#!/usr/bin/env python3
"""
square_push_coaster_designs.py
Push the coaster design manifest (content/coaster-designs.csv) to the Square
catalog as variations of an existing coaster parent item (the Engraved Slate
Coaster, or a future material's parent item), using a shared "Design"
ITEM_OPTION -- per docs/superpowers/specs/2026-09-05-laser-coaster-launch-
design.md.

SAFETY -- same pattern as square_seed_sandbox.py:
  * Access token read ONLY from SQUARE_ACCESS_TOKEN. Never printed/written.
  * Default --env is sandbox. Production requires --env production AND
    --yes-really-push-production.
  * --dry-run prints exactly what would be created/updated and writes
    nothing.
  * This script only ever sends NEW `#`-prefixed temporary-id objects to
    Square (a new "Design" option value per design/shape, a new variation
    per design/shape) -- it never sends an existing object's id with
    modified fields. No existing Square object (the parent item included)
    is ever replaced or has its data wiped by this script.

NOT DONE BY THIS SCRIPT -- one-time parent-item setup:
  The parent item's own "Design" ITEM_OPTION must already exist and already
  be attached to the parent item's `item_options` list before you run this
  script. That is a one-time, per-parent-item setup step done interactively
  via the Square MCP connector (with sparse_update: true) -- see the
  Deployment Runbook. This script deliberately does NOT create or attach
  that option itself: retrofitting an ITEM_OPTION onto an item that already
  has option-less variations is not documented as supported by Square, and
  a mis-shaped ITEM update via this raw REST endpoint risks replacing the
  whole item (see SAFETY above and CLAUDE.md's sparse_update warnings).

QUICK START
  export SQUARE_ACCESS_TOKEN='<sandbox access token>'
  python3 square_push_coaster_designs.py --dry-run \\
      --parent-item-id <existing coaster ITEM id> --parent-sku-number 0004 \\
      --design-option-id <existing 'Design' ITEM_OPTION id>
"""

import argparse
import json
import os
import sys
import uuid

try:
    import requests
except ImportError:
    sys.exit("Missing dependency. Run:  pip install requests")

from coaster_manifest import (
    load_manifest,
    validate_manifest,
    resolve_thumbnail,
    ManifestError,
    SHAPE_CODES,
)

BASE = {
    "sandbox": "https://connect.squareupsandbox.com",
    "production": "https://connect.squareup.com",
}
SQUARE_VERSION = os.environ.get("SQUARE_VERSION", "2025-04-16")


def sku_for(row, shape, parent_sku_number):
    """CAD-COA-<parent_sku_number>-<design code>[-<shape code>].
    The shape suffix is only added when the row offers more than one
    shape -- a single-shape design's SKU has no shape code. Shape codes
    come from coaster_manifest.SHAPE_CODES -- the same dict validate_manifest
    checks shape values against -- so a shape can never pass manifest
    validation and then have no SKU code to map it to here."""
    base = f"CAD-COA-{parent_sku_number}-{row['sku_code']}"
    if len(row["shapes"]) > 1:
        return f"{base}-{SHAPE_CODES[shape]}"
    return base


def variation_label(row, shape):
    """'<name>' for a single-shape row, '<name> — <shape>' for a
    multi-shape row (spec §3)."""
    if len(row["shapes"]) > 1:
        return f"{row['name']} — {shape}"
    return row["name"]


def build_option_value_object(row, shape, option_id):
    """One ITEM_OPTION_VAL for this row+shape combination."""
    temp_id = f"#optval_{row['sku_code'].lower()}_{shape.lower()}"
    return {
        "type": "ITEM_OPTION_VAL",
        "id": temp_id,
        "item_option_value_data": {
            "item_option_id": option_id,
            "name": variation_label(row, shape),
        },
    }


def build_variation_objects(row, item_id, option_id, option_value_ids, parent_sku_number):
    """One ITEM_VARIATION per shape in the row, each linked to its own
    ITEM_OPTION_VAL id (from option_value_ids, keyed by shape name)."""
    variations = []
    for shape in row["shapes"]:
        sku = sku_for(row, shape, parent_sku_number)
        variations.append({
            "type": "ITEM_VARIATION",
            "id": f"#var_{row['sku_code'].lower()}_{shape.lower()}",
            "item_variation_data": {
                "item_id": item_id,
                "name": variation_label(row, shape),
                "sku": sku,
                "pricing_type": "FIXED_PRICING",
                "price_money": {
                    "amount": round(row["price_usd"] * 100),
                    "currency": "USD",
                },
                "item_option_values": [{
                    "item_option_id": option_id,
                    "item_option_value_id": option_value_ids[shape],
                }],
            },
        })
    return variations


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
        sys.exit(
            f"ERROR: {what}: HTTP 401 Unauthorized. The token does not match --env. "
            f"Nothing was written."
        )
    if resp.status_code >= 400:
        sys.exit(f"ERROR: {what}: HTTP {resp.status_code}: {resp.text[:600]}")
    return resp


def main():
    ap = argparse.ArgumentParser(
        description="Push content/coaster-designs.csv to the Square catalog.",
        formatter_class=argparse.ArgumentDefaultsHelpFormatter)
    ap.add_argument(
        "--manifest",
        default=os.path.join(os.path.dirname(os.path.abspath(__file__)), "..", "content", "coaster-designs.csv"),
    )
    ap.add_argument("--env", choices=list(BASE), default="sandbox")
    ap.add_argument("--parent-item-id", required=True,
                     help="Square ITEM id of the existing coaster parent (e.g. Engraved Slate Coaster)")
    ap.add_argument("--parent-sku-number", required=True,
                     help="the ####  segment of the parent's own SKU, e.g. '0004' for CAD-COA-0004")
    ap.add_argument("--design-option-id", required=True,
                     help="id of the 'Design' ITEM_OPTION that ALREADY EXISTS and is ALREADY attached "
                          "to the parent item's own item_options list -- this script never creates or "
                          "attaches that option itself (see the module docstring); attach it first via "
                          "the Square MCP connector, proven in sandbox before production")
    ap.add_argument("--dry-run", action="store_true")
    ap.add_argument("--thumbnail-dir", action="append", default=[],
                     help="directory to search for each design's existing PNG (repeatable)")
    ap.add_argument("--yes-really-push-production", action="store_true", help=argparse.SUPPRESS)
    args = ap.parse_args()

    if args.env == "production" and not args.yes_really_push_production:
        sys.exit("ERROR: Refusing to push to PRODUCTION without --yes-really-push-production.")

    try:
        rows = load_manifest(args.manifest)
    except ManifestError as e:
        sys.exit(f"ERROR: {e}")
    ready_rows = [r for r in rows if r["status"] == "ready"]
    errors = validate_manifest(rows)
    if errors:
        print("Manifest has errors -- fix these before pushing:")
        for e in errors:
            print(f"  - {e}")
        sys.exit(1)

    if not ready_rows:
        print("No 'ready' rows in the manifest. Nothing to push.")
        return

    option_id = args.design_option_id
    objects = []

    option_value_ids = {}
    for row in ready_rows:
        for shape in row["shapes"]:
            obj = build_option_value_object(row, shape, option_id)
            objects.append(obj)
            option_value_ids[(row["sku_code"], shape)] = obj["id"]

    for row in ready_rows:
        ids_for_row = {shape: option_value_ids[(row["sku_code"], shape)] for shape in row["shapes"]}
        objects.extend(build_variation_objects(
            row, args.parent_item_id, option_id, ids_for_row, args.parent_sku_number,
        ))

    print(f"Environment : {args.env}")
    print(f"Manifest    : {args.manifest}  ({len(ready_rows)} ready design(s))")
    print(f"Mode        : {'DRY RUN (no writes)' if args.dry_run else 'LIVE UPLOAD'}\n")
    for row in ready_rows:
        thumb = resolve_thumbnail(row, args.thumbnail_dir) if args.thumbnail_dir else None
        thumb_note = thumb if thumb else "NO THUMBNAIL FOUND" if args.thumbnail_dir else "(no --thumbnail-dir given)"
        for shape in row["shapes"]:
            print(f"  {sku_for(row, shape, args.parent_sku_number):24s} "
                  f"{variation_label(row, shape):38s} ${row['price_usd']:.2f}")
        print(f"      thumbnail: {thumb_note}")

    if args.dry_run:
        print("\n(dry run -- nothing was written to Square)")
        return

    token = os.environ.get("SQUARE_ACCESS_TOKEN")
    if not token:
        sys.exit("ERROR: SQUARE_ACCESS_TOKEN is not set.")

    sess = session(token)
    base = BASE[args.env]
    body = {
        "idempotency_key": str(uuid.uuid4()),
        "batches": [{"objects": objects}],
    }
    resp = check(
        sess.post(f"{base}/v2/catalog/batch-upsert",
                  data=json.dumps(body), timeout=120),
        "batch-upsert catalog",
    )
    result = resp.json()
    print(f"\nPushed {len(objects)} object(s).")
    for m in result.get("id_mappings", []):
        print(f"  {m.get('client_object_id')} -> {m.get('object_id')}")


if __name__ == "__main__":
    main()
