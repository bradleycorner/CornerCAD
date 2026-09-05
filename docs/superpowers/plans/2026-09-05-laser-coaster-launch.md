# Laser Coaster Launch — Onboarding Pipeline Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build the tooling (manifest schema, Square-push script, min-order-quantity enforcement) that
lets Bradley add his proven laser-coaster designs — his own tested `Laser/Commercial/Coasters`
`.lbrn2` files, one manifest row at a time — into the Engraved Slate Coaster product on cornercad.com,
without any manual per-design product editing.

**Architecture:** A CSV manifest (`content/coaster-designs.csv`) is the single source of truth for
which designs exist, their SKU codes, shapes, and prices. A Python script reads it, builds Square
Catalog API objects (a shared "Design" `ITEM_OPTION` + one `ITEM_OPTION_VAL` and one variation per
design), and pushes them with `sparse_update: true`. The existing Square→Woo sync (WP-CLI) pulls the
result into the live product. A separate WPCode PHP snippet enforces the minimum-order-quantity-of-4
rule on any coaster product that has a "Design" attribute — gated on the attribute's presence, not a
hardcoded product-ID list, so a future material's parent product picks up the rule automatically.

**Tech Stack:** Python 3 (stdlib `csv`/`argparse`/`unittest` + `requests`, matching every existing
script in `scripts/`), PHP (WPCode snippet, matching `snippets/wpcode-cornercad-auto-crosssells.php`),
Square Catalog API v2, WP-CLI.

**Spec:** `docs/superpowers/specs/2026-09-05-laser-coaster-launch-design.md` (read this first — it
explains *why* Square-sync over Woo-native, why one parent product per material, and why "Design" is
the one flattened Woo attribute).

## Global Constraints

- **Never commit or print a Square access token.** Read it only from `SQUARE_ACCESS_TOKEN`; every
  script that touches Square must refuse to run without it and must never log its value (matches
  every existing script in `scripts/`).
- **`sparse_update: true` on every Square `batchUpsertCatalogObjects`/`batchUpdateObjects` call** — a
  full update replaces the object and wipes descriptions/images/variations (per project `CLAUDE.md`).
- **Default `--env` is `sandbox`; production requires an explicit `--env production` AND a
  `--yes-really-*-production` flag** — matches `square_seed_sandbox.py`'s safety pattern. Never make
  production the default for a new script.
- **No live WordPress/Square writes as part of automated task execution.** Every task in this plan
  produces local files (scripts, tests, the manifest template, the snippet file) and runs local
  verification only. The actual deployment (creating the WPCode snippet post, running the push script
  against real Square data, triggering the WP-CLI sync) is a separate, Bradley-confirmed runbook at
  the end of this plan — per the project's standing write-safety rule, no write to the live store
  happens without showing the exact tool/target/change and getting an explicit yes first.
- **WooCommerce SKU pattern:** `CAD-COA-####-<3-letter design code>` per variation (spec §6). Never
  reuse a parent product's SKU on a variation (this deleted a Woo product once before — see
  `project_square-untracked-variation-stock-bug` memory).
- **One Woo variation attribute: "Design."** Shape folds into the value label (e.g. `Diamond Lattice —
  Round`) rather than being a second variation dimension (spec §3) — Square sync silently drops a
  product with 2+ true variation attributes.
- **Tabs for PHP indentation**, matching `snippets/wpcode-cornercad-auto-crosssells.php`.
- **Idempotent PHP snippet** — guard with `if ( ! function_exists( ... ) )`, matching the existing
  snippet's pattern, so re-deploying is always safe.

---

## Task 1: Design manifest schema, loader, and validator

**Files:**
- Create: `content/coaster-designs.csv`
- Create: `scripts/coaster_manifest.py`
- Test: `scripts/test_coaster_manifest.py`

**Interfaces:**
- Produces: `coaster_manifest.load_manifest(path: str) -> list[dict]` — one dict per CSV row, keys
  exactly `name`, `sku_code`, `shapes`, `source_file`, `price_usd`, `status`. `shapes` is parsed into
  `list[str]` (split on `;`), `price_usd` into `float`.
- Produces: `coaster_manifest.validate_manifest(rows: list[dict]) -> list[str]` — returns a list of
  human-readable error strings (empty list = valid). Later tasks call this before pushing anything to
  Square.
- Produces: `coaster_manifest.ManifestError` — exception class raised by `load_manifest` on structural
  problems (missing file, missing required column).

- [ ] **Step 1: Write the failing tests**

Create `scripts/test_coaster_manifest.py`:

```python
import os
import tempfile
import unittest

from coaster_manifest import load_manifest, validate_manifest, ManifestError

HEADER = "name,sku_code,shapes,source_file,price_usd,status\n"


def write_csv(tmpdir, content):
    path = os.path.join(tmpdir, "manifest.csv")
    with open(path, "w", newline="") as fh:
        fh.write(content)
    return path


class LoadManifestTests(unittest.TestCase):
    def test_loads_rows_with_expected_types(self):
        with tempfile.TemporaryDirectory() as tmp:
            path = write_csv(
                tmp,
                HEADER
                + "Octagon Greek-Key Maze,GRK,Round,CoasterSet2_design1.lbrn2,9.00,ready\n",
            )
            rows = load_manifest(path)
            self.assertEqual(len(rows), 1)
            row = rows[0]
            self.assertEqual(row["name"], "Octagon Greek-Key Maze")
            self.assertEqual(row["sku_code"], "GRK")
            self.assertEqual(row["shapes"], ["Round"])
            self.assertEqual(row["source_file"], "CoasterSet2_design1.lbrn2")
            self.assertEqual(row["price_usd"], 9.00)
            self.assertEqual(row["status"], "ready")

    def test_splits_multiple_shapes_on_semicolon(self):
        with tempfile.TemporaryDirectory() as tmp:
            path = write_csv(
                tmp,
                HEADER + "Diamond Lattice,LAT,Round;Square,geo-2.lbrn2,9.00,ready\n",
            )
            rows = load_manifest(path)
            self.assertEqual(rows[0]["shapes"], ["Round", "Square"])

    def test_missing_file_raises_manifest_error(self):
        with self.assertRaises(ManifestError):
            load_manifest("/nonexistent/path/manifest.csv")

    def test_missing_required_column_raises_manifest_error(self):
        with tempfile.TemporaryDirectory() as tmp:
            path = write_csv(tmp, "name,sku_code\nFoo,ABC\n")
            with self.assertRaises(ManifestError):
                load_manifest(path)


class ValidateManifestTests(unittest.TestCase):
    def _valid_row(self, **overrides):
        row = {
            "name": "Octagon Greek-Key Maze",
            "sku_code": "GRK",
            "shapes": ["Round"],
            "source_file": "CoasterSet2_design1.lbrn2",
            "price_usd": 9.00,
            "status": "ready",
        }
        row.update(overrides)
        return row

    def test_valid_rows_produce_no_errors(self):
        errors = validate_manifest([self._valid_row()])
        self.assertEqual(errors, [])

    def test_duplicate_sku_code_is_an_error(self):
        rows = [self._valid_row(), self._valid_row(name="Diamond Lattice")]
        errors = validate_manifest(rows)
        self.assertTrue(any("duplicate" in e.lower() and "GRK" in e for e in errors))

    def test_bad_shape_value_is_an_error(self):
        rows = [self._valid_row(shapes=["Hexagon"])]
        errors = validate_manifest(rows)
        self.assertTrue(any("shape" in e.lower() for e in errors))

    def test_non_positive_price_is_an_error_when_ready(self):
        rows = [self._valid_row(price_usd=0.0)]
        errors = validate_manifest(rows)
        self.assertTrue(any("price" in e.lower() for e in errors))

    def test_draft_status_skips_price_and_shape_checks(self):
        rows = [self._valid_row(price_usd=0.0, shapes=[], status="draft")]
        errors = validate_manifest(rows)
        self.assertEqual(errors, [])

    def test_unknown_status_is_an_error(self):
        rows = [self._valid_row(status="maybe")]
        errors = validate_manifest(rows)
        self.assertTrue(any("status" in e.lower() for e in errors))


if __name__ == "__main__":
    unittest.main()
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `cd scripts && python3 -m unittest test_coaster_manifest -v`
Expected: `ModuleNotFoundError: No module named 'coaster_manifest'` (or `ImportError`) for every test.

- [ ] **Step 3: Write the implementation**

Create `scripts/coaster_manifest.py`:

```python
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
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `cd scripts && python3 -m unittest test_coaster_manifest -v`
Expected: all 10 tests PASS.

- [ ] **Step 5: Create the manifest template**

Create `content/coaster-designs.csv`:

```csv
name,sku_code,shapes,source_file,price_usd,status
Octagon Greek-Key Maze,GRK,Round,CoasterSet2_design1.lbrn2,9.00,draft
Diamond Lattice,LAT,Round;Square,CoasterSet2_design4.lbrn2,9.00,draft
```

These two rows are placeholders marked `draft` (not `ready`) — real prices and confirmed source
files come from Bradley's own split-and-verify pass in LightBurn (spec §5 step 1). Flip a row to
`ready` only once its price is set and its source file is a confirmed standalone `.lbrn2`.

- [ ] **Step 6: Commit**

```bash
git add content/coaster-designs.csv scripts/coaster_manifest.py scripts/test_coaster_manifest.py
git commit -m "feat: add coaster design manifest schema, loader, and validator

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>"
```

---

## Task 2: Square catalog object builder (pure functions, no live network)

**Files:**
- Create: `scripts/square_push_coaster_designs.py`
- Test: `scripts/test_square_push_coaster_designs.py`

**Interfaces:**
- Consumes: `coaster_manifest.load_manifest`, `coaster_manifest.validate_manifest` (Task 1).
- Produces: `square_push_coaster_designs.build_option_value_object(row: dict, option_id: str) ->
  dict` — one `ITEM_OPTION_VAL` object per manifest row.
- Produces: `square_push_coaster_designs.build_variation_objects(row: dict, item_id: str, option_id:
  str, option_value_ids: dict[str, str]) -> list[dict]` — one `ITEM_VARIATION` object per shape in
  the row (so a two-shape row produces two variations, SKU-suffixed per shape).
- Produces: `square_push_coaster_designs.sku_for(row: dict, shape: str) -> str` — e.g.
  `sku_for(row, "Round")` with `row["sku_code"] == "GRK"` returns `"CAD-COA-0004-GRK"` (the numeric
  segment is the existing Engraved Slate Coaster parent SKU number, passed in via `--parent-sku`; see
  Step 3 below for how the CLI wires this).
- Produces: `square_push_coaster_designs.variation_label(row: dict, shape: str) -> str` — e.g.
  `"Octagon Greek-Key Maze — Round"`, or just `"Octagon Greek-Key Maze"` when the row has exactly one
  shape (spec §3: a single-shape design doesn't need the shape in its label).

- [ ] **Step 1: Write the failing tests**

Create `scripts/test_square_push_coaster_designs.py`:

```python
import unittest

from square_push_coaster_designs import (
    build_option_value_object,
    build_variation_objects,
    sku_for,
    variation_label,
)

ROW_ONE_SHAPE = {
    "name": "Octagon Greek-Key Maze",
    "sku_code": "GRK",
    "shapes": ["Round"],
    "source_file": "CoasterSet2_design1.lbrn2",
    "price_usd": 9.00,
    "status": "ready",
}

ROW_TWO_SHAPES = {
    "name": "Diamond Lattice",
    "sku_code": "LAT",
    "shapes": ["Round", "Square"],
    "source_file": "CoasterSet2_design4.lbrn2",
    "price_usd": 9.00,
    "status": "ready",
}


class SkuForTests(unittest.TestCase):
    def test_single_shape_sku(self):
        self.assertEqual(sku_for(ROW_ONE_SHAPE, "Round", parent_sku_number="0004"),
                          "CAD-COA-0004-GRK")

    def test_two_shape_row_gets_distinct_skus(self):
        round_sku = sku_for(ROW_TWO_SHAPES, "Round", parent_sku_number="0004")
        square_sku = sku_for(ROW_TWO_SHAPES, "Square", parent_sku_number="0004")
        self.assertNotEqual(round_sku, square_sku)
        self.assertTrue(round_sku.endswith("-LAT-RND"))
        self.assertTrue(square_sku.endswith("-LAT-SQR"))


class VariationLabelTests(unittest.TestCase):
    def test_single_shape_label_has_no_shape_suffix(self):
        self.assertEqual(variation_label(ROW_ONE_SHAPE, "Round"), "Octagon Greek-Key Maze")

    def test_multi_shape_label_includes_shape(self):
        self.assertEqual(variation_label(ROW_TWO_SHAPES, "Round"), "Diamond Lattice — Round")
        self.assertEqual(variation_label(ROW_TWO_SHAPES, "Square"), "Diamond Lattice — Square")


class BuildOptionValueObjectTests(unittest.TestCase):
    def test_builds_expected_shape(self):
        obj = build_option_value_object(ROW_ONE_SHAPE, "Round", option_id="#opt_design")
        self.assertEqual(obj["type"], "ITEM_OPTION_VAL")
        self.assertEqual(obj["item_option_value_data"]["item_option_id"], "#opt_design")
        self.assertEqual(obj["item_option_value_data"]["name"], "Octagon Greek-Key Maze")
        self.assertTrue(obj["id"].startswith("#optval_"))

    def test_two_shapes_get_distinct_option_values(self):
        round_obj = build_option_value_object(ROW_TWO_SHAPES, "Round", option_id="#opt_design")
        square_obj = build_option_value_object(ROW_TWO_SHAPES, "Square", option_id="#opt_design")
        self.assertNotEqual(round_obj["id"], square_obj["id"])
        self.assertEqual(round_obj["item_option_value_data"]["name"], "Diamond Lattice — Round")


class BuildVariationObjectsTests(unittest.TestCase):
    def test_single_shape_row_produces_one_variation(self):
        variations = build_variation_objects(
            ROW_ONE_SHAPE, item_id="#item_coaster", option_id="#opt_design",
            option_value_ids={"Round": "#optval_grk_round"}, parent_sku_number="0004",
        )
        self.assertEqual(len(variations), 1)
        v = variations[0]
        self.assertEqual(v["type"], "ITEM_VARIATION")
        data = v["item_variation_data"]
        self.assertEqual(data["item_id"], "#item_coaster")
        self.assertEqual(data["sku"], "CAD-COA-0004-GRK")
        self.assertEqual(data["price_money"], {"amount": 900, "currency": "USD"})
        self.assertEqual(
            data["item_option_values"],
            [{"item_option_id": "#opt_design", "item_option_value_id": "#optval_grk_round"}],
        )

    def test_two_shape_row_produces_two_variations(self):
        variations = build_variation_objects(
            ROW_TWO_SHAPES, item_id="#item_coaster", option_id="#opt_design",
            option_value_ids={"Round": "#optval_lat_round", "Square": "#optval_lat_square"},
            parent_sku_number="0004",
        )
        self.assertEqual(len(variations), 2)
        skus = {v["item_variation_data"]["sku"] for v in variations}
        self.assertEqual(skus, {"CAD-COA-0004-LAT-RND", "CAD-COA-0004-LAT-SQR"})


if __name__ == "__main__":
    unittest.main()
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `cd scripts && python3 -m unittest test_square_push_coaster_designs -v`
Expected: `ModuleNotFoundError` for every test.

- [ ] **Step 3: Write the implementation**

Create `scripts/square_push_coaster_designs.py`:

```python
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
  * Every write uses sparse_update: true.

QUICK START
  export SQUARE_ACCESS_TOKEN='<sandbox access token>'
  python3 square_push_coaster_designs.py --dry-run \\
      --parent-item-id <existing coaster ITEM id> --parent-sku-number 0004
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

from coaster_manifest import load_manifest, validate_manifest

BASE = {
    "sandbox": "https://connect.squareupsandbox.com",
    "production": "https://connect.squareup.com",
}
SQUARE_VERSION = os.environ.get("SQUARE_VERSION", "2025-04-16")
SHAPE_CODE = {"Round": "RND", "Square": "SQR"}


def sku_for(row, shape, parent_sku_number):
    """CAD-COA-<parent_sku_number>-<design code>[-<shape code>].
    The shape suffix is only added when the row offers more than one
    shape -- a single-shape design's SKU has no shape code."""
    base = f"CAD-COA-{parent_sku_number}-{row['sku_code']}"
    if len(row["shapes"]) > 1:
        return f"{base}-{SHAPE_CODE[shape]}"
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
    ap.add_argument("--manifest", default="../content/coaster-designs.csv")
    ap.add_argument("--env", choices=list(BASE), default="sandbox")
    ap.add_argument("--parent-item-id", required=True,
                     help="Square ITEM id of the existing coaster parent (e.g. Engraved Slate Coaster)")
    ap.add_argument("--parent-sku-number", required=True,
                     help="the ####  segment of the parent's own SKU, e.g. '0004' for CAD-COA-0004")
    ap.add_argument("--design-option-id", default=None,
                     help="existing 'Design' ITEM_OPTION id, if one was already created; omit to create one")
    ap.add_argument("--dry-run", action="store_true")
    ap.add_argument("--yes-really-push-production", action="store_true", help=argparse.SUPPRESS)
    args = ap.parse_args()

    if args.env == "production" and not args.yes_really_push_production:
        sys.exit("ERROR: Refusing to push to PRODUCTION without --yes-really-push-production.")

    rows = load_manifest(args.manifest)
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

    option_id = args.design_option_id or "#opt_design"
    objects = []
    if not args.design_option_id:
        objects.append({
            "type": "ITEM_OPTION",
            "id": option_id,
            "item_option_data": {"name": "Design", "display_name": "Design"},
        })

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
        for shape in row["shapes"]:
            print(f"  {sku_for(row, shape, args.parent_sku_number):24s} "
                  f"{variation_label(row, shape):38s} ${row['price_usd']:.2f}")

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
                  data=json.dumps({**body, "sparse_update": True}), timeout=120),
        "batch-upsert catalog",
    )
    result = resp.json()
    print(f"\nPushed {len(objects)} object(s).")
    for m in result.get("id_mappings", []):
        print(f"  {m.get('client_object_id')} -> {m.get('object_id')}")


if __name__ == "__main__":
    main()
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `cd scripts && python3 -m unittest test_square_push_coaster_designs -v`
Expected: all 8 tests PASS.

- [ ] **Step 5: Verify the CLI's dry-run path end to end**

Run:
```bash
cd scripts
python3 - <<'PY'
import csv
with open("../content/coaster-designs.csv") as f:
    print(f.read())
PY
```
Then, treating the two `draft` template rows as if `ready` just to prove the CLI wiring works,
run a throwaway dry-run against a copy with one row flipped to `ready`:
```bash
cd scripts
python3 -c "
rows = open('../content/coaster-designs.csv').read().replace(',draft', ',ready', 1)
open('/tmp/manifest_dryrun_test.csv', 'w').write(rows)
"
python3 square_push_coaster_designs.py --dry-run \
    --manifest /tmp/manifest_dryrun_test.csv \
    --parent-item-id FAKE_ITEM_ID --parent-sku-number 0004
rm /tmp/manifest_dryrun_test.csv
```
Expected: prints the one `ready` row's SKU/label/price, then `(dry run -- nothing was written to
Square)`. No network call is made in `--dry-run` mode, so `FAKE_ITEM_ID` is fine here.

- [ ] **Step 6: Commit**

```bash
git add scripts/square_push_coaster_designs.py scripts/test_square_push_coaster_designs.py
git commit -m "feat: add Square catalog push script for coaster designs

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>"
```

---

## Task 3: Resolve each manifest row's existing PNG thumbnail

**Files:**
- Modify: `scripts/coaster_manifest.py`
- Modify: `scripts/test_coaster_manifest.py`

**Interfaces:**
- Consumes: a manifest row dict from `load_manifest` (Task 1).
- Produces: `coaster_manifest.resolve_thumbnail(row: dict, search_dirs: list[str]) -> str | None` —
  the first existing file path found matching the row's `source_file` stem with a `.png` extension
  across `search_dirs`, or `None` if not found in any of them. Bradley confirmed a PNG already exists
  for every template, so this never generates one — it only locates the one that's already there.

- [ ] **Step 1: Write the failing tests**

Append to `scripts/test_coaster_manifest.py` (add the import and the new test class):

```python
# add to the existing imports line:
from coaster_manifest import load_manifest, validate_manifest, resolve_thumbnail, ManifestError


class ResolveThumbnailTests(unittest.TestCase):
    def test_finds_matching_png_in_first_search_dir(self):
        with tempfile.TemporaryDirectory() as tmp:
            dir_a = os.path.join(tmp, "a")
            dir_b = os.path.join(tmp, "b")
            os.makedirs(dir_a)
            os.makedirs(dir_b)
            open(os.path.join(dir_a, "CoasterSet2_design1.png"), "w").close()
            row = {"source_file": "CoasterSet2_design1.lbrn2"}
            found = resolve_thumbnail(row, [dir_a, dir_b])
            self.assertEqual(found, os.path.join(dir_a, "CoasterSet2_design1.png"))

    def test_falls_back_to_second_search_dir(self):
        with tempfile.TemporaryDirectory() as tmp:
            dir_a = os.path.join(tmp, "a")
            dir_b = os.path.join(tmp, "b")
            os.makedirs(dir_a)
            os.makedirs(dir_b)
            open(os.path.join(dir_b, "geo-coaster.png"), "w").close()
            row = {"source_file": "geo-coaster.lbrn2"}
            found = resolve_thumbnail(row, [dir_a, dir_b])
            self.assertEqual(found, os.path.join(dir_b, "geo-coaster.png"))

    def test_returns_none_when_not_found_anywhere(self):
        with tempfile.TemporaryDirectory() as tmp:
            row = {"source_file": "nonexistent-design.lbrn2"}
            self.assertIsNone(resolve_thumbnail(row, [tmp]))
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `cd scripts && python3 -m unittest test_coaster_manifest -v`
Expected: the three new `ResolveThumbnailTests` FAIL with `ImportError` (`resolve_thumbnail` doesn't
exist yet); the Task 1 tests still PASS.

- [ ] **Step 3: Write the implementation**

Append to `scripts/coaster_manifest.py`:

```python
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
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `cd scripts && python3 -m unittest test_coaster_manifest -v`
Expected: all tests (Task 1's 10 + Task 3's 3 = 13) PASS.

- [ ] **Step 5: Wire it into the push script's dry-run output**

In `scripts/square_push_coaster_designs.py`, add a `--thumbnail-dir` argument (repeatable) and print
a thumbnail-found/missing line per row in the dry-run summary, so a missing PNG is caught before any
Square write. Add the import:

```python
from coaster_manifest import load_manifest, validate_manifest, resolve_thumbnail
```

Add the argument in `main()`, alongside the other `ap.add_argument(...)` calls:

```python
    ap.add_argument("--thumbnail-dir", action="append", default=[],
                     help="directory to search for each design's existing PNG (repeatable)")
```

And extend the per-row print loop (the `for row in ready_rows: for shape in row["shapes"]:` block) to
also resolve and report the thumbnail once per row (not per shape) — replace that loop with:

```python
    for row in ready_rows:
        thumb = resolve_thumbnail(row, args.thumbnail_dir) if args.thumbnail_dir else None
        thumb_note = thumb if thumb else "NO THUMBNAIL FOUND" if args.thumbnail_dir else "(no --thumbnail-dir given)"
        for shape in row["shapes"]:
            print(f"  {sku_for(row, shape, args.parent_sku_number):24s} "
                  f"{variation_label(row, shape):38s} ${row['price_usd']:.2f}")
        print(f"      thumbnail: {thumb_note}")
```

- [ ] **Step 6: Run the full test suite once more**

Run: `cd scripts && python3 -m unittest test_coaster_manifest test_square_push_coaster_designs -v`
Expected: all 21 tests PASS (13 in `test_coaster_manifest` + 8 in
`test_square_push_coaster_designs` — the push-script tests are unaffected since `--thumbnail-dir`
defaults to an empty list and the row-building functions weren't touched).

- [ ] **Step 7: Commit**

```bash
git add scripts/coaster_manifest.py scripts/test_coaster_manifest.py scripts/square_push_coaster_designs.py
git commit -m "feat: resolve each coaster design's existing PNG thumbnail

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>"
```

---

## Task 4: Minimum-order-quantity WPCode snippet

**Files:**
- Create: `snippets/wpcode-cornercad-coaster-min-qty.php`

**Interfaces:**
- Produces (PHP, global scope once deployed): `cornercad_coaster_min_qty()` — returns `int`, the
  current floor (4). Single source of truth so a future tiered-discount snippet can read the same
  constant rather than hardcoding `4` again.
- Produces: `cornercad_coaster_is_min_qty_product( $product )` — `bool`, true when the given
  `WC_Product` (or its parent, if it's a variation) has a non-empty `design` attribute. This is the
  gate spec §3/§9 calls for: any current or future coaster-line parent product picks up the rule
  automatically by having a "Design" attribute, with no snippet edit needed when a new material ships.

- [ ] **Step 1: Write the PHP file**

Create `snippets/wpcode-cornercad-coaster-min-qty.php`:

```php
<?php
/**
 * CornerCAD — enforce a minimum order quantity of 4 on laser-engraved
 * coaster products.
 *
 * Scope: any product (or product variation) that carries a "Design"
 * attribute -- this is exactly the coaster-line parent products (Engraved
 * Slate Coaster today, a future Engraved Birch Coaster, etc.), per
 * docs/superpowers/specs/2026-09-05-laser-coaster-launch-design.md §3 and
 * §7. Deliberately NOT scoped by product ID or SKU prefix, so a new
 * material's parent product picks up the rule the moment it has a Design
 * attribute -- no snippet edit needed when Bradley ships a new material.
 *
 * Does NOT apply to the FDM (3D-printed) coaster products (Cova Coasters,
 * Hex Coaster Set) -- they have no Design attribute, so they're untouched.
 *
 * Two enforcement points, both required -- the quantity-input filter alone
 * is a UI nicety a customer can bypass by editing the request directly:
 *   1. woocommerce_quantity_input_args -- sets the visible minimum in the
 *      quantity stepper on the product page and in the cart.
 *   2. woocommerce_add_to_cart_validation /
 *      woocommerce_update_cart_validation -- actually blocks a below-floor
 *      quantity from being added or updated in the cart, with a clear
 *      notice.
 *
 * Mirrored in git at snippets/wpcode-cornercad-coaster-min-qty.php
 *
 * Manual verification (no PHPUnit harness in this repo -- see the project
 * CLAUDE.md's "no local php binary" note):
 *   wp eval 'var_dump(function_exists("cornercad_coaster_min_qty"));' --user=1
 *   wp eval 'echo cornercad_coaster_min_qty();' --user=1   # expect: 4
 *   wp eval 'var_dump(cornercad_coaster_is_min_qty_product(wc_get_product(4787)));' --user=1
 *     # expect: bool(true) once the Engraved Slate Coaster (id 4787) has a
 *     # Design attribute; bool(false) before that attribute exists.
 */

if ( ! function_exists( 'cornercad_coaster_min_qty' ) ) {

	function cornercad_coaster_min_qty() {
		return 4;
	}

	/**
	 * True when $product (or its parent, for a variation) has a non-empty
	 * "design" attribute.
	 *
	 * @param WC_Product $product
	 * @return bool
	 */
	function cornercad_coaster_is_min_qty_product( $product ) {
		if ( ! $product instanceof WC_Product ) {
			return false;
		}
		$target = $product;
		if ( $product->is_type( 'variation' ) ) {
			$parent_id = $product->get_parent_id();
			$target    = $parent_id ? wc_get_product( $parent_id ) : null;
			if ( ! $target ) {
				return false;
			}
		}
		$design = $target->get_attribute( 'design' );
		return '' !== trim( (string) $design );
	}

	add_filter( 'woocommerce_quantity_input_args', function( $args, $product ) {
		if ( cornercad_coaster_is_min_qty_product( $product ) ) {
			$floor              = cornercad_coaster_min_qty();
			$args['min_value']  = $floor;
			if ( (int) $args['input_value'] < $floor ) {
				$args['input_value'] = $floor;
			}
		}
		return $args;
	}, 10, 2 );

	add_filter( 'woocommerce_add_to_cart_validation', function( $passed, $product_id, $quantity, $variation_id = 0 ) {
		$id      = $variation_id ? $variation_id : $product_id;
		$product = wc_get_product( $id );
		if ( $product && cornercad_coaster_is_min_qty_product( $product ) ) {
			$floor = cornercad_coaster_min_qty();
			if ( (int) $quantity < $floor ) {
				wc_add_notice(
					sprintf( 'This coaster design has a minimum order quantity of %d.', $floor ),
					'error'
				);
				return false;
			}
		}
		return $passed;
	}, 10, 4 );

	add_filter( 'woocommerce_update_cart_validation', function( $passed, $cart_item_key, $values, $quantity ) {
		$product = $values['data'];
		if ( $product && cornercad_coaster_is_min_qty_product( $product ) ) {
			$floor = cornercad_coaster_min_qty();
			if ( (int) $quantity < $floor ) {
				wc_add_notice(
					sprintf( 'This coaster design has a minimum order quantity of %d.', $floor ),
					'error'
				);
				return false;
			}
		}
		return $passed;
	}, 10, 4 );
}
```

- [ ] **Step 2: Local structural sanity check**

There is no local `php` binary in this environment (confirmed: `which php` finds nothing), so full
syntax linting happens at deployment time over SSH (see the runbook below). Do a quick local
structural check instead — brace and parenthesis balance, and that the file starts with `<?php` with
no stray Markdown formatting (the exact failure mode documented in the
`feedback_mcp-create-post-mangles-php` memory):

```bash
python3 -c "
src = open('snippets/wpcode-cornercad-coaster-min-qty.php').read()
assert src.startswith('<?php'), 'must start with <?php'
assert src.count('{') == src.count('}'), 'unbalanced braces'
assert src.count('(') == src.count(')'), 'unbalanced parens'
assert '<p>' not in src and '&lt;' not in src, 'looks Markdown-mangled'
print('structural check OK')
"
```

Expected: `structural check OK`.

- [ ] **Step 3: Commit**

```bash
git add snippets/wpcode-cornercad-coaster-min-qty.php
git commit -m "feat: add coaster minimum-order-quantity WPCode snippet

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>"
```

---

## Task 5: Document the pipeline in the project CLAUDE.md

**Files:**
- Modify: `CLAUDE.md`

- [ ] **Step 1: Add a bullet to the "Product copy pipeline" paragraph**

Find the existing paragraph in `CLAUDE.md` that begins `**Product copy pipeline.**` (in the "Current
state" section) and add one sentence after it:

```markdown
**Laser coaster design pipeline** (spec: `docs/superpowers/specs/2026-09-05-laser-coaster-launch-design.md`).
Designs are tracked in `content/coaster-designs.csv` (schema/loader: `scripts/coaster_manifest.py`)
and pushed to Square as variations of the coaster parent product via
`scripts/square_push_coaster_designs.py`, then reach the site on the next Square→Woo sync — the same
shape as the description pipeline above, applied to design variations instead of description text.
Minimum order quantity (4, for any coaster product with a Design attribute) is enforced by
`snippets/wpcode-cornercad-coaster-min-qty.php`.
```

- [ ] **Step 2: Verify the addition renders sensibly**

Run: `grep -n "Laser coaster design pipeline" CLAUDE.md`
Expected: one match, in the "Current state (verified 2026-08-24)" section near the existing "Product
copy pipeline" bullet.

- [ ] **Step 3: Commit**

```bash
git add CLAUDE.md
git commit -m "docs: cross-reference the laser coaster pipeline from CLAUDE.md

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>"
```

---

## Deployment Runbook (manual — NOT part of automated task execution)

Everything above produces local files and runs local verification only, per this plan's Global
Constraints. The steps below touch the live WordPress site and/or the live Square catalog and each
one requires showing Bradley the exact tool, target, and change and getting an explicit yes first —
do not run these as part of an unattended task-executor pass.

1. **Deploy the WPCode snippet.** Create the `wpcode` post via the `cornercad-com` MCP (or WP-CLI),
   then immediately verify and fix its taxonomy terms — the auto-cross-sell snippet needed both of
   these corrected after creation:
   - `wpcode_type` must be `php` (WP-CLI-created posts default to `html`, which silently never
     executes).
   - `wpcode_location` must be `everywhere` (not `site_wide_header`, which is front-end-`<head>`-only
     and won't fire for a cart/checkout hook).
   Then rebuild the snippet cache (`WPCode_Snippet_Cache::delete_cache()` +
   `cache_all_loaded_snippets()` via `wp eval`) and run the manual verification commands documented in
   the snippet's own PHPDoc header (Task 4). Hash the file's content before and after the MCP write
   and compare, per the `feedback_mcp-create-post-mangles-php` memory — the MCP's Markdown conversion
   has silently mangled PHP before.

2. **Finish Bradley's own LightBurn split-and-verify pass** for the designs he wants in the first
   batch (spec §5 step 1) — this plan's tooling doesn't do this for him.

3. **Fill in real manifest rows** in `content/coaster-designs.csv`, flipping each to `status: ready`
   once its price and confirmed standalone source file are set. Run
   `python3 scripts/coaster_manifest.py`-backed validation (or just `validate_manifest` interactively)
   before pushing.

4. **Dry-run against sandbox first**, then push for real: get the existing Engraved Slate Coaster's
   Square `ITEM` id and its parent SKU number, then run
   ```bash
   export SQUARE_ACCESS_TOKEN='<sandbox token>'
   python3 scripts/square_push_coaster_designs.py --dry-run \
       --parent-item-id <ITEM id> --parent-sku-number 0004 \
       --thumbnail-dir "/Users/bradleycorner/Documents/Laser/Commercial/Coasters"
   ```
   Confirm the printed SKUs/labels/thumbnails look right, then re-run without `--dry-run` against
   `--env production` (only with `--yes-really-push-production`, and only after Bradley says go).

5. **Run the Square→Woo sync** (WP-CLI, `--user=1` mandatory) and spot-check the live product page —
   variation dropdown shows every pushed design, images swap correctly, price and min-qty-4 behave as
   the snippet from Task 4 intends.

---

## Self-Review Notes

- **Spec coverage:** §3 (parent-per-material, Design attribute, shape-in-label) → Task 2's
  `variation_label`/`sku_for`. §5 (manifest-driven pipeline) → Tasks 1–3. §6 (SKU pattern) → Task 2's
  `sku_for`. §7 (min-qty-4, gated so it composes with a future bulk-discount tier) → Task 4. §8 (FDM
  stays separate, cross-sell deferred) → no code needed yet, correctly out of scope here. §9 open
  items 1 (design list), 3 (Birch calibration), 4 (Military/Clan sourcing), 5 (stand/bundle) are
  Bradley's own work, correctly left out of this plan; open item 2 (min-qty mechanism) → Task 4; open
  item 6 (the push script itself) → Task 2.
- **Placeholder scan:** no TBD/TODO; every step has runnable code or exact commands.
- **Type consistency:** `load_manifest` row shape (`name`, `sku_code`, `shapes: list[str]`,
  `source_file`, `price_usd: float`, `status`) is used identically across `validate_manifest`,
  `resolve_thumbnail`, `sku_for`, `variation_label`, `build_option_value_object`, and
  `build_variation_objects`.
