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
import re

REQUIRED_COLUMNS = ["name", "sku_code", "shapes", "source_file", "price_usd", "status"]
# Single source of truth for shape values: the SKU suffix Square variations use
# for each shape (square_push_coaster_designs.py imports this dict directly
# rather than keeping its own copy, so adding a shape here is the only edit
# needed -- see the "SHAPE_CODES lookup" code review finding on the
# laser-coaster-pipeline PR for what happens when the two drift apart).
SHAPE_CODES = {"Round": "RND", "Square": "SQR"}
VALID_SHAPES = set(SHAPE_CODES)
VALID_STATUSES = {"draft", "ready"}
SKU_CODE_RE = re.compile(r"^[A-Z]{3}$")


class ManifestError(Exception):
    """Raised for structural problems in the manifest file itself
    (missing file, missing required column) -- not per-row data problems,
    which validate_manifest reports instead."""


def load_manifest(path):
    """Read the manifest CSV at `path` and return a list of dicts, one per
    row, with `shapes` split into a list and `price_usd` parsed as float (or
    `None` if the cell is present but not a valid number -- validate_manifest
    reports that as a per-row error rather than load_manifest raising, so one
    malformed row doesn't abort loading the rest of the manifest).
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
            if not price_raw:
                price = 0.0
            else:
                try:
                    price = float(price_raw)
                except ValueError:
                    # Malformed, not blank -- e.g. a typo like "9.oo". Keep as
                    # None (distinct from a deliberate 0.0) so validate_manifest
                    # can flag it explicitly instead of it silently reading as
                    # "no price entered".
                    price = None
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
    `draft` row is exempt from the price/shape/sku_code/name checks below
    (it isn't launching yet). Returns an empty list when everything is
    valid.

    For `ready` rows this also rejects: a duplicate shape within one row's
    own `shapes` list (e.g. `["Round", "Round"]`), which would otherwise
    make build_variation_objects emit two variations sharing one SKU and
    one Square client id; a `sku_code` that isn't exactly three uppercase
    letters; and a blank `name`.

    A non-numeric price_usd cell (load_manifest parses it to `None`) is
    flagged for every row regardless of status -- unlike a `0.0` price,
    which is a legitimate placeholder on a `draft` row, garbled price text
    is a data-entry mistake worth surfacing immediately rather than letting
    it silently read as "no price entered" until the row is flipped to
    `ready`. A blank sku_code is never treated as a duplicate against
    another blank sku_code -- two `draft` placeholder rows that haven't had
    a code assigned yet aren't a real collision."""
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

        if row["price_usd"] is None:
            errors.append(f"{name}: price_usd is not a valid number")

        sku = row["sku_code"]
        if sku:
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
        elif len(set(row["shapes"])) != len(row["shapes"]):
            errors.append(
                f"{name}: duplicate shape value(s) within row {row['shapes']} -- "
                f"would produce colliding SKUs/variations"
            )

        if not SKU_CODE_RE.match(sku):
            errors.append(
                f"{name}: sku_code '{sku}' must be exactly 3 uppercase letters"
            )

        if not row["name"]:
            errors.append("(unnamed row): 'name' must not be blank for a 'ready' row")

        if row["price_usd"] is not None and row["price_usd"] <= 0:
            errors.append(f"{name}: price_usd must be > 0 for a 'ready' row")

    return errors


def resolve_thumbnail(row, search_dirs):
    """Find the PNG that already exists for this design's source LightBurn
    file -- same basename, .png extension, checked across search_dirs in
    order. Returns the first match, or None if it isn't found anywhere.
    Never generates a thumbnail; Bradley keeps a PNG on hand for every
    template already.

    A `source_file` may be nested in a subdirectory (e.g.
    'MandellaCoasters/mandella01.lbrn2') -- for each search dir, try the
    matching nested-relative path first, then fall back to a basename-only
    probe directly in that search dir (for source_files with no
    subdirectory, or thumbnail dirs that flatten everything)."""
    source_dir = os.path.dirname(row["source_file"])
    stem = os.path.splitext(os.path.basename(row["source_file"]))[0]
    for d in search_dirs:
        if source_dir:
            nested = os.path.join(d, source_dir, f"{stem}.png")
            if os.path.exists(nested):
                return nested
        candidate = os.path.join(d, f"{stem}.png")
        if os.path.exists(candidate):
            return candidate
    return None


def _main(argv):
    """Minimal CLI: `python3 coaster_manifest.py <manifest.csv>` loads and
    validates the manifest, printing each error (or 'Manifest OK') and
    exiting 1 if there were errors, 0 otherwise."""
    if len(argv) != 2:
        print(f"usage: {argv[0]} <manifest.csv>")
        return 1
    try:
        rows = load_manifest(argv[1])
    except ManifestError as e:
        print(f"ERROR: {e}")
        return 1
    errors = validate_manifest(rows)
    if errors:
        for e in errors:
            print(f"  - {e}")
        return 1
    print("Manifest OK")
    return 0


if __name__ == "__main__":
    import sys
    sys.exit(_main(sys.argv))
