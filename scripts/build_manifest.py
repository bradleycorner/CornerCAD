#!/usr/bin/env python3
"""
build_manifest.py — turn the downloaded h3li0_photos_2026 folder tree into:

  1. packages/cornercad_catalog_import/manifest.json   (folder -> catalog mapping)
  2. packages/cornercad_catalog_import/images/<slug>/... (web-optimized images)

The Proton folder is ~2.4 GB of full-res photos. This script resizes + compresses
each product's photos to web size so the package bundle stays small (~<300 KB/image),
and derives a Product entry per folder from the `<name>_<type>_photos` convention.

USAGE
    python3 scripts/build_manifest.py \
        --src   ~/Downloads/h3li0_photos_2026 \
        --out   packages/cornercad_catalog_import \
        [--max-dim 1600] [--quality 82] [--limit-per-product 6] [--dry-run]

Requires Pillow:  python3 -m pip install Pillow

NOTHING here touches the live site. Review the emitted manifest.json (categories,
cta_type, price, licensed) before deploying the package. See manifest.schema.md.
"""

import argparse
import json
import re
import sys
from pathlib import Path

# ----------------------------------------------------------------------------
# Type -> default category + friendly label. `type` is parsed from the folder
# name (the token(s) before the trailing "_photos", after the product name).
# Categories MUST match the Product Categories topic tree names exactly.
# These are DEFAULTS — hand-correct in manifest.json afterward.
# ----------------------------------------------------------------------------
TYPE_RULES = {
    # decorative / home
    "vase":            (["Home & Garden"], "Vase"),
    "planter":         (["Home & Garden"], "Planter"),
    "orchid_planter":  (["Home & Garden"], "Orchid Planter"),
    "lamp":            (["Home & Garden"], "Lamp"),
    "watering_can":    (["Home & Garden"], "Watering Can"),
    # desk / organise / functional
    "organiser":       (["Functional / Hardware", "Display & Retail"], "Organiser"),
    "pen_organiser":   (["Functional / Hardware", "Display & Retail"], "Pen Organiser"),
    "pen_holder":      (["Functional / Hardware", "Display & Retail"], "Pen Holder"),
    "drawer_module":   (["Functional / Hardware"], "Drawer Module"),
    "cable_mgmt":      (["Functional / Hardware"], "Cable Management"),
    "phone_stand":     (["Functional / Hardware", "Display & Retail"], "Phone Stand"),
    "desk_clock":      (["Functional / Hardware", "Display & Retail"], "Desk Clock"),
    "card_deck_box":   (["Display & Retail"], "Card Deck Box"),
    # display / retail
    "box":             (["Display & Retail"], "Box"),
    "boxes":           (["Display & Retail"], "Boxes"),
    "container":       (["Display & Retail"], "Container"),
    "coaster":         (["Display & Retail"], "Coasters"),
    "coasters":        (["Display & Retail"], "Coasters"),
    # seasonal / decor
    "easter_decor":    (["Home & Garden", "Display & Retail"], "Easter Decor"),
    "decor":           (["Home & Garden", "Display & Retail"], "Decor"),
}

# Type tokens that are known multi-word (checked before single-word fallback).
MULTIWORD_TYPES = [
    "orchid_planter", "pen_organiser", "pen_holder", "drawer_module",
    "cable_mgmt", "phone_stand", "desk_clock", "card_deck_box",
    "watering_can", "easter_decor",
]

IMG_EXTS = {".jpg", ".jpeg", ".png", ".webp", ".heic", ".tif", ".tiff"}


def slugify(name: str) -> str:
    s = re.sub(r"[^a-z0-9]+", "-", name.lower()).strip("-")
    return s


def parse_folder(folder_name: str):
    """
    Derive (display_name, type_token) from a folder name. The NAME is always the
    full folder (minus trailing '_photos'), titleized — so short/irregular folders
    don't get their type duplicated into the name.

    'alvor_vase_photos'         -> ('Alvor Vase',        'vase')
    'gaia_pen_organiser_photos' -> ('Gaia Pen Organiser','pen_organiser')
    'axon_planter'              -> ('Axon Planter',      'planter')
    'card_deck_box'             -> ('Card Deck Box',     'card_deck_box')
    'desk_clock'                -> ('Desk Clock',        'desk_clock')
    'cova_coasters_photos'      -> ('Cova Coasters',     'coasters')
    """
    tokens = folder_name.split("_")
    if tokens and tokens[-1] == "photos":
        tokens = tokens[:-1]
    if not tokens:
        return titleize(folder_name), "unknown"

    joined = "_".join(tokens)
    name = titleize(joined)

    # Type = longest known multi-word suffix, else the last token, else unknown.
    type_token = "unknown"
    for mw in sorted(MULTIWORD_TYPES, key=lambda x: -x.count("_")):
        if joined == mw or joined.endswith("_" + mw):
            type_token = mw
            break
    else:
        if len(tokens) > 1:
            type_token = tokens[-1]

    return name, type_token


def titleize(product: str) -> str:
    return " ".join(w.capitalize() for w in product.replace("-", "_").split("_"))


def optimize_image(src: Path, dst: Path, max_dim: int, quality: int) -> bool:
    try:
        from PIL import Image, ImageOps
    except ImportError:
        print("ERROR: Pillow not installed. Run: python3 -m pip install Pillow", file=sys.stderr)
        raise
    try:
        with Image.open(src) as im:
            im = ImageOps.exif_transpose(im)
            im = im.convert("RGB")
            im.thumbnail((max_dim, max_dim), Image.LANCZOS)
            dst.parent.mkdir(parents=True, exist_ok=True)
            im.save(dst, "JPEG", quality=quality, optimize=True, progressive=True)
        return True
    except Exception as e:  # noqa: BLE001
        print(f"  ! skip {src.name}: {e}", file=sys.stderr)
        return False


def main():
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("--src", required=True, help="Path to downloaded h3li0_photos_2026 folder")
    ap.add_argument("--out", required=True, help="Package dir (e.g. packages/cornercad_catalog_import)")
    ap.add_argument("--max-dim", type=int, default=1600, help="Max width/height px (default 1600)")
    ap.add_argument("--quality", type=int, default=82, help="JPEG quality (default 82)")
    ap.add_argument("--limit-per-product", type=int, default=6, help="Max images copied per product")
    ap.add_argument("--default-cta", default="inquire", choices=["buy", "download", "inquire"],
                    help="Default cta_type (default inquire — safe until Store products exist)")
    ap.add_argument("--licensed-tag", default="h3li0", help="licensed tag applied to all (set '' for none)")
    ap.add_argument("--dry-run", action="store_true", help="Parse + report only, write nothing")
    args = ap.parse_args()

    src = Path(args.src).expanduser()
    out = Path(args.out).expanduser()
    img_root = out / "images"
    if not src.is_dir():
        print(f"ERROR: src not found: {src}", file=sys.stderr)
        sys.exit(1)

    folders = sorted([d for d in src.iterdir() if d.is_dir()])
    print(f"Found {len(folders)} product folders under {src}")

    products = []
    for d in folders:
        name, type_token = parse_folder(d.name)
        slug = slugify(name)
        cats, _label = TYPE_RULES.get(type_token, (["Home & Garden"], ""))

        images = sorted([p for p in d.rglob("*") if p.suffix.lower() in IMG_EXTS])[: args.limit_per_product]
        rel_imgs = []
        for i, imgp in enumerate(images, 1):
            rel = f"{slug}/{slug}-{i:02d}.jpg"
            if not args.dry_run:
                optimize_image(imgp, img_root / rel, args.max_dim, args.quality)
            rel_imgs.append(rel)

        products.append({
            "slug": slug,
            "name": name,
            "type": type_token,
            "categories": cats,
            "licensed": (args.licensed_tag or None),
            "short_desc": "",
            "cta_type": args.default_cta,
            "cta_target": "",
            "price": "",
            "external_links": "",
            "featured_image": rel_imgs[0] if rel_imgs else "",
            "gallery": rel_imgs,
            "configurator": None,
        })
        print(f"  {d.name:40s} -> {slug:32s} [{type_token}] {len(rel_imgs)} imgs")

    manifest = {"catalog_parent_path": "/catalog", "products": products}
    if args.dry_run:
        print(f"\nDRY RUN — parsed {len(products)} products, wrote nothing.")
        return

    out.mkdir(parents=True, exist_ok=True)
    (out / "manifest.json").write_text(json.dumps(manifest, indent=2))
    print(f"\nWrote {out / 'manifest.json'} ({len(products)} products)")
    print(f"Optimized images under {img_root}")
    print("NEXT: review manifest.json (categories / cta_type / price / short_desc), then deploy the package.")


if __name__ == "__main__":
    main()
