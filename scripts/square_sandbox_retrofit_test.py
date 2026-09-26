#!/usr/bin/env python3
"""
square_sandbox_retrofit_test.py
Disposable SANDBOX-ONLY probe for the open question in the laser-coaster launch
runbook (docs/superpowers/plans/2026-09-05-laser-coaster-launch.md, Deployment
Runbook step 4): does Square's Catalog API support retrofitting an ITEM_OPTION
onto an ITEM that already has option-less variations, without breaking those
variations? Square's own docs do not say either way, and the real Engraved
Slate Coaster (Square item IMEO2PRALGTAWDWWKGCHNCKQ) is in exactly that state
(4 real, live, sellable variations, no item_options) -- so this is proven here,
on a throwaway sandbox clone, before anyone touches the live item.

THREE PHASES, run one at a time so you can inspect between each:
  --create    Create a throwaway ITEM in sandbox with 2 option-less variations,
              shaped like the real Engraved Slate Coaster (name/sku differ so
              it's unmistakably a test object). Prints its real id + version.
  --retrofit  In ONE sparse batch-upsert: create a new "Design" ITEM_OPTION
              (+ one option value) AND attach it to the throwaway item's
              item_options list -- WITHOUT resending the variations array.
              This is the exact shape the real retrofit would use.
  --verify    Re-fetch the throwaway item and report: are the original 2
              variations still intact (name/sku/price unchanged)? Do they now
              carry (or need) item_option_values? Any variation lost or
              silently altered is a clear "do not retrofit production" signal.
  --cleanup   Permanently delete the throwaway item from sandbox when done.

SAFETY -- same pattern as square_seed_sandbox.py / square_push_coaster_designs.py:
  * Token read ONLY from SQUARE_ACCESS_TOKEN. Never printed or written to disk.
  * Default --env is sandbox. A production token used here fails with 401 (env
    mismatch), so an accidentally-exported prod token cannot silently run this
    against the live catalog.
  * Production is hard-blocked behind --env production AND
    --yes-really-touch-production -- there should never be a real reason to
    pass that; this script's whole purpose is to avoid touching production.
  * --dry-run on --create and --retrofit prints the exact request body and
    writes nothing.

QUICK START
  export SQUARE_ACCESS_TOKEN='<sandbox access token>'
  python3 square_sandbox_retrofit_test.py --create
  python3 square_sandbox_retrofit_test.py --retrofit
  python3 square_sandbox_retrofit_test.py --verify
  python3 square_sandbox_retrofit_test.py --cleanup
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
STATE_FILE = Path(__file__).with_name("retrofit_test_state.json")

TEST_SKU_PREFIX = "ZZTEST-RETROFIT-"


def log(msg):
    print(msg, flush=True)


def die(msg):
    print("ERROR: " + msg, file=sys.stderr, flush=True)
    sys.exit(1)


def load_state():
    return json.loads(STATE_FILE.read_text()) if STATE_FILE.exists() else {}


def save_state(state):
    STATE_FILE.write_text(json.dumps(state, indent=1))


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
        die(f"{what}: HTTP 401 Unauthorized. Token/--env mismatch -- export your "
            f"SANDBOX token. Nothing was written.")
    if resp.status_code >= 400:
        die(f"{what}: HTTP {resp.status_code}: {resp.text[:800]}")
    return resp


def get_location(sess, base):
    resp = check(sess.get(f"{base}/v2/locations", timeout=60), "list locations")
    locs = resp.json().get("locations", [])
    if not locs:
        die("token sees no locations")
    if len(locs) > 1:
        log(f"NOTE: {len(locs)} locations visible; using the first ({locs[0]['id']}).")
    return locs[0]["id"]


def do_create(sess, base, dry_run):
    location_id = get_location(sess, base)
    objects = [{
        "type": "ITEM",
        "id": "#item_retrofit_test",
        "present_at_all_locations": False,
        "present_at_location_ids": [location_id],
        "item_data": {
            "name": "ZZ TEST -- Coaster Retrofit Probe (delete me)",
            "description": "Disposable sandbox item for the ITEM_OPTION retrofit test. Safe to delete.",
            "product_type": "REGULAR",
            "variations": [
                {
                    "type": "ITEM_VARIATION",
                    "id": "#var_retrofit_a",
                    "item_variation_data": {
                        "item_id": "#item_retrofit_test",
                        "name": "Square - Single",
                        "sku": f"{TEST_SKU_PREFIX}A",
                        "pricing_type": "FIXED_PRICING",
                        "price_money": {"amount": 800, "currency": "USD"},
                        "location_overrides": [{"location_id": location_id, "track_inventory": False}],
                    },
                },
                {
                    "type": "ITEM_VARIATION",
                    "id": "#var_retrofit_b",
                    "item_variation_data": {
                        "item_id": "#item_retrofit_test",
                        "name": "Round - Single",
                        "sku": f"{TEST_SKU_PREFIX}B",
                        "pricing_type": "FIXED_PRICING",
                        "price_money": {"amount": 800, "currency": "USD"},
                        "location_overrides": [{"location_id": location_id, "track_inventory": False}],
                    },
                },
            ],
        },
    }]
    body = {"idempotency_key": str(uuid.uuid4()), "batches": [{"objects": objects}]}
    log("Would create:\n" + json.dumps(body, indent=2))
    if dry_run:
        log("\n(dry run -- nothing was written)")
        return
    resp = check(sess.post(f"{base}/v2/catalog/batch-upsert", data=json.dumps(body), timeout=60),
                 "create throwaway item")
    result = resp.json()
    mapping = {m["client_object_id"]: m["object_id"] for m in result.get("id_mappings", [])}
    item_id = mapping.get("#item_retrofit_test")
    item_obj = next(o for o in result.get("objects", []) if o["id"] == item_id)
    state = load_state()
    state["item_id"] = item_id
    state["item_version"] = item_obj["version"]
    state["location_id"] = location_id
    save_state(state)
    log(f"\nCreated throwaway item {item_id} (version {item_obj['version']}) with 2 option-less variations.")
    log(f"State saved to {STATE_FILE}. Next: --retrofit")


def do_retrofit(sess, base, dry_run):
    state = load_state()
    item_id = state.get("item_id")
    item_version = state.get("item_version")
    if not item_id:
        die("no throwaway item in state -- run --create first")

    objects = [
        {
            "type": "ITEM_OPTION",
            "id": "#opt_design",
            "item_option_data": {
                "name": "Design",
                "values": [{
                    "type": "ITEM_OPTION_VAL",
                    "id": "#optval_design_test",
                    "item_option_value_data": {"name": "Test Design"},
                }],
            },
        },
        # THE ACTUAL TEST: attach the option to the EXISTING item by id+version,
        # WITHOUT resending item_data.variations. If sparse_update genuinely
        # merges, the 2 existing variations should survive untouched.
        {
            "type": "ITEM",
            "id": item_id,
            "version": item_version,
            "item_data": {
                "item_options": [{"item_option_id": "#opt_design"}],
            },
        },
    ]
    body = {
        "idempotency_key": str(uuid.uuid4()),
        "batches": [{"objects": objects}],
        "sparse_update": True,
    }
    log("Would send (sparse_update: true):\n" + json.dumps(body, indent=2))
    if dry_run:
        log("\n(dry run -- nothing was written)")
        return
    resp = check(sess.post(f"{base}/v2/catalog/batch-upsert", data=json.dumps(body), timeout=60),
                 "retrofit ITEM_OPTION onto existing item")
    result = resp.json()
    log("\nRaw response:\n" + json.dumps(result, indent=2))
    log("\nNow run --verify to check whether the original 2 variations survived.")


def do_verify(sess, base):
    state = load_state()
    item_id = state.get("item_id")
    if not item_id:
        die("no throwaway item in state -- run --create first")
    resp = check(sess.post(f"{base}/v2/catalog/batch-retrieve",
                            data=json.dumps({"object_ids": [item_id], "include_related_objects": True}),
                            timeout=60),
                 "retrieve throwaway item")
    result = resp.json()
    item = next((o for o in result.get("objects", []) if o["id"] == item_id), None)
    if not item:
        die(f"item {item_id} not found -- was it deleted?")
    item_data = item.get("item_data", {})
    log(f"Item: {item_data.get('name')}  (version {item.get('version')})")
    log(f"item_options attached: {item_data.get('item_options', 'NONE')}")
    variations = item_data.get("variations", [])
    log(f"\n{len(variations)} variation(s) found (expected 2):")
    for v in variations:
        vd = v.get("item_variation_data", {})
        log(f"  {vd.get('sku', '?'):24s} name={vd.get('name','?'):16s} "
            f"price={vd.get('price_money',{}).get('amount','?')} "
            f"item_option_values={vd.get('item_option_values', 'NONE')}")
    log("\nVerdict:")
    if len(variations) == 2 and all(v.get("item_variation_data", {}).get("sku", "").startswith(TEST_SKU_PREFIX) for v in variations):
        log("  Both original variations survived with their SKUs intact.")
        if any(not v.get("item_variation_data", {}).get("item_option_values") for v in variations):
            log("  BUT: at least one variation has no item_option_values for the new option --")
            log("  check in the Square Sandbox dashboard whether that variation still displays/sells")
            log("  correctly (a variation missing a value for an attached option may become hidden or")
            log("  unselectable in some Square surfaces even though the Catalog API returned it intact).")
        else:
            log("  Retrofit looks safe to attempt on the real production item.")
    else:
        log("  ONE OR MORE ORIGINAL VARIATIONS ARE MISSING OR CHANGED.")
        log("  Do NOT attempt this retrofit on the real production item (4787 / IMEO2PRALGTAWDWWKGCHNCKQ).")
        log("  Use a different approach instead (e.g. a brand-new parent item per material, not a retrofit).")


def do_cleanup(sess, base):
    state = load_state()
    item_id = state.get("item_id")
    if not item_id:
        log("nothing to clean up -- no item in state")
        return
    resp = check(sess.post(f"{base}/v2/catalog/batch-delete",
                            data=json.dumps({"object_ids": [item_id]}), timeout=60),
                 "delete throwaway item")
    log(f"Deleted: {resp.json().get('deleted_object_ids', [])}")
    STATE_FILE.unlink(missing_ok=True)


def main():
    ap = argparse.ArgumentParser(
        description="Sandbox-only probe: can an ITEM_OPTION be retrofitted onto an "
                    "item with existing option-less variations?",
        formatter_class=argparse.ArgumentDefaultsHelpFormatter)
    ap.add_argument("--env", choices=list(BASE), default="sandbox")
    ap.add_argument("--create", action="store_true")
    ap.add_argument("--retrofit", action="store_true")
    ap.add_argument("--verify", action="store_true")
    ap.add_argument("--cleanup", action="store_true")
    ap.add_argument("--dry-run", action="store_true")
    ap.add_argument("--yes-really-touch-production", action="store_true", help=argparse.SUPPRESS)
    args = ap.parse_args()

    if args.env == "production" and not args.yes_really_touch_production:
        die("Refusing to run against PRODUCTION. This script exists specifically to "
            "avoid needing to.")

    if not any([args.create, args.retrofit, args.verify, args.cleanup]):
        die("pass one of --create / --retrofit / --verify / --cleanup")

    token = os.environ.get("SQUARE_ACCESS_TOKEN")
    if not token:
        die("SQUARE_ACCESS_TOKEN is not set. Export your SANDBOX access token first.")

    base = BASE[args.env]
    sess = session(token)

    if args.create:
        do_create(sess, base, args.dry_run)
    if args.retrofit:
        do_retrofit(sess, base, args.dry_run)
    if args.verify:
        do_verify(sess, base)
    if args.cleanup:
        do_cleanup(sess, base)


if __name__ == "__main__":
    main()
