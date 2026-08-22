#!/usr/bin/env python3
"""
square_seed_sandbox.py
Seed the Square SANDBOX catalog with a small set of made-to-order sample items,
so the WooCommerce <-> Square sync can be proven on staging before it is ever
pointed at the live catalog.

WHY THIS EXISTS
  The live catalog (168 real CAD- products, plus Lisa's UCL- items) must never be
  used as a sync guinea pig. Square's Sandbox is a fully isolated test account: its
  own access token, its own catalog and location, fake money, nothing shared with
  production. This script creates ~12 representative sample items in SANDBOX ONLY,
  so staging Woo (which is configured for Sandbox) has something real to import.

WHAT IT DOES
  1. Reads the sandbox catalog once to learn: which locations the token can see,
     which SKUs already exist, and which of the needed categories already exist.
  2. Creates any missing categories and any missing sample items in a single
     batch-upsert. Existing SKUs are skipped, so re-runs never duplicate.
  3. Each item is made-to-order: one variation, a CAD- SKU, fixed USD price, and
     track_inventory = false at the target location (so it never reads out of stock).
  4. Records created object IDs to seed_state.json for your reference.

SAFETY / DESIGN
  * The access token is read ONLY from the SQUARE_ACCESS_TOKEN environment variable.
    It is never printed and never written to disk.
  * Default --env is sandbox, whose base URL is connect.squareupsandbox.com. A
    PRODUCTION token used against that base URL fails with HTTP 401 and NOTHING is
    written -- the token and the environment must match. So an accidentally-exported
    production token cannot silently seed the live catalog; you just get a 401.
  * Seeding production is blocked behind --env production AND --yes-really-seed-production.
    Don't. This tool is for sandbox.
  * --dry-run shows exactly what would be created and writes nothing.

REQUIREMENTS
  Python 3.8+ and requests:   pip install requests

QUICK START
  export SQUARE_ACCESS_TOKEN='<your SANDBOX access token>'

  python3 square_seed_sandbox.py --list-locations   # show sandbox locations, exit
  python3 square_seed_sandbox.py --dry-run          # preview the 12 items, no writes
  python3 square_seed_sandbox.py                     # create them in sandbox
  python3 square_seed_sandbox.py --limit 4           # just the first 4 (fast smoke test)

  # If sandbox has more than one location, pass the one staging Woo is set to
  # (its sandbox_location_id, e.g. L4KK3G9JY6RDY):
  python3 square_seed_sandbox.py --location L4KK3G9JY6RDY
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
# The sample. ~12 made-to-order items across 6 categories. Prices in cents (USD).
# Vases/Planters mirror real live items; the rest are representative stand-ins --
# this is a disposable sandbox whose only job is to prove the sync mechanism and
# category mapping, so exact fidelity to live is not required.
CATEGORIES = ["Vases", "Planters", "Gardening", "Fidgets", "3D Printed", "Coasters"]

SAMPLE = [
    # name,                    sku,            price, category,     description
    ("Alvor Vase",             "CAD-VAS-0004", 4500, "Vases",
     "Sculptural patterned vase, 3D printed to order. Sandbox sample."),
    ("Apex Vase",              "CAD-VAS-0005", 4500, "Vases",
     "Faceted, ribbed contemporary vase. Sandbox sample."),
    ("Apexis Vase",            "CAD-VAS-0012", 4500, "Vases",
     "Angular display vase. Sandbox sample."),
    ("Adrift Planter",         "CAD-PLA-0005", 3500, "Planters",
     "Flowing two-part planter. Sandbox sample."),
    ("Alvao Orchid Planter",   "CAD-PLA-0006", 3500, "Planters",
     "Orchid planter with drainage. Sandbox sample."),
    ("Alvao Planter",          "CAD-PLA-0007", 3500, "Planters",
     "Textured desktop planter. Sandbox sample."),
    ("Fluxis Watering Can",    "CAD-GAR-0001", 5000, "Gardening",
     "Printed watering can. Sandbox sample."),
    ("Nexus Watering Can",     "CAD-GAR-0002", 5000, "Gardening",
     "Geometric watering can. Sandbox sample."),
    ("Mobius Fidget",          "CAD-FID-0001", 3000, "Fidgets",
     "Print-in-place mobius fidget. Sandbox sample."),
    ("Baby Dragon",            "CAD-3DP-0001", 3500, "3D Printed",
     "Articulated baby dragon. Sandbox sample."),
    ("Desk Clock",             "CAD-3DP-0002", 4000, "3D Printed",
     "Minimalist desk clock body. Sandbox sample."),
    ("Hex Coaster Set",        "CAD-COA-0001", 3000, "Coasters",
     "Set of hexagonal coasters. Sandbox sample."),
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
            f"If you're seeding sandbox, export your SANDBOX access token "
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
    """Return (objects, cat_temp_map). Reuses existing category IDs; makes temp
    CATEGORY objects for any that don't exist yet."""
    objects = []
    cat_ref = dict(existing_cats)          # name -> id (real or temp)
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
        description="Seed the Square SANDBOX catalog with sample items for the "
                    "WooCommerce sync test.",
        formatter_class=argparse.ArgumentDefaultsHelpFormatter)
    ap.add_argument("--env", choices=list(BASE), default="sandbox",
                    help="Square environment the token belongs to")
    ap.add_argument("--location", default=None,
                    help="Target location id. Default: the single sandbox location "
                         "(errors if there is more than one -- pass the one staging "
                         "Woo uses, its sandbox_location_id).")
    ap.add_argument("--limit", type=int, default=0,
                    help="Only the first N sample items (0 = all)")
    ap.add_argument("--dry-run", action="store_true",
                    help="Show what would be created; write nothing")
    ap.add_argument("--list-locations", action="store_true",
                    help="Print the locations this token can see, then exit")
    ap.add_argument("--state", default="seed_state.json",
                    help="Where to record created object IDs")
    ap.add_argument("--yes-really-seed-production", action="store_true",
                    help=argparse.SUPPRESS)  # guard for --env production
    args = ap.parse_args()

    if args.env == "production" and not args.yes_really_seed_production:
        die("Refusing to seed PRODUCTION. This tool is for sandbox. If you truly "
            "mean it, re-run with --env production --yes-really-seed-production.")

    token = os.environ.get("SQUARE_ACCESS_TOKEN")
    if not token:
        die("SQUARE_ACCESS_TOKEN is not set. Export your SANDBOX access token first:\n"
            "    export SQUARE_ACCESS_TOKEN='<sandbox token>'")

    base = BASE[args.env]
    sess = session(token)

    # Locations -------------------------------------------------------------
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
            die(f"location {location_id} is not visible to this token. "
                f"Run --list-locations to see valid ids.")
    elif len(locs) == 1:
        location_id = locs[0]["id"]
    else:
        ids = ", ".join(l["id"] for l in locs) or "(none)"
        die(f"token sees {len(locs)} locations ({ids}). Pass --location <id> "
            f"(use the sandbox_location_id staging Woo is configured for).")

    items = SAMPLE[:args.limit] if args.limit else SAMPLE

    # What already exists ---------------------------------------------------
    skus_present, cats_present = read_catalog(sess, base)
    todo = [it for it in items if it[1] not in skus_present]
    skipped = [it for it in items if it[1] in skus_present]

    log(f"\nEnvironment : {args.env}  ({base})")
    log(f"Location    : {location_id}")
    log(f"Sample      : {len(items)} item(s)  |  to create: {len(todo)}  "
        f"already present: {len(skipped)}")
    log(f"Mode        : {'DRY RUN (no writes)' if args.dry_run else 'LIVE UPLOAD to sandbox'}\n")

    for name, sku, price, cat, _ in skipped:
        log(f"  skip  {sku:14s} {name}  (SKU already in catalog)")
    for name, sku, price, cat, _ in todo:
        log(f"  new   {sku:14s} {name:24s} ${price/100:5.2f}  [{cat}]")

    if not todo:
        log("\nNothing to create -- every sample SKU already exists. Done.")
        return
    if args.dry_run:
        log("\n(dry run -- nothing was written to Square)")
        return

    # Create ----------------------------------------------------------------
    objects = build_objects(todo, location_id, cats_present)
    body = {"idempotency_key": str(uuid.uuid4()),
            "batches": [{"objects": objects}]}
    resp = check(sess.post(f"{base}/v2/catalog/batch-upsert",
                           data=json.dumps(body), timeout=120),
                 "batch-upsert catalog")
    result = resp.json()

    # Map temp ids -> real ids for the state file
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
        log(f"  {sku:14s} -> {oid}")
    log(f"\nState saved to {state_path}. "
        f"Next: verify in the Square sandbox dashboard / via the Woo import.")


if __name__ == "__main__":
    main()
