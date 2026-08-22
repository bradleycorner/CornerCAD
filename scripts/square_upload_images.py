#!/usr/bin/env python3
"""
square_upload_images.py
Bulk-upload h3li0 product photos to the Square catalog and attach them to items,
setting each product's hero frame as the primary image.

WHY THIS EXISTS
  Square's CSV import can't carry images, and the Dashboard bulk uploader does only
  one image per item. The Catalog API (CreateCatalogImage) is the only way to build
  ordered, multi-image galleries with a chosen primary. This script drives that API.

WHAT IT DOES
  1. Resolves each product's SKU (from upload_plan.json) to its Square item ID by
     reading your live catalog.
  2. For each product, uploads its images in the order listed in the plan; images[0]
     (the hero / featured frame) is attached with is_primary = true.
  3. Records progress to a state file so re-runs skip anything already uploaded.

SAFETY / DESIGN
  * Reads your access token ONLY from the SQUARE_ACCESS_TOKEN environment variable.
    The token is never written to disk or printed.
  * --dry-run shows exactly what would happen and uploads nothing.
  * --product lets you test against a single product first (match on slug, name, or SKU).
  * Idempotency keys are deterministic (sku+filename), and Square de-dupes identical
    files, so an interrupted run is safe to repeat.

REQUIREMENTS
  Python 3.8+ and the `requests` library:   pip install requests

QUICK START
  export SQUARE_ACCESS_TOKEN='your_production_access_token'

  # 1) See what it would do for one product, no writes:
  python3 square_upload_images.py --product adrift --dry-run

  # 2) Actually upload that one product:
  python3 square_upload_images.py --product adrift

  # 3) Once it looks right in Square, run the whole catalog:
  python3 square_upload_images.py

  # Resume is automatic; to only (re)set primaries and skip galleries: --primary-only
"""

import argparse
import json
import os
import sys
import time
import uuid
from pathlib import Path

try:
    import requests
except ImportError:
    sys.exit("Missing dependency. Run:  pip install requests")

BASE = {
    "production": "https://connect.squareup.com",
    "sandbox": "https://connect.squareupsandbox.com",
}
# Square API version. Override with SQUARE_VERSION if you need a newer one.
SQUARE_VERSION = os.environ.get("SQUARE_VERSION", "2025-04-16")
IDEMPOTENCY_NS = uuid.UUID("6f1a0b7e-0000-4000-8000-000000000001")  # fixed namespace

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
    })
    return s


def request_with_retry(fn, what, tries=6):
    """Call fn() -> requests.Response, retrying on 429 / 5xx with backoff."""
    delay = 1.0
    for attempt in range(1, tries + 1):
        resp = fn()
        if resp.status_code < 400:
            return resp
        if resp.status_code == 429 or resp.status_code >= 500:
            wait = float(resp.headers.get("Retry-After", 0)) or delay
            if attempt == tries:
                die(f"{what}: gave up after {tries} tries "
                    f"(last status {resp.status_code}): {resp.text[:400]}")
            log(f"   .. {what}: status {resp.status_code}, retrying in {wait:.1f}s "
                f"(attempt {attempt}/{tries})")
            time.sleep(wait)
            delay = min(delay * 2, 30)
            continue
        # 4xx that isn't rate limiting -> real error, don't retry
        die(f"{what}: status {resp.status_code}: {resp.text[:600]}")
    return None  # unreachable


def build_sku_map(sess, base):
    """Return {sku: item_id} by paging the whole catalog once."""
    log("Resolving SKUs -> Square item IDs (reading your live catalog)...")
    sku_map = {}
    cursor = None
    pages = 0
    while True:
        params = {"types": "ITEM"}
        if cursor:
            params["cursor"] = cursor
        resp = request_with_retry(
            lambda: sess.get(f"{base}/v2/catalog/list", params=params, timeout=60),
            "list catalog",
        )
        data = resp.json()
        for obj in data.get("objects", []):
            if obj.get("type") != "ITEM":
                continue
            item_id = obj["id"]
            for v in obj.get("item_data", {}).get("variations", []):
                sku = v.get("item_variation_data", {}).get("sku")
                if sku:
                    sku_map[sku] = item_id
        pages += 1
        cursor = data.get("cursor")
        if not cursor:
            break
    log(f"   read {pages} page(s), {len(sku_map)} SKUs in the catalog.")
    return sku_map


def upload_one(sess, base, item_id, path, is_primary, caption):
    req = {
        "idempotency_key": str(uuid.uuid5(IDEMPOTENCY_NS, f"{item_id}:{path.name}")),
        "object_id": item_id,
        "is_primary": bool(is_primary),
        "image": {"type": "IMAGE", "id": "#new_image",
                  "image_data": {"caption": caption}},
    }
    with open(path, "rb") as fh:
        files = {
            "request": (None, json.dumps(req), "application/json"),
            "image_file": (path.name, fh, "image/jpeg"),
        }
        resp = request_with_retry(
            lambda: sess.post(f"{base}/v2/catalog/images", files=files, timeout=120),
            f"upload {path.name}",
        )
    return resp.json().get("image", {}).get("id", "")


# ---------------------------------------------------------------------------

def main():
    ap = argparse.ArgumentParser(
        description="Upload h3li0 product images to the Square catalog.",
        formatter_class=argparse.ArgumentDefaultsHelpFormatter)
    ap.add_argument("--plan", default="upload_plan.json",
                    help="Path to upload_plan.json")
    ap.add_argument("--media-root", default="h3li0 Media (web)",
                    help="Folder that contains the per-product image subfolders")
    ap.add_argument("--product", default=None,
                    help="Only this product: matches slug, name, or SKU (substring, "
                         "case-insensitive). Great for a first test.")
    ap.add_argument("--env", choices=list(BASE), default="production",
                    help="Which Square environment the token belongs to")
    ap.add_argument("--dry-run", action="store_true",
                    help="Show what would happen; upload nothing")
    ap.add_argument("--primary-only", action="store_true",
                    help="Upload only each product's hero (primary) image")
    ap.add_argument("--max-per-product", type=int, default=0,
                    help="Cap images per product (0 = all)")
    ap.add_argument("--state", default="upload_state.json",
                    help="Progress file; lets you resume and avoids duplicates")
    ap.add_argument("--sleep", type=float, default=0.25,
                    help="Seconds to pause between uploads (be gentle on rate limits)")
    args = ap.parse_args()

    plan_path = Path(args.plan)
    if not plan_path.exists():
        die(f"plan not found: {plan_path}")
    media_root = Path(args.media_root)
    if not media_root.exists():
        die(f"media root not found: {media_root}  (use --media-root to point at it)")

    plan = json.loads(plan_path.read_text())
    products = plan["products"] if isinstance(plan, dict) else plan

    if args.product:
        q = args.product.lower()
        products = [p for p in products
                    if q in p["slug"].lower()
                    or q in p["name"].lower()
                    or q in p["sku"].lower()]
        if not products:
            die(f"no product matched '{args.product}'")

    token = os.environ.get("SQUARE_ACCESS_TOKEN")
    if not token and not args.dry_run:
        die("SQUARE_ACCESS_TOKEN is not set. Export your Square access token first.")

    base = BASE[args.env]

    # progress state: {"<sku>::<filename>": "<image_id>"}
    state_path = Path(args.state)
    state = json.loads(state_path.read_text()) if state_path.exists() else {}

    def save_state():
        state_path.write_text(json.dumps(state, indent=1))

    sess = None
    sku_map = {}
    if not args.dry_run:
        sess = session(token)
        sku_map = build_sku_map(sess, base)

    total_products = len(products)
    log(f"\nEnvironment : {args.env}  ({base})")
    log(f"Products    : {total_products}")
    log(f"Mode        : {'DRY RUN (no writes)' if args.dry_run else 'LIVE UPLOAD'}"
        f"{'  [primary-only]' if args.primary_only else ''}\n")

    uploaded = skipped = missing = failed_products = 0

    for pi, p in enumerate(products, 1):
        sku = p["sku"]; name = p["name"]
        imgs = p["images"][:1] if args.primary_only else p["images"]
        if args.max_per_product:
            imgs = imgs[:args.max_per_product]
        folder = media_root / p["folder"]

        item_id = None if args.dry_run else sku_map.get(sku)
        if not args.dry_run and not item_id:
            log(f"[{pi}/{total_products}] {name} ({sku})  !! no matching item in Square "
                f"-- skipping")
            failed_products += 1
            continue

        log(f"[{pi}/{total_products}] {name} ({sku}) -> "
            f"{item_id or 'DRY'}  | {len(imgs)} image(s)")

        for idx, fname in enumerate(imgs):
            is_primary = (idx == 0)
            key = f"{sku}::{fname}"
            path = folder / fname
            tag = "PRIMARY" if is_primary else "gallery"

            if not path.exists():
                log(f"      !! missing file: {path}")
                missing += 1
                continue
            if key in state:
                skipped += 1
                continue
            if args.dry_run:
                log(f"      would upload {fname:32s} [{tag}]")
                continue

            img_id = upload_one(sess, base, item_id, path, is_primary,
                                caption=name)
            state[key] = img_id
            uploaded += 1
            save_state()
            log(f"      uploaded {fname:32s} [{tag}] -> {img_id}")
            time.sleep(args.sleep)

    log("\n---- summary ----")
    log(f"uploaded        : {uploaded}")
    log(f"skipped (done)  : {skipped}")
    log(f"missing files   : {missing}")
    log(f"unmatched items : {failed_products}")
    if args.dry_run:
        log("(dry run -- nothing was written to Square)")
    else:
        log(f"state saved to  : {state_path}")


if __name__ == "__main__":
    main()
