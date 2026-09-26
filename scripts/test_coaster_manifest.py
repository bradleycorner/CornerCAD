import os
import tempfile
import unittest

from coaster_manifest import (
    load_manifest,
    validate_manifest,
    resolve_thumbnail,
    ManifestError,
    SHAPE_CODES,
    VALID_SHAPES,
)

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

    def test_malformed_price_parses_as_none_not_a_silent_zero(self):
        with tempfile.TemporaryDirectory() as tmp:
            path = write_csv(
                tmp,
                HEADER + "Typo Price,TYP,Round,typo.lbrn2,9.oo,ready\n",
            )
            rows = load_manifest(path)
            self.assertIsNone(rows[0]["price_usd"])

    def test_blank_price_still_parses_as_zero(self):
        with tempfile.TemporaryDirectory() as tmp:
            path = write_csv(
                tmp,
                HEADER + "No Price Yet,NPY,Round,npy.lbrn2,,draft\n",
            )
            rows = load_manifest(path)
            self.assertEqual(rows[0]["price_usd"], 0.0)


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

    def test_duplicate_shape_within_row_is_an_error(self):
        rows = [self._valid_row(shapes=["Round", "Round"])]
        errors = validate_manifest(rows)
        self.assertTrue(any("duplicate shape" in e.lower() for e in errors))

    def test_too_short_sku_code_is_an_error(self):
        rows = [self._valid_row(sku_code="GR")]
        errors = validate_manifest(rows)
        self.assertTrue(any("sku_code" in e.lower() for e in errors))

    def test_lowercase_sku_code_is_an_error(self):
        rows = [self._valid_row(sku_code="grk")]
        errors = validate_manifest(rows)
        self.assertTrue(any("sku_code" in e.lower() for e in errors))

    def test_empty_name_is_an_error(self):
        rows = [self._valid_row(name="")]
        errors = validate_manifest(rows)
        self.assertTrue(any("name" in e.lower() and "blank" in e.lower() for e in errors))

    def test_two_blank_sku_codes_on_draft_rows_are_not_flagged_as_duplicates(self):
        rows = [
            self._valid_row(name="Placeholder A", sku_code="", status="draft", shapes=[], price_usd=0.0),
            self._valid_row(name="Placeholder B", sku_code="", status="draft", shapes=[], price_usd=0.0),
        ]
        errors = validate_manifest(rows)
        self.assertEqual(errors, [])

    def test_malformed_price_is_an_error_even_on_a_draft_row(self):
        rows = [self._valid_row(price_usd=None, status="draft", shapes=[])]
        errors = validate_manifest(rows)
        self.assertTrue(any("price_usd" in e.lower() and "valid number" in e.lower() for e in errors))

    def test_malformed_price_is_an_error_on_a_ready_row(self):
        rows = [self._valid_row(price_usd=None)]
        errors = validate_manifest(rows)
        self.assertTrue(any("price_usd" in e.lower() and "valid number" in e.lower() for e in errors))

    def test_every_valid_shape_has_a_sku_code(self):
        # Regression guard for the SHAPE_CODES/VALID_SHAPES split that used
        # to live in two files -- a shape that passes this validator but has
        # no entry in SHAPE_CODES crashes square_push_coaster_designs.sku_for
        # with an unhandled KeyError on any 2+-shape row using it.
        self.assertEqual(set(SHAPE_CODES), VALID_SHAPES)


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

    def test_resolves_nested_source_file_subdirectory(self):
        with tempfile.TemporaryDirectory() as tmp:
            dir_a = os.path.join(tmp, "a")
            nested = os.path.join(dir_a, "MandellaCoasters")
            os.makedirs(nested)
            open(os.path.join(nested, "mandella01.png"), "w").close()
            row = {"source_file": "MandellaCoasters/mandella01.lbrn2"}
            found = resolve_thumbnail(row, [dir_a])
            self.assertEqual(found, os.path.join(nested, "mandella01.png"))


if __name__ == "__main__":
    unittest.main()
