#!/usr/bin/env python3
"""
coaster_manifest.py
Load and validate content/coaster-designs.csv -- the single source of truth
mapping each laser-coaster design to its SKU code, shape(s), source LightBurn
file, price, and readiness, per docs/superpowers/specs/
2026-09-05-laser-coaster-launch-design.md #5.

Used by square_push_coaster_designs.py to drive what gets pushed to Square;
never edit Square directly for a coaster design -- edit this CSV instead.
"""

import csv
import os

REQUIRED_COLUMNS = ["name", "sku_code", "shapes", "source_file", "price_usd", "status"]
VALID_SHAPES = {"Round", "Square"}
VALID_STATUSES = {"draft", "ready"}


class ManifestError(Exception):
    """Raised for structural problems in the manifest file itself
    (missing file, missing required column) -- not per-row data problems,
    which validate_manifest reports instead."""


def load_manifest(path):
    """Read the manifest CSV at `path` and return a list of dicts, one per
    row, with `shapes` split into a list and `price_usd` parsed as float.
    Raises ManifestError if the file is missing or a required column is
    absent."""
    if not os.path.exists(path):
        raise ManifestError(f"manifest not found: {path}")

    with open(path, newline="") as fh:
        reader = csv.DictReader(fh)
        fieldnames = reader.fieldnames or []
        missing = [c for c in REQUIRED_COLUMNS if c not in fieldnames]
        if missing:
            raise ManifestError(
                f"manifest {path} is missing required column(s): {', '.join(missing)}"
            )

        rows = []
        for raw in reader:
            shapes_raw = (raw.get("shapes") or "").strip()
            shapes = [s.strip() for s in shapes_raw.split(";") if s.strip()]
            price_raw = (raw.get("price_usd") or "").strip()
            try:
                price = float(price_raw) if price_raw else 0.0
            except ValueError:
                price = 0.0
            rows.append({
                "name": (raw.get("name") or "").strip(),
                "sku_code": (raw.get("sku_code") or "").strip(),
                "shapes": shapes,
                "source_file": (raw.get("source_file") or "").strip(),
                "price_usd": price,
                "status": (raw.get("status") or "").strip(),
            })
        return rows


def validate_manifest(rows):
    """Return a list of human-readable error strings for problems that
    would break a Square push -- duplicate SKU codes, invalid shape values,
    a non-positive price on a `ready` row, or an unrecognized status. A
    `draft` row is exempt from the price/shape checks (it isn't launching
    yet). Returns an empty list when everything is valid."""
    errors = []
    seen_skus = {}

    for row in rows:
        name = row["name"] or "(unnamed row)"
        status = row["status"]

        if status not in VALID_STATUSES:
            errors.append(
                f"{name}: unknown status '{status}' (expected one of {sorted(VALID_STATUSES)})"
            )
            continue

        sku = row["sku_code"]
        if sku in seen_skus:
            errors.append(
                f"{name}: duplicate sku_code '{sku}' (also used by '{seen_skus[sku]}')"
            )
        else:
            seen_skus[sku] = name

        if status == "draft":
            continue  # not launching yet -- skip readiness checks below

        bad_shapes = [s for s in row["shapes"] if s not in VALID_SHAPES]
        if bad_shapes:
            errors.append(
                f"{name}: invalid shape value(s) {bad_shapes} "
                f"(expected one of {sorted(VALID_SHAPES)})"
            )
        if not row["shapes"]:
            errors.append(f"{name}: a 'ready' row must list at least one shape")

        if row["price_usd"] <= 0:
            errors.append(f"{name}: price_usd must be > 0 for a 'ready' row")

    return errors


def resolve_thumbnail(row, search_dirs):
    """Find the PNG that already exists for this design's source LightBurn
    file -- same basename, .png extension, checked across search_dirs in
    order. Returns the first match, or None if it isn't found anywhere.
    Never generates a thumbnail; Bradley keeps a PNG on hand for every
    template already."""
    stem = os.path.splitext(os.path.basename(row["source_file"]))[0]
    for d in search_dirs:
        candidate = os.path.join(d, f"{stem}.png")
        if os.path.exists(candidate):
            return candidate
    return None
