#!/usr/bin/env python3
"""
Push product descriptions from a Markdown source file into the Square catalog.

Square is the system of record for CornerCAD product copy: WooCommerce pulls it on the
next sync, so descriptions must be written HERE, not in Woo.

  * Reads your access token ONLY from the SQUARE_ACCESS_TOKEN environment variable.
    Never pass it as an argument (it would land in your shell history).
  * Refuses to run while the source file still contains a "CONFIRM" tag -- those mark
    claims that were inferred from product photos rather than verified.
  * DRY RUN BY DEFAULT. Nothing is written without --apply.
  * Idempotent: records the pushed content hash per SKU in a state file and skips
    unchanged entries on re-run.

Usage
-----
    export SQUARE_ACCESS_TOKEN='your_production_access_token'
    python3 scripts/square_push_descriptions.py content/square-descriptions-A.md
    python3 scripts/square_push_descriptions.py content/square-descriptions-A.md --apply

    # single product
    python3 scripts/square_push_descriptions.py content/square-descriptions-A.md --only CAD-VAS-0004

Source format (per product):

    ## <Product Name> -- <SKU>
    ### Summary
    ...prose...
    ### Details
    ...prose, "- " lines become a bullet list...

The two halves are joined with the literal <p>---</p> separator that the WPCode snippet
splits on: summary becomes the Woo excerpt, details become the Description tab.
"""

import argparse
import hashlib
import json
import os
import re
import sys
import urllib.error
import urllib.request

SQUARE_API = "https://connect.squareup.com/v2"
SQUARE_VERSION = os.environ.get("SQUARE_VERSION", "2025-04-16")
STATE_PATH = os.path.join(os.path.dirname(os.path.abspath(__file__)),
                          "description_push_state.json")
CONFIRM_TAG = "CONFIRM"


def die(msg):
    print("ERROR: %s" % msg, file=sys.stderr)
    sys.exit(1)


# ---------------------------------------------------------------- markdown --

def inline_html(text):
    """**bold** -> <strong>, and escape nothing else (source is trusted, hand-written)."""
    return re.sub(r"\*\*(.+?)\*\*", r"<strong>\1</strong>", text.strip())


def blocks_to_html(chunk):
    """Blank-line-separated blocks -> <p>; runs of '- ' lines -> <ul><li>."""
    out = []
    for block in re.split(r"\n\s*\n", chunk.strip()):
        # A block of nothing but hyphens is the Markdown rule that separates products
        # in the source file, not content. Emitting it produced a SECOND <p>---</p> at
        # the end of every description except the last one, which split the copy in the
        # wrong place. Never treat a bare rule as prose.
        if re.fullmatch(r"-{3,}", block.strip()):
            continue
        lines = [l.rstrip() for l in block.strip().splitlines() if l.strip()]
        if not lines:
            continue
        if all(l.lstrip().startswith("- ") for l in lines):
            items = "".join("<li>%s</li>" % inline_html(l.lstrip()[2:]) for l in lines)
            out.append("<ul>%s</ul>" % items)
        else:
            out.append("<p>%s</p>" % inline_html(" ".join(lines)))
    return "".join(out)


def parse_source(path):
    """-> [ {name, sku, summary_html, details_html, html} ]"""
    with open(path, encoding="utf-8") as fh:
        text = fh.read()

    # Only bullet lines count as real tags -- the file's own instructions mention the tag
    # by name, and scanning the whole document would block the push permanently.
    hits = [l.strip() for l in text.splitlines()
            if l.strip().startswith("-") and CONFIRM_TAG in l]
    if hits:
        die("%s still contains %d %s tag(s) -- resolve or delete them before pushing:\n  %s"
            % (path, len(hits), CONFIRM_TAG, "\n  ".join(h[:110] for h in hits)))

    products = []
    # "## Name -- SKU"  (accepts -, --, or an em dash as the separator)
    for m in re.finditer(r"^##\s+(?P<name>.+?)\s+(?:—|--|-)\s+(?P<sku>[A-Z0-9-]+)\s*$",
                         text, re.MULTILINE):
        start = m.end()
        nxt = re.search(r"^##\s+", text[start:], re.MULTILINE)
        body = text[start:start + nxt.start()] if nxt else text[start:]

        def section(label):
            s = re.search(r"^###\s+%s\s*$" % label, body, re.MULTILINE)
            if not s:
                return None
            after = body[s.end():]
            e = re.search(r"^###\s+", after, re.MULTILINE)
            return (after[:e.start()] if e else after).strip()

        summary, details = section("Summary"), section("Details")
        if not summary or not details:
            die("%s (%s): missing a ### Summary or ### Details section"
                % (m.group("name"), m.group("sku")))

        s_html = blocks_to_html(summary)
        d_html = blocks_to_html(details)
        products.append({
            "name": m.group("name").strip(),
            "sku": m.group("sku").strip(),
            "summary_html": s_html,
            "details_html": d_html,
            # No newlines between tags -- matches the shape Square actually stores.
            "html": s_html + "<p>---</p>" + d_html,
        })
    return products


# ------------------------------------------------------------------ square --

def api(token, method, path, payload=None):
    url = SQUARE_API + path
    data = json.dumps(payload).encode() if payload is not None else None
    req = urllib.request.Request(url, data=data, method=method)
    req.add_header("Authorization", "Bearer %s" % token)
    req.add_header("Square-Version", SQUARE_VERSION)
    req.add_header("Content-Type", "application/json")
    try:
        with urllib.request.urlopen(req, timeout=60) as r:
            return json.loads(r.read().decode())
    except urllib.error.HTTPError as e:
        body = e.read().decode(errors="replace")
        die("Square API %s %s -> HTTP %s\n%s" % (method, path, e.code, body[:800]))


def load_catalog(token):
    """SKU (from item variations) -> parent ITEM object."""
    by_sku, cursor, items = {}, None, 0
    while True:
        path = "/catalog/list?types=ITEM" + ("&cursor=%s" % cursor if cursor else "")
        page = api(token, "GET", path)
        for obj in page.get("objects", []):
            items += 1
            for var in obj.get("item_data", {}).get("variations", []) or []:
                sku = (var.get("item_variation_data") or {}).get("sku")
                if sku:
                    by_sku[sku] = obj
        cursor = page.get("cursor")
        if not cursor:
            break
    print("  catalog: %d items, %d SKUs" % (items, len(by_sku)))
    return by_sku


def push(token, item, html):
    """Re-upsert the full ITEM with description_html replaced (Square upsert is a replace)."""
    obj = json.loads(json.dumps(item))          # deep copy
    obj["item_data"]["description_html"] = html
    # description_plaintext is read-only/derived; the legacy `description` field is left
    # alone so Square keeps deriving it rather than us shipping two sources of truth.
    body = {
        "idempotency_key": hashlib.sha1(
            (obj["id"] + hashlib.sha1(html.encode()).hexdigest()).encode()
        ).hexdigest(),
        "object": obj,
    }
    return api(token, "POST", "/catalog/object", body)


# -------------------------------------------------------------------- main --

def main():
    ap = argparse.ArgumentParser(description=__doc__,
                                 formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("source", help="Markdown source file")
    ap.add_argument("--apply", action="store_true",
                    help="actually write to Square (default is a dry run)")
    ap.add_argument("--only", metavar="SKU", help="push a single SKU")
    ap.add_argument("--force", action="store_true",
                    help="push even if the state file says the content is unchanged")
    args = ap.parse_args()

    products = parse_source(args.source)
    if args.only:
        products = [p for p in products if p["sku"] == args.only]
        if not products:
            die("SKU %s not found in %s" % (args.only, args.source))
    print("Parsed %d product(s) from %s\n" % (len(products), args.source))

    state = {}
    if os.path.exists(STATE_PATH):
        with open(STATE_PATH, encoding="utf-8") as fh:
            state = json.load(fh)

    if not args.apply:
        for p in products:
            h = hashlib.sha1(p["html"].encode()).hexdigest()[:10]
            mark = "unchanged" if state.get(p["sku"], {}).get("hash") == h else "WOULD PUSH"
            print("%-14s %-24s [%s]  %d chars" % (p["sku"], p["name"], mark, len(p["html"])))
            print("   summary: %s" % p["summary_html"][:150])
            print("   details: %s\n" % p["details_html"][:150])
        print("Dry run. Re-run with --apply to write to Square.")
        return

    token = os.environ.get("SQUARE_ACCESS_TOKEN")
    if not token:
        die("SQUARE_ACCESS_TOKEN is not set. Export your Square access token first.")

    print("Loading Square catalog...")
    by_sku = load_catalog(token)

    missing = [p["sku"] for p in products if p["sku"] not in by_sku]
    if missing:
        die("SKU(s) not found in the Square catalog: %s" % ", ".join(missing))

    pushed = skipped = 0
    for p in products:
        h = hashlib.sha1(p["html"].encode()).hexdigest()[:10]
        if not args.force and state.get(p["sku"], {}).get("hash") == h:
            print("  skip   %-14s %s (unchanged)" % (p["sku"], p["name"]))
            skipped += 1
            continue
        res = push(token, by_sku[p["sku"]], p["html"])
        ver = (res.get("catalog_object") or {}).get("version")
        state[p["sku"]] = {"hash": h, "name": p["name"], "version": ver}
        with open(STATE_PATH, "w", encoding="utf-8") as fh:
            json.dump(state, fh, indent=2, sort_keys=True)
        print("  pushed %-14s %s (version %s)" % (p["sku"], p["name"], ver))
        pushed += 1

    print("\nDone: %d pushed, %d skipped. State: %s" % (pushed, skipped, STATE_PATH))
    print("Woo picks these up on the next Square sync (or run a manual sync per the "
          "CLI recipe in CLAUDE.md).")


if __name__ == "__main__":
    main()
