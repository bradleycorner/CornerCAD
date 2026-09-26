#!/usr/bin/env python3
"""
square_seed_sandbox_uclc.py
Seed the Square SANDBOX catalog for Lisa's UCLC WooCommerce site
(uniquecreationsbylisac.store) with a small set of REAL representative
items, so the Woo <-> Square sync can be proven there before it's ever
pointed at her live catalog.

Same mechanism as square_seed_sandbox.py (CornerCAD's own seeder), but the
sample below is pulled from Lisa's ACTUAL live catalog (real names/SKUs/
prices, read via the Square MCP connector against the shared production
merchant ML0RSAXT9HH0B, location L71MXVF5YWZE4) rather than invented
placeholder data -- same "real items in a disposable sandbox" approach
CornerCAD's own seeder used for its Vases/Planters.

Lisa's sandbox app is a SEPARATE, dedicated Square dev app -- NOT shared
with CornerCAD's or the Miata club's. Verified 2026-08-30 by calling
/v2/locations with the stored sandbox token: resolves to its own isolated
test merchant (MLQMEARPGFFC6, "Default Test Account"), distinct from
CornerCAD's sandbox merchant despite an identical-looking default
location id (L4KK3G9JY6RDY) -- that id is just Square's fixed default
seed value for every fresh sandbox account, not a shared/leaked one.

SAFETY / DESIGN -- identical to square_seed_sandbox.py:
  * Access token read ONLY from SQUARE_ACCESS_TOKEN. Never printed/written.
  * Default --env is sandbox. A production token against the sandbox base
    URL fails with 401 -- nothing is written on a mismatch.
  * Seeding production is blocked behind --env production AND
    --yes-really-seed-production. Don't.
  * --dry-run shows exactly what would be created and writes nothing.

QUICK START
  export SQUARE_ACCESS_TOKEN='<Lisa's SANDBOX access token>'
  python3 square_seed_sandbox_uclc.py --list-locations
  python3 square_seed_sandbox_uclc.py --dry-run
  python3 square_seed_sandbox_uclc.py --location L4KK3G9JY6RDY
"""

import argparse
import json
import os
import sys
import uuid
from pathlib import Path

try:
    import requests
except ImportError:
    sys.exit("Missing dependency. Run:  pip install requests")

BASE = {
    "sandbox": "https://connect.squareupsandbox.com",
    "production": "https://connect.squareup.com",
}
SQUARE_VERSION = os.environ.get("SQUARE_VERSION", "2025-04-16")

# ---------------------------------------------------------------------------
# 12 REAL items pulled from Lisa's live catalog (uniquecreationsbylisac,
# location L71MXVF5YWZE4), across 9 of her real categories. Prices in cents,
# matching her actual live prices at the time this was written (2026-08-30).
SAMPLE = [
    # name,                                  sku,                     price, category,     description
    ("Silver Snowflake Earring",             "UCL-EAR-0021-SILI",     3500, "Earrings",
     "Handcrafted silver snowflake earrings. Sandbox sample."),
    ("Shaggy Loops Earring",                 "UCL-EAR-0018-SIMU",     1000, "Earrings",
     "Chainmail shaggy-loops earrings. Sandbox sample."),
    ("Colorful Glow in the Dark Arc Pendants","UCL-PEN-0001-PIWH",    3000, "Pendants",
     "Glow-in-the-dark arc pendant. Sandbox sample."),
    ("2n2 Bracelet",                         "UCL-BRA-0001",          3000, "Bracelets",
     "Chainmail 2-in-2 weave bracelet. Sandbox sample."),
    ("Full Persian Bracelet",                "UCL-BRA-0009-6IN",      3000, "Bracelets",
     "Full Persian chainmail weave bracelet, 6in. Sandbox sample."),
    ("Gemstone Heart",                       "UCL-GEM-0002-SMAL",     1000, "Gemstones",
     "Small polished gemstone heart. Sandbox sample."),
    ("Angel Necklaces",                      "UCL-NEC-0001",           500, "Necklaces",
     "Angel charm necklace. Sandbox sample."),
    ("GSG - Great Southern Gathering",       "UCL-CHM-0002",          3000, "Charms",
     "Great Southern Gathering commemorative charm. Sandbox sample."),
    ("Helm",                                 "UCL-CHN-0002",          3000, "Chainmail",
     "Helm-weave chainmail piece. Sandbox sample."),
    ("Mobius Fidget",                        "UCL-FDG-0001-CUST",     2000, "Fidgets",
     "Chainmail mobius fidget. Sandbox sample."),
    ("Stainless Steel Rings",                "UCL-RNG-0002",           500, "Rings",
     "Stainless steel chainmail ring. Sandbox sample."),
    ("Arc Keychain",                         "UCL-KEY-0001",          2000, "Keychains",
     "Chainmail arc keychain. Sandbox sample."),
]

# ---------------------------------------------------------------------------

def log(msg):
    print(msg, flush=True)


def die(msg, code=1):
    print("ERROR: " + msg, file=sys.stderr, flush=True)
    sys.exit(code)


def session(token):
    s = requests.Session()
    s.headers.update({
        "Authorization": f"Bearer {token}",
        "Square-Version": SQUARE_VERSION,
        "Accept": "application/json",
        "Content-Type": "application/json",
    })
    return s


def check(resp, what):
    if resp.status_code == 401:
        die(f"{what}: HTTP 401 Unauthorized. The token does not match --env. "
            f"If you're seeding sandbox, export Lisa's SANDBOX access token "
            f"(not the production one). Nothing was written.")
    if resp.status_code >= 400:
        die(f"{what}: HTTP {resp.status_code}: {resp.text[:600]}")
    return resp


def list_locations(sess, base):
    resp = check(sess.get(f"{base}/v2/locations", timeout=60), "list locations")
    return resp.json().get("locations", [])


def read_catalog(sess, base):
    """Return (skus_present:set, categories_by_name:{name:id})."""
    skus, cats = set(), {}
    cursor = None
    while True:
        params = {"types": "ITEM,CATEGORY"}
        if cursor:
            params["cursor"] = cursor
        resp = check(sess.get(f"{base}/v2/catalog/list", params=params, timeout=60),
                     "list catalog")
        data = resp.json()
        for obj in data.get("objects", []):
            if obj.get("type") == "CATEGORY":
                cats[obj["category_data"]["name"]] = obj["id"]
            elif obj.get("type") == "ITEM":
                for v in obj.get("item_data", {}).get("variations", []):
                    sku = v.get("item_variation_data", {}).get("sku")
                    if sku:
                        skus.add(sku)
        cursor = data.get("cursor")
        if not cursor:
            break
    return skus, cats


def build_objects(items, location_id, existing_cats):
    objects = []
    cat_ref = dict(existing_cats)
    needed = {c for _, _, _, c, _ in items}
    for name in sorted(needed):
        if name in cat_ref:
            continue
        temp = f"#cat_{name.replace(' ', '_')}"
        cat_ref[name] = temp
        objects.append({
            "type": "CATEGORY",
            "id": temp,
            "category_data": {"name": name},
        })
    for name, sku, price, cat, desc in items:
        cid = cat_ref[cat]
        objects.append({
            "type": "ITEM",
            "id": f"#item_{sku}",
            "present_at_all_locations": False,
            "present_at_location_ids": [location_id],
            "item_data": {
                "name": name,
                "description": desc,
                "product_type": "REGULAR",
                "categories": [{"id": cid}],
                "reporting_category": {"id": cid},
                "variations": [{
                    "type": "ITEM_VARIATION",
                    "id": f"#var_{sku}",
                    "present_at_all_locations": False,
                    "present_at_location_ids": [location_id],
                    "item_variation_data": {
                        "item_id": f"#item_{sku}",
                        "name": "Regular",
                        "sku": sku,
                        "pricing_type": "FIXED_PRICING",
                        "price_money": {"amount": price, "currency": "USD"},
                        "location_overrides": [{
                            "location_id": location_id,
                            "track_inventory": False,
                        }],
                    },
                }],
            },
        })
    return objects


# ---------------------------------------------------------------------------

def main():
    ap = argparse.ArgumentParser(
        description="Seed the Square SANDBOX catalog with real-item samples "
                    "for Lisa's UCLC WooCommerce sync test.",
        formatter_class=argparse.ArgumentDefaultsHelpFormatter)
    ap.add_argument("--env", choices=list(BASE), default="sandbox")
    ap.add_argument("--location", default=None,
                    help="Target location id (Lisa's sandbox_location_id, "
                         "e.g. L4KK3G9JY6RDY)")
    ap.add_argument("--limit", type=int, default=0)
    ap.add_argument("--dry-run", action="store_true")
    ap.add_argument("--list-locations", action="store_true")
    ap.add_argument("--state", default="seed_state_uclc.json")
    ap.add_argument("--yes-really-seed-production", action="store_true",
                    help=argparse.SUPPRESS)
    args = ap.parse_args()

    if args.env == "production" and not args.yes_really_seed_production:
        die("Refusing to seed PRODUCTION. This tool is for sandbox.")

    token = os.environ.get("SQUARE_ACCESS_TOKEN")
    if not token:
        die("SQUARE_ACCESS_TOKEN is not set. Export Lisa's SANDBOX token first.")

    base = BASE[args.env]
    sess = session(token)

    locs = list_locations(sess, base)
    if args.list_locations:
        log(f"Environment: {args.env}  ({base})")
        log(f"{len(locs)} location(s):")
        for l in locs:
            log(f"  {l['id']:16s} {l.get('status','?'):9s} "
                f"{l.get('name','')}  [{l.get('business_name','')}]")
        return

    if args.location:
        location_id = args.location
        if location_id not in {l["id"] for l in locs}:
            die(f"location {location_id} is not visible to this token.")
    elif len(locs) == 1:
        location_id = locs[0]["id"]
    else:
        ids = ", ".join(l["id"] for l in locs) or "(none)"
        die(f"token sees {len(locs)} locations ({ids}). Pass --location <id>.")

    items = SAMPLE[:args.limit] if args.limit else SAMPLE

    skus_present, cats_present = read_catalog(sess, base)
    todo = [it for it in items if it[1] not in skus_present]
    skipped = [it for it in items if it[1] in skus_present]

    log(f"\nEnvironment : {args.env}  ({base})")
    log(f"Location    : {location_id}")
    log(f"Sample      : {len(items)} item(s)  |  to create: {len(todo)}  "
        f"already present: {len(skipped)}")
    log(f"Mode        : {'DRY RUN (no writes)' if args.dry_run else 'LIVE UPLOAD to sandbox'}\n")

    for name, sku, price, cat, _ in skipped:
        log(f"  skip  {sku:20s} {name}  (SKU already in catalog)")
    for name, sku, price, cat, _ in todo:
        log(f"  new   {sku:20s} {name:38s} ${price/100:5.2f}  [{cat}]")

    if not todo:
        log("\nNothing to create -- every sample SKU already exists. Done.")
        return
    if args.dry_run:
        log("\n(dry run -- nothing was written to Square)")
        return

    objects = build_objects(todo, location_id, cats_present)
    body = {"idempotency_key": str(uuid.uuid4()),
            "batches": [{"objects": objects}]}
    resp = check(sess.post(f"{base}/v2/catalog/batch-upsert",
                           data=json.dumps(body), timeout=120),
                 "batch-upsert catalog")
    result = resp.json()

    mapping = {m.get("client_object_id"): m.get("object_id")
               for m in result.get("id_mappings", [])}
    created = {}
    for name, sku, price, cat, _ in todo:
        created[sku] = mapping.get(f"#item_{sku}", "")

    state_path = Path(args.state)
    prior = json.loads(state_path.read_text()) if state_path.exists() else {}
    prior.update(created)
    state_path.write_text(json.dumps(prior, indent=1))

    log(f"\nCreated {len(created)} item(s) in sandbox:")
    for sku, oid in created.items():
        log(f"  {sku:20s} -> {oid}")
    log(f"\nState saved to {state_path}.")


if __name__ == "__main__":
    main()
