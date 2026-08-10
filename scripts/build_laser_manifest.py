#!/usr/bin/env python3
"""
build_laser_manifest.py
Walk the +17K commercial laser bundle and emit a design manifest CSV that maps every
SVG design to its PNG thumbnail, theme, and collection — the curation worksheet + the
production lookup for the Phase 1 coaster design picker.

Columns: id, theme, collection, name, coaster_hint, svg_path, png_path
  - id           : stable zero-padded sequential id (deterministic sort by svg path)
  - theme        : top-level bundle theme (e.g. "Mandala Designs"), N. prefix stripped
  - collection   : the sub-collection folder the design lives in
  - name         : the file's base name (the design's own label)
  - coaster_hint : "yes" if the collection/name suggests it's coaster-ready
                   (matches "coaster" or "round"), else ""
  - svg_path     : path to the SVG, relative to the bundle root
  - png_path     : path to the matching PNG thumbnail (relative), or "MISSING"

Paths are bundle-root-relative for portability; BUNDLE (below) is the only absolute bit.
Open the CSV in a spreadsheet to curate: filter by theme / coaster_hint, then mark the
ones you want to offer per themed listing.
"""

import csv
import re
import sys
from pathlib import Path

BUNDLE = Path(
    "/Users/bradleycorner/Documents/Laser/Commercial/"
    "+17K Files Bundle Laser Works Hub"
)
OUT = Path(__file__).resolve().parent / "laser_design_manifest.csv"

COASTER_RE = re.compile(r"coaster|round", re.IGNORECASE)


def clean_theme(part: str) -> str:
    """'7. Mandala Designs' -> 'Mandala Designs'."""
    return re.sub(r"^\d+\.\s*", "", part).strip()


def find_png(svg: Path) -> Path | None:
    """Matching PNG: swap a 'SVG' path segment for 'PNG' and .svg->.png.
    Fall back to same-basename .png anywhere in the collection."""
    parts = list(svg.parts)
    # replace the last case-insensitive 'SVG' segment with 'PNG'
    for i in range(len(parts) - 1, -1, -1):
        if parts[i].lower() == "svg":
            cand = Path(*parts[:i], "PNG", *parts[i + 1:]).with_suffix(".png")
            if cand.exists():
                return cand
            # also try the exact-case sibling folder name variants
            for pdir in ("PNG", "Png", "png"):
                c2 = Path(*parts[:i], pdir, *parts[i + 1:]).with_suffix(".png")
                if c2.exists():
                    return c2
            break
    # fallback: same basename .png next to the svg or up one level
    stem = svg.stem
    for base in (svg.parent, svg.parent.parent):
        for p in base.rglob(stem + ".png"):
            return p
    return None


def main():
    if not BUNDLE.exists():
        sys.exit(f"Bundle not found: {BUNDLE}")

    svgs = sorted(BUNDLE.rglob("*.svg"), key=lambda p: str(p).lower())
    rows = []
    missing = 0
    for n, svg in enumerate(svgs, 1):
        rel = svg.relative_to(BUNDLE)
        theme = clean_theme(rel.parts[0]) if rel.parts else ""
        collection = rel.parts[1] if len(rel.parts) > 2 else theme
        png = find_png(svg)
        if png is None:
            missing += 1
        hint = "yes" if COASTER_RE.search(collection) or COASTER_RE.search(svg.stem) else ""
        rows.append({
            "id": f"{n:05d}",
            "theme": theme,
            "collection": collection,
            "name": svg.stem,
            "coaster_hint": hint,
            "svg_path": str(rel),
            "png_path": str(png.relative_to(BUNDLE)) if png else "MISSING",
        })

    with OUT.open("w", newline="") as fh:
        w = csv.DictWriter(fh, fieldnames=[
            "id", "theme", "collection", "name", "coaster_hint", "svg_path", "png_path"])
        w.writeheader()
        w.writerows(rows)

    # summary
    print(f"designs (SVG)     : {len(rows)}")
    print(f"PNG matched       : {len(rows) - missing}")
    print(f"PNG MISSING       : {missing}")
    print(f"coaster-hinted    : {sum(1 for r in rows if r['coaster_hint'] == 'yes')}")
    print("\nby theme:")
    by_theme = {}
    for r in rows:
        by_theme[r["theme"]] = by_theme.get(r["theme"], 0) + 1
    for t, c in sorted(by_theme.items(), key=lambda x: -x[1]):
        print(f"  {c:5d}  {t}")
    print(f"\nmanifest written  : {OUT}")


if __name__ == "__main__":
    main()
