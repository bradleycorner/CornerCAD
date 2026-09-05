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
