#!/usr/bin/env python3
"""
Audit the Square catalog: variations, modifier sets, SKUs, and descriptions.

READ ONLY. This script never writes to Square. It exists to turn "go through all 168
products by hand" into "sort a spreadsheet."

  * Reads your access token ONLY from the SQUARE_ACCESS_TOKEN environment variable.
  * Scopes to the CornerCAD location by default (LS4SZ98SBX4F6). Location scoping --
    not the CAD- SKU prefix -- is the real guard: a SKU-less item of Lisa's once bled
    into a staging import because the prefix filter had nothing to match on.
  * Writes a CSV for sorting, and prints a summary of the things that need action.

Usage
-----
    export SQUARE_ACCESS_TOKEN='your_production_access_token'
    python3 scripts/square_audit_catalog.py
    python3 scripts/square_audit_catalog.py --csv /tmp/catalog-audit.csv
    python3 scripts/square_audit_catalog.py --all-locations

Why the flags matter
--------------------
NO_SKU        The WooCommerce Square plugin gates on has_sku(): no SKU means the product
              silently never syncs. This is the highest-priority flag.
MULTI_OPTION  The plugin also refuses products with more than one variation dimension
              (has_multiple_variation_attributes). Items using 2+ Square item options
              cannot sync -- the second dimension must be flattened into the label.
NO_DESC       No description at all.
NO_SPLIT      Has a description but no "---" separator, so the storefront shows the same
              text as both the excerpt and the Description tab.
NON_CAD_SKU   Item sits at the CornerCAD location but its SKU is not CAD-. Worth a look
              before any import -- this is the bleed case.
"""

import argparse
import collections
import csv
import json
import os
import sys
import urllib.error
import urllib.request

SQUARE_API = "https://connect.squareup.com/v2"
SQUARE_VERSION = os.environ.get("SQUARE_VERSION", "2025-04-16")
CORNERCAD_LOCATION = "LS4SZ98SBX4F6"
DEFAULT_CSV = os.path.join(os.path.dirname(os.path.abspath(__file__)),
                           "catalog_audit.csv")


def die(msg):
    print("ERROR: %s" % msg, file=sys.stderr)
    sys.exit(1)


def api(token, path):
    req = urllib.request.Request(SQUARE_API + path, method="GET")
    req.add_header("Authorization", "Bearer %s" % token)
    req.add_header("Square-Version", SQUARE_VERSION)
    req.add_header("Content-Type", "application/json")
    try:
        with urllib.request.urlopen(req, timeout=60) as r:
            return json.loads(r.read().decode())
    except urllib.error.HTTPError as e:
        body = e.read().decode(errors="replace")
        die("Square API GET %s -> HTTP %s\n%s" % (path, e.code, body[:800]))


def list_objects(token, types):
    """Page through /catalog/list for the given comma-separated types."""
    out, cursor = [], None
    while True:
        path = "/catalog/list?types=%s" % types + ("&cursor=%s" % cursor if cursor else "")
        page = api(token, path)
        out.extend(page.get("objects", []) or [])
        cursor = page.get("cursor")
        if not cursor:
            break
    return out


def at_location(obj, location_id):
    if obj.get("present_at_all_locations"):
        # absent_at_location_ids can carve an exception out of "all locations"
        return location_id not in (obj.get("absent_at_location_ids") or [])
    return location_id in (obj.get("present_at_location_ids") or [])


def money(v):
    amt = (v.get("item_variation_data") or {}).get("price_money") or {}
    cents = amt.get("amount")
    return "" if cents is None else "%.2f" % (cents / 100.0)


def main():
    ap = argparse.ArgumentParser(description=__doc__,
                                 formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("--csv", default=DEFAULT_CSV, help="output CSV path")
    ap.add_argument("--location", default=CORNERCAD_LOCATION,
                    help="location to scope to (default: the CornerCAD location)")
    ap.add_argument("--all-locations", action="store_true",
                    help="do not scope by location (includes Lisa's items)")
    args = ap.parse_args()

    token = os.environ.get("SQUARE_ACCESS_TOKEN")
    if not token:
        die("SQUARE_ACCESS_TOKEN is not set. Export your Square access token first.")

    print("Loading catalog...")
    items = list_objects(token, "ITEM")
    mod_lists = list_objects(token, "MODIFIER_LIST")
    categories = list_objects(token, "CATEGORY")
    options = list_objects(token, "ITEM_OPTION")
    print("  %d items, %d modifier lists, %d categories, %d item options"
          % (len(items), len(mod_lists), len(categories), len(options)))

    mod_name = {o["id"]: (o.get("modifier_list_data") or {}).get("name", "?")
                for o in mod_lists}
    cat_name = {o["id"]: (o.get("category_data") or {}).get("name", "?")
                for o in categories}
    opt_name = {o["id"]: (o.get("item_option_data") or {}).get("name", "?")
                for o in options}

    rows, skipped = [], 0
    for obj in items:
        if not args.all_locations and not at_location(obj, args.location):
            skipped += 1
            continue

        d = obj.get("item_data") or {}
        variations = d.get("variations") or []

        # category: prefer reporting_category, fall back to the first assigned category
        cat_id = (d.get("reporting_category") or {}).get("id")
        if not cat_id:
            cats = d.get("categories") or []
            cat_id = cats[0].get("id") if cats else None

        mods = [mod_name.get((m.get("modifier_list_id") or ""), "?")
                for m in (d.get("modifier_list_info") or [])]

        # Which item options (variation dimensions) this item uses.
        used_options = []
        for v in variations:
            for ov in ((v.get("item_variation_data") or {}).get("item_option_values") or []):
                nm = opt_name.get(ov.get("item_option_id"), "?")
                if nm not in used_options:
                    used_options.append(nm)

        skus = [(v.get("item_variation_data") or {}).get("sku") or "" for v in variations]
        names = [(v.get("item_variation_data") or {}).get("name") or "" for v in variations]
        prices = [money(v) for v in variations]

        flags = []
        if any(not s for s in skus):
            flags.append("NO_SKU")
        if len(used_options) > 1:
            flags.append("MULTI_OPTION")
        desc = d.get("description_html") or d.get("description") or ""
        if not desc.strip():
            flags.append("NO_DESC")
        elif "---" not in desc:
            flags.append("NO_SPLIT")
        if skus and not any(s.startswith("CAD-") for s in skus if s):
            flags.append("NON_CAD_SKU")

        rows.append({
            "name": d.get("name", ""),
            "category": cat_name.get(cat_id, ""),
            "variation_count": len(variations),
            "variation_names": " | ".join(names),
            "skus": " | ".join(skus),
            "prices": " | ".join(prices),
            "modifier_sets": " | ".join(mods),
            "item_options": " | ".join(used_options),
            "desc_chars": len(desc),
            "has_separator": "yes" if "---" in desc else "no",
            "flags": " ".join(flags),
            "item_id": obj.get("id", ""),
        })

    rows.sort(key=lambda r: (r["category"], r["name"]))

    cols = ["name", "category", "variation_count", "variation_names", "skus", "prices",
            "modifier_sets", "item_options", "desc_chars", "has_separator", "flags",
            "item_id"]
    with open(args.csv, "w", newline="", encoding="utf-8") as fh:
        w = csv.DictWriter(fh, fieldnames=cols)
        w.writeheader()
        w.writerows(rows)

    # ------------------------------------------------------------- summary --
    print("\n%d items in scope (%d skipped: not at %s)\n"
          % (len(rows), skipped, args.location))

    counts = collections.Counter()
    for r in rows:
        for f in r["flags"].split():
            counts[f] += 1
    if counts:
        print("Flags:")
        for f, n in counts.most_common():
            print("  %-14s %d" % (f, n))
    else:
        print("No flags raised.")

    print("\nVariation counts:")
    for n, c in sorted(collections.Counter(r["variation_count"] for r in rows).items()):
        print("  %d variation(s): %d item(s)" % (n, c))

    print("\nModifier sets in use:")
    ms = collections.Counter()
    for r in rows:
        for m in [x for x in r["modifier_sets"].split(" | ") if x]:
            ms[m] += 1
    for m, c in ms.most_common():
        print("  %-22s %d item(s)" % (m, c))

    multi = [r for r in rows if r["variation_count"] > 1]
    if multi:
        print("\nItems with more than one variation (%d) -- the naming convention "
              "to standardize:" % len(multi))
        for r in multi[:25]:
            print("  %-30s %s" % (r["name"][:30], r["variation_names"][:90]))
        if len(multi) > 25:
            print("  ... and %d more (see the CSV)" % (len(multi) - 25))

    blockers = [r for r in rows if "NO_SKU" in r["flags"] or "MULTI_OPTION" in r["flags"]]
    if blockers:
        print("\nSYNC BLOCKERS (%d) -- these silently never reach WooCommerce:"
              % len(blockers))
        for r in blockers:
            print("  %-30s %-14s %s" % (r["name"][:30], r["flags"], r["skus"][:60]))

    print("\nCSV: %s" % args.csv)


if __name__ == "__main__":
    main()
