/**
 * CornerCAD — keep Square-untracked variations in stock across syncs.
 *
 * THE BUG
 *
 * Every Square sync forced all variations of Wall Clock (4780) and Vexel Clock
 * (4775) to _manage_stock = 'yes' / _stock_status = 'outofstock' with no stock
 * quantity, even though Square's Catalog API reports track_inventory = false on
 * all eight variations. Setting them right by hand worked until the next sync,
 * which reverted them.
 *
 * THE MECHANISM (traced 2026-08-23, all steps verified against the live site)
 *
 * 1. Sync\Product_Import::save_variations() line 1314 writes
 *
 *        update_post_meta( $variation_id, '_manage_stock', 'yes' );
 *        update_post_meta( $variation_id, '_backorders',   'no'  );
 *
 *    unconditionally, for every variation, with no regard for Square's
 *    track_inventory flag. It never writes _stock, so quantity stays empty.
 *    Both sync paths reach this: Manual_Synchronization::square_sor_sync() and
 *    Interval_Polling::update_product_data() each call update_product().
 *
 * 2. The 'outofstock' is applied later, by WooCommerce itself. The next CRUD
 *    save of that variation — Handlers\Product::set_square_item_variation_id()
 *    does one at Product.php:1137 — runs WC_Product::validate_props()
 *    (abstract-wc-product.php:1517), which sees manage_stock on, quantity null,
 *    backorders 'no', and therefore forces stock_status to 'outofstock'.
 *
 * 3. The step that is supposed to undo step 1 never sees these variations.
 *    Both repair paths have the correct track_inventory logic, and both miss:
 *
 *    - Manual sync, Manual_Synchronization::pull_inventory(): it drives off
 *      batch_retrieve_inventory_counts(). Square returns NO counts at all for
 *      untracked variations — verified live, getCounts() is NULL for all eight
 *      — so the guard
 *
 *          if ( ! is_array( $response_data->getCounts() ) ) { ...; return; }
 *
 *      fires and returns BEFORE the get_catalog_objects_tracking_stats() loop
 *      that would have called set_manage_stock( false ). The variations are
 *      marked processed and the repair is skipped entirely.
 *
 *    - Scheduled sync, Interval_Polling::update_inventory_tracking(): it
 *      searches ITEM_VARIATION with begin_time = inventory_last_synced_at.
 *      Nothing about these variations has changed in Square since tracking was
 *      switched off, so the search returns zero objects — verified live — and
 *      there is nothing to repair. update_inventory_counts() then iterates only
 *      objects that HAVE counts, which untracked variations do not.
 *
 * Why the "Engraved Slate Coaster" (4787) came in correct: the IMPORT path uses
 * Product_Import::import_inventory(), which iterates the catalog objects rather
 * than the inventory counts. The plugin's own comment there spells the trap out:
 * "batch_retrieve_inventory_counts doesn't return any catalog objects if they
 * are not tracked. This is why, we instead iterate on $objects." The manual-sync
 * repair path did not get that treatment.
 *
 * THE FIX
 *
 * Re-assert the correct state after every sync job, on the plugin's documented
 * wc_square_products_synced action (fired from Handlers\Sync::record_sync(),
 * @since 2.0.0), which runs once at job completion for BOTH sync paths.
 *
 * Square stays the source of truth: we ask it, through the plugin's own
 * Sync\Helper::get_catalog_objects_tracking_stats(), and apply exactly the
 * branch upstream would have applied — set_manage_stock( false ) and a stock
 * status from the sold_out flag. Variations Square really does track are left
 * completely alone.
 *
 * Corrections go through WC CRUD save(), not update_post_meta(), so that
 * wc_product_meta_lookup is rewritten too. That matters: WC_Product_Variable
 * resolves child stock through the lookup table, so a read-time filter on
 * woocommerce_product_variation_get_manage_stock would NOT have been enough —
 * the parent would still have computed itself out of stock. Each touched parent
 * is then re-synced so its own stock status and price range recompute.
 *
 * Note this is a repair, not a prevention: there is a brief window during the
 * sync where the variation is out of stock. Fixing it at the source would mean
 * patching Product_Import.php line 1314, which a plugin update would overwrite.
 *
 * Mirrored in git at snippets/wpcode-square-untracked-variation-stock.php
 */

if ( ! function_exists( 'cornercad_square_restore_untracked_variation_stock' ) ) {
	/**
	 * Restore in-stock status on variations Square does not track inventory for.
	 *
	 * @param int[] $product_ids Product IDs reported by the finished sync job.
	 * @return void
	 */
	function cornercad_square_restore_untracked_variation_stock( $product_ids ) {

		if ( ! class_exists( '\WooCommerce\Square\Sync\Helper' ) || ! function_exists( 'wc_square' ) ) {
			return;
		}

		$product_ids = array_filter( array_map( 'absint', (array) $product_ids ) );

		if ( empty( $product_ids ) ) {
			return;
		}

		$logger  = function_exists( 'wc_get_logger' ) ? wc_get_logger() : null;
		$context = array( 'source' => 'cornercad-square-stock' );

		// Expand the job's product IDs into candidate variation IDs. The job
		// reports parent products, and in the Interval_Polling inventory steps
		// also bare variation IDs, so handle both. Simple products are skipped:
		// save_variations() is the only thing that misbehaves, and it only ever
		// touches variations.
		$variation_ids = array();

		foreach ( $product_ids as $product_id ) {

			$product = wc_get_product( $product_id );

			if ( ! $product instanceof WC_Product ) {
				continue;
			}

			if ( $product->is_type( 'variation' ) ) {
				$variation_ids[] = $product->get_id();
			} elseif ( $product->is_type( 'variable' ) ) {
				$variation_ids = array_merge( $variation_ids, $product->get_children() );
			}
		}

		$variation_ids = array_unique( array_filter( array_map( 'absint', $variation_ids ) ) );

		if ( empty( $variation_ids ) ) {
			return;
		}

		// Only variations currently managing stock can be wrong in the way this
		// snippet fixes, so that is the whole candidate set. It is naturally
		// self-limiting: anything already corrected has _manage_stock = 'no' and
		// drops out until a sync breaks it again.
		$square_ids_by_variation = array();

		foreach ( $variation_ids as $variation_id ) {

			if ( 'yes' !== get_post_meta( $variation_id, '_manage_stock', true ) ) {
				continue;
			}

			$square_id = get_post_meta( $variation_id, '_square_item_variation_id', true );

			if ( ! empty( $square_id ) ) {
				$square_ids_by_variation[ $variation_id ] = $square_id;
			}
		}

		if ( empty( $square_ids_by_variation ) ) {
			return;
		}

		// Ask Square which of those it actually tracks. Chunked because
		// batch_retrieve_catalog_objects takes a bounded list of IDs.
		$tracking = array();

		foreach ( array_chunk( array_values( $square_ids_by_variation ), 100 ) as $chunk ) {
			try {
				$tracking += (array) \WooCommerce\Square\Sync\Helper::get_catalog_objects_tracking_stats( $chunk );
			} catch ( \Exception $e ) {
				// A sync must never be broken by this correction. Leave the
				// variations as they are and let the next sync try again.
				if ( $logger ) {
					$logger->warning( 'Could not read Square inventory tracking: ' . $e->getMessage(), $context );
				}
				return;
			}
		}

		$corrected  = array();
		$parent_ids = array();

		foreach ( $square_ids_by_variation as $variation_id => $square_id ) {

			if ( ! isset( $tracking[ $square_id ] ) ) {
				continue;
			}

			// Square tracks this one — the plugin's own handling is correct for
			// it, so do not touch it.
			if ( ! empty( $tracking[ $square_id ]['track_inventory'] ) ) {
				continue;
			}

			$variation = wc_get_product( $variation_id );

			if ( ! $variation instanceof WC_Product_Variation ) {
				continue;
			}

			$sold_out = ! empty( $tracking[ $square_id ]['sold_out'] );

			// Same branch Product_Import::import_inventory() takes for an
			// untracked catalog object. Order matters: clear manage_stock first
			// so validate_props() does not re-derive 'outofstock' from the empty
			// quantity on save.
			$variation->set_manage_stock( false );
			$variation->set_stock_status( $sold_out ? 'outofstock' : 'instock' );
			$variation->save();

			$corrected[] = $variation_id;

			$parent_id = $variation->get_parent_id();

			if ( $parent_id ) {
				$parent_ids[ $parent_id ] = $parent_id;
			}
		}

		if ( empty( $corrected ) ) {
			return;
		}

		// Recompute each parent's own stock status, price range and children
		// caches now that the child rows have changed.
		foreach ( $parent_ids as $parent_id ) {
			WC_Product_Variable::sync( $parent_id );
			wc_delete_product_transients( $parent_id );
		}

		if ( $logger ) {
			$logger->info(
				sprintf(
					'Restored in-stock on %d Square-untracked variation(s): %s (parents: %s)',
					count( $corrected ),
					implode( ', ', $corrected ),
					implode( ', ', $parent_ids )
				),
				$context
			);
		}
	}
}

add_action( 'wc_square_products_synced', 'cornercad_square_restore_untracked_variation_stock', 20, 1 );
