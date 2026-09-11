<?php
/**
 * Plugin Name: FCMC Membership Lifecycle
 * Description: Term calculation, membership status, and the cached membership fields on the
 *              WP user. Implements §1 of the 2026-09-09 membership design.
 *
 *              The club year runs June 1 -> May 31 and dues are never prorated: paying late
 *              still expires the following June 1. Status is DERIVED from the paid-through
 *              date, then cached on the user so the roster screen is a fast user query rather
 *              than an order-history scan per row.
 *
 *              Orders remain the payment ledger — nothing here writes money facts. These
 *              fields are a cache, and `fcmc_recompute_member()` rebuilds them from order
 *              history to catch drift (or to absorb an imported roster).
 *
 * @see docs/superpowers/specs/2026-09-09-fcmc-membership-system-design.md
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* -------------------------------------------------------------------------
 * Tunables
 * ---------------------------------------------------------------------- */

/**
 * Month from which a payment counts toward the UPCOMING club year.
 *
 * The board has not confirmed this. The spec says April (4); if they say early
 * renewal opens in May, return 5 from this filter and re-run the recompute —
 * nothing else changes. The data already rules out 6: 19 members paid in May 2026
 * expecting a full year.
 *
 * @return int Month number, 1-12.
 */
function fcmc_early_renewal_cutoff_month() {
	return (int) apply_filters( 'fcmc_early_renewal_cutoff_month', 4 );
}

/**
 * Days after paid-through during which a member is "grace" rather than "lapsed".
 *
 * @return int
 */
function fcmc_grace_days() {
	return (int) apply_filters( 'fcmc_grace_days', 60 );
}

/**
 * Order statuses that count as money actually received.
 *
 * NOTE: `on-hold` is deliberately absent. The offline/cheque gateway parks orders
 * there, and an uncleared cheque is not a paid membership. Card payments via
 * WooPayments land on processing/completed.
 *
 * @return string[]
 */
function fcmc_paid_order_statuses() {
	return (array) apply_filters( 'fcmc_paid_order_statuses', array( 'processing', 'completed' ) );
}

/* -------------------------------------------------------------------------
 * 1. Term calculation — the whole rule, in one function
 * ---------------------------------------------------------------------- */

/**
 * Given the date a payment was taken, return the date the membership runs through.
 *
 * Payments from the cutoff month onward count toward the upcoming club year:
 *   Sep 2 2026 -> Jun 1 2027   (late payment for the current year)
 *   May 15 2026 -> Jun 1 2027  (early renewal — 45% of payments look like this)
 *   Feb 10 2027 -> Jun 1 2027
 *
 * @param DateTimeInterface $paid When the payment was taken.
 * @return DateTimeImmutable Midnight on the June 1 the membership runs through.
 */
function fcmc_paid_through( DateTimeInterface $paid ) {
	$year   = (int) $paid->format( 'Y' );
	$month  = (int) $paid->format( 'n' );
	$expiry = $month >= fcmc_early_renewal_cutoff_month() ? $year + 1 : $year;

	return new DateTimeImmutable( sprintf( '%d-06-01 00:00:00', $expiry ), wp_timezone() );
}

/**
 * Derive membership status from a paid-through date.
 *
 * @param DateTimeInterface|null $paid_through Null means never paid.
 * @param DateTimeInterface|null $now          Defaults to now, injectable for tests.
 * @return string One of: active, grace, lapsed, none.
 */
function fcmc_status_for( $paid_through, $now = null ) {
	if ( ! $paid_through instanceof DateTimeInterface ) {
		return 'none';
	}

	$now = $now instanceof DateTimeInterface
		? $now
		: new DateTimeImmutable( 'now', wp_timezone() );

	if ( $now < $paid_through ) {
		return 'active';
	}

	$grace_ends = ( new DateTimeImmutable( $paid_through->format( 'Y-m-d H:i:s' ), wp_timezone() ) )
		->modify( '+' . fcmc_grace_days() . ' days' );

	return $now < $grace_ends ? 'grace' : 'lapsed';
}

/**
 * Human label for a status key.
 *
 * @param string $status Status key.
 * @return string
 */
function fcmc_status_label( $status ) {
	$labels = array(
		'active' => __( 'Active', 'fcmc' ),
		'grace'  => __( 'In grace', 'fcmc' ),
		'lapsed' => __( 'Lapsed', 'fcmc' ),
		'none'   => __( 'Never paid', 'fcmc' ),
	);

	return $labels[ $status ] ?? $status;
}

/* -------------------------------------------------------------------------
 * 2. Reading the cached fields
 * ---------------------------------------------------------------------- */

/**
 * Paid-through date cached on a user, or null.
 *
 * @param int $user_id User ID.
 * @return DateTimeImmutable|null
 */
function fcmc_get_paid_through( $user_id ) {
	$raw = get_user_meta( $user_id, 'fcmc_paid_through', true );
	if ( ! $raw ) {
		return null;
	}

	$date = DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', $raw . ' 00:00:00', wp_timezone() );

	return $date ?: null;
}

/**
 * The baseline paid-through date for a user: a membership asserted without a
 * WooCommerce order behind it — set by the roster import, or by an officer
 * correcting a record by hand. Treated as a FLOOR, never a cache: orders can
 * extend it, nothing erases it. See the roster-import design, §5 and §5a.
 *
 * @param int $user_id User ID.
 * @return DateTimeImmutable|null
 */
function fcmc_get_baseline_paid_through( $user_id ) {
	$raw = get_user_meta( $user_id, 'fcmc_paid_through_manual', true );
	if ( ! $raw ) {
		return null;
	}

	$date = DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', $raw . ' 00:00:00', wp_timezone() );

	return $date ?: null;
}

/**
 * Cached status for a user. Recomputed nightly; safe to read on every row.
 *
 * @param int $user_id User ID.
 * @return string
 */
function fcmc_get_status( $user_id ) {
	$cached = get_user_meta( $user_id, 'fcmc_status', true );

	return $cached ? $cached : 'none';
}

/* -------------------------------------------------------------------------
 * 3. Recompute from order history — the source of truth
 * ---------------------------------------------------------------------- */

/**
 * Every paid membership order for a user, oldest first.
 *
 * @param int $user_id User ID.
 * @return WC_Order[]
 */
function fcmc_member_paid_orders( $user_id ) {
	if ( ! function_exists( 'wc_get_orders' ) ) {
		return array();
	}

	$orders = wc_get_orders(
		array(
			'customer_id' => $user_id,
			'status'      => fcmc_paid_order_statuses(),
			'limit'       => -1,
			'orderby'     => 'date',
			'order'       => 'ASC',
		)
	);

	// Only orders that actually contain the membership product count. A member who
	// only ever bought something else is not a member.
	return array_values(
		array_filter(
			$orders,
			function ( $order ) {
				foreach ( $order->get_items() as $item ) {
					if ( (int) $item->get_product_id() === FCMC_MEMBERSHIP_PRODUCT_ID ) {
						return true;
					}
				}
				return false;
			}
		)
	);
}

/**
 * Rebuild a user's cached membership fields from their order history.
 *
 * A lapsed member who pays again needs no special handling — the new payment simply
 * produces a later paid-through date.
 *
 * @param int $user_id User ID.
 * @return array{paid_through:?string,status:string,member_since:?string} What was written.
 */
function fcmc_recompute_member( $user_id ) {
	$orders   = fcmc_member_paid_orders( $user_id );
	$baseline = fcmc_get_baseline_paid_through( $user_id );

	$from_orders = null;
	$first_paid  = null;
	if ( ! empty( $orders ) ) {
		$from_orders = fcmc_paid_through( end( $orders )->get_date_created() );
		$first_paid  = $orders[0]->get_date_created()->format( 'Y-m-d' );
	}

	// The floor rule: an order can extend the baseline, never erase it.
	$effective = $from_orders;
	if ( $baseline && ( ! $effective || $baseline > $effective ) ) {
		$effective = $baseline;
	}

	if ( ! $effective ) {
		delete_user_meta( $user_id, 'fcmc_paid_through' );
		update_user_meta( $user_id, 'fcmc_status', 'none' );

		return array(
			'paid_through' => null,
			'status'       => 'none',
			'member_since' => get_user_meta( $user_id, 'fcmc_member_since', true ) ?: null,
		);
	}

	$status = fcmc_status_for( $effective );
	update_user_meta( $user_id, 'fcmc_paid_through', $effective->format( 'Y-m-d' ) );
	update_user_meta( $user_id, 'fcmc_status', $status );

	// member_since is the first payment ever and must never move backwards on
	// recompute — an officer may have set it by hand when importing the old roster.
	$existing_since = get_user_meta( $user_id, 'fcmc_member_since', true );
	if ( $first_paid && ( ! $existing_since || $first_paid < $existing_since ) ) {
		update_user_meta( $user_id, 'fcmc_member_since', $first_paid );
		$existing_since = $first_paid;
	}

	return array(
		'paid_through' => $effective->format( 'Y-m-d' ),
		'status'       => $status,
		'member_since' => $existing_since ?: null,
	);
}

/**
 * Refresh only the derived status for one user, from the already-cached date.
 *
 * Cheap — no order query. This is what the nightly job needs, because what changes
 * overnight is the date, not the payment history.
 *
 * @param int $user_id User ID.
 * @return string The status written.
 */
function fcmc_refresh_status( $user_id ) {
	$status = fcmc_status_for( fcmc_get_paid_through( $user_id ) );
	update_user_meta( $user_id, 'fcmc_status', $status );

	return $status;
}

/**
 * Recompute every member from order history. Use after an import or to catch drift.
 *
 * @return int Number of users processed.
 */
function fcmc_recompute_all() {
	$count = 0;
	foreach ( get_users( array( 'fields' => 'ID' ) ) as $user_id ) {
		fcmc_recompute_member( (int) $user_id );
		$count++;
	}

	return $count;
}

/* -------------------------------------------------------------------------
 * 4. Hooks — keep the cache honest
 * ---------------------------------------------------------------------- */

/**
 * Recompute when an order reaches a paid status.
 */
foreach ( array( 'processing', 'completed' ) as $fcmc_paid_status ) {
	add_action(
		'woocommerce_order_status_' . $fcmc_paid_status,
		function ( $order_id ) {
			$order = wc_get_order( $order_id );
			if ( ! $order ) {
				return;
			}
			$user_id = $order->get_customer_id();
			if ( $user_id ) {
				fcmc_recompute_member( $user_id );
			}
		}
	);
}
unset( $fcmc_paid_status );

/**
 * Also recompute when an order LEAVES a paid status (refund, cancellation, a
 * mistakenly-completed order put back). Without this the cache would keep a
 * paid-through date the ledger no longer supports.
 */
add_action(
	'woocommerce_order_status_changed',
	function ( $order_id, $from, $to ) {
		if ( ! in_array( $from, fcmc_paid_order_statuses(), true ) ) {
			return;
		}
		if ( in_array( $to, fcmc_paid_order_statuses(), true ) ) {
			return;
		}
		$order = wc_get_order( $order_id );
		if ( $order && $order->get_customer_id() ) {
			fcmc_recompute_member( $order->get_customer_id() );
		}
	},
	10,
	3
);

/* -------------------------------------------------------------------------
 * 5. Nightly status refresh
 * ---------------------------------------------------------------------- */

add_action(
	'init',
	function () {
		if ( ! wp_next_scheduled( 'fcmc_daily_status_refresh' ) ) {
			// 03:00 site time — after midnight, so a membership that expires today
			// is already reflected by the time anyone looks at the roster.
			$next = new DateTimeImmutable( 'tomorrow 03:00', wp_timezone() );
			wp_schedule_event( $next->getTimestamp(), 'daily', 'fcmc_daily_status_refresh' );
		}
	}
);

add_action(
	'fcmc_daily_status_refresh',
	function () {
		foreach ( get_users( array( 'fields' => 'ID' ) ) as $user_id ) {
			fcmc_refresh_status( (int) $user_id );
		}
	}
);

/* -------------------------------------------------------------------------
 * 6. WP-CLI: wp fcmc recompute
 * ---------------------------------------------------------------------- */

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	WP_CLI::add_command(
		'fcmc recompute',
		function ( $args, $assoc_args ) {
			if ( ! empty( $args[0] ) ) {
				$result = fcmc_recompute_member( (int) $args[0] );
				WP_CLI::success( 'User ' . $args[0] . ': ' . wp_json_encode( $result ) );
				return;
			}
			$count = fcmc_recompute_all();
			WP_CLI::success( "Recomputed {$count} users from order history." );
		},
		array(
			'shortdesc' => 'Rebuild cached membership fields from order history. Pass a user ID for one member.',
		)
	);
}
