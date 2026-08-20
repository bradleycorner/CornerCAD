#!/usr/bin/env python3
"""
Insert the "---" separator into Square descriptions that don't have one.

The WPCode snippet on cornercad.com splits a product description on a literal
<p>---</p>: everything before becomes the WooCommerce excerpt, everything after
becomes the Description tab. Without a separator the same text renders in both
places, which reads as a bug.

These descriptions are all a SINGLE paragraph ending in standard boilerplate --
bulb safety, kit requirements, "3D printed to order...", "Designed by h3li0."
This splits before the EARLIEST boilerplate sentence, so the distinctive product
copy becomes the summary and the standard tail becomes the tab.

  * Reads your access token ONLY from the SQUARE_ACCESS_TOKEN environment variable.
  * DRY RUN BY DEFAULT. Nothing is written without --apply.
  * Skips anything it cannot split cleanly and reports it rather than guessing.
  * --min-summary guards against pointless splits: a one-line description yields a
    seven-word summary and a boilerplate-only tab, which is not an improvement.

Usage
-----
    export SSL_CERT_FILE=$(python3 -c "import certifi;print(certifi.where())")
    export SQUARE_ACCESS_TOKEN='...'
    python3 scripts/square_insert_separators.py                    # dry run, all
    python3 scripts/square_insert_separators.py --min-summary 120  # only substantial ones
    python3 scripts/square_insert_separators.py --apply
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
CORNERCAD_LOCATION = "LS4SZ98SBX4F6"

# Earliest match wins. Ordered loosely by how late they normally appear, but the
# code takes the minimum index found, so order here is documentation, not logic.
BOILERPLATE = [
    r"Use low-heat LED bulbs only",
    r"Strict requirement for low-heat LED",
    r"Supplied as printed parts only",
    r"Package Contents:",
    r"Requires (?:a|an) (?:standard )?E\d+",
    r"Lighting Hardware:",
    r"3D printed to order",
    r"Printed to order",
    r"Designed by h3li0",
    r"Crafted with precision, sustainable materials",
]


def die(msg):
    print("ERROR: %s" % msg, file=sys.stderr)
    sys.exit(1)


def api(token, method, path, payload=None):
    data = json.dumps(payload).encode() if payload is not None else None
    req = urllib.request.Request(SQUARE_API + path, data=data, method=method)
    req.add_header("Authorization", "Bearer %s" % token)
    req.add_header("Square-Version", SQUARE_VERSION)
    req.add_header("Content-Type", "application/json")
    try:
        with urllib.request.urlopen(req, timeout=60) as r:
            return json.loads(r.read().decode())
    except urllib.error.HTTPError as e:
        die("Square API %s %s -> HTTP %s\n%s"
            % (method, path, e.code, e.read().decode(errors="replace")[:600]))


def list_items(token):
    out, cursor = [], None
    while True:
        path = "/catalog/list?types=ITEM" + ("&cursor=%s" % cursor if cursor else "")
        page = api(token, "GET", path)
        out.extend(page.get("objects", []) or [])
        cursor = page.get("cursor")
        if not cursor:
            break
    return out


def at_location(obj, loc):
    if obj.get("present_at_all_locations"):
        return loc not in (obj.get("absent_at_location_ids") or [])
    return loc in (obj.get("present_at_location_ids") or [])


def split_description(html):
    """-> (summary_html, details_html, reason_if_skipped)"""
    if "---" in html:
        return None, None, "already has a separator"

    # These are all a single <p>...</p>. Anything else has structure worth
    # preserving, and a blind split could land mid-list. Skip and report.
    body = html.strip()
    m = re.fullmatch(r"<p>(.*?)</p>", body, re.S | re.I)
    if not m:
        return None, None, "not a single paragraph -- needs a human"
    inner = m.group(1)
    if re.search(r"<(p|ul|ol|li|h[1-6]|br)\b", inner, re.I):
        return None, None, "contains block markup -- needs a human"

    idx = None
    for pat in BOILERPLATE:
        hit = re.search(pat, inner, re.I)
        if hit and (idx is None or hit.start() < idx):
            idx = hit.start()
    if idx is None:
        return None, None, "no boilerplate sentence found"

    summary = inner[:idx].strip()
    details = inner[idx:].strip()
    if not summary or not details:
        return None, None, "split would leave one half empty"
    return summary, details, None


def main():
    ap = argparse.ArgumentParser(description=__doc__,
                                 formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("--apply", action="store_true", help="write to Square (default: dry run)")
    ap.add_argument("--min-summary", type=int, default=0,
                    help="skip items whose summary half would be shorter than N chars")
    ap.add_argument("--location", default=CORNERCAD_LOCATION)
    ap.add_argument("--limit", type=int, help="process at most N items")
    args = ap.parse_args()

    token = os.environ.get("SQUARE_ACCESS_TOKEN")
    if not token:
        die("SQUARE_ACCESS_TOKEN is not set.")

    print("Loading catalog...")
    items = [o for o in list_items(token) if at_location(o, args.location)]
    print("  %d items at %s\n" % (len(items), args.location))

    todo, skipped, tooshort = [], [], []
    for obj in items:
        d = obj.get("item_data") or {}
        html = (d.get("description_html") or "").strip()
        name = d.get("name", "?")
        if not html:
            continue
        summary, details, reason = split_description(html)
        if reason:
            if reason != "already has a separator":
                skipped.append((name, reason))
            continue
        if len(summary) < args.min_summary:
            tooshort.append((name, len(summary)))
            continue
        todo.append((obj, name, summary, details))

    if args.limit:
        todo = todo[:args.limit]

    print("%d item(s) to split" % len(todo))
    if tooshort:
        print("%d skipped as too short (--min-summary %d)" % (len(tooshort), args.min_summary))
    if skipped:
        print("%d skipped, needing a human:" % len(skipped))
        for n, r in skipped[:15]:
            print("   %-34s %s" % (n[:34], r))
    print()

    if not args.apply:
        for _, name, s, det in todo[:12]:
            print("%s" % name)
            print("   SUMMARY (%3d): %s" % (len(s), s[:120]))
            print("   TAB     (%3d): %s\n" % (len(det), det[:120]))
        if len(todo) > 12:
            print("... and %d more\n" % (len(todo) - 12))
        print("Dry run. Re-run with --apply to write to Square.")
        return

    done = 0
    for obj, name, summary, details in todo:
        new_html = "<p>%s</p><p>---</p><p>%s</p>" % (summary, details)
        payload = json.loads(json.dumps(obj))
        payload["item_data"]["description_html"] = new_html
        res = api(token, "POST", "/catalog/object", {
            "idempotency_key": hashlib.sha1(
                (payload["id"] + hashlib.sha1(new_html.encode()).hexdigest()).encode()
            ).hexdigest(),
            "object": payload,
        })
        done += 1
        print("  %3d/%d  %s (v%s)" % (done, len(todo), name[:40],
                                      (res.get("catalog_object") or {}).get("version")))
    print("\nDone: %d updated. Woo picks these up on the next Square sync." % done)


if __name__ == "__main__":
    main()
