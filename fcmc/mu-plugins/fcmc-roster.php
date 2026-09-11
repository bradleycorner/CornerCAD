<?php
/**
 * Plugin Name: FCMC Club Roster
 * Description: The officer-facing roster. Implements §2 of the 2026-09-09 membership design,
 *              with one deliberate departure: the roster lives as a **My Account tab**, not a
 *              wp-admin screen.
 *
 *              Why: club officers are volunteers, not WordPress administrators. Putting the
 *              roster in wp-admin means handing them a backend login and trusting a role to
 *              fence them in. As a My Account tab they sign in exactly as any member does and
 *              simply see one tab more, themed like the rest of the club site. The cost is
 *              that we hand-roll the table instead of getting WP_List_Table free — fine at
 *              ~47 households.
 *
 *              One row per HOUSEHOLD (up to two members and any number of cars), whether or
 *              not a WP user account is attached — plus one row for any WP user who isn't
 *              linked to a household at all. Never one row per transaction. This is the thing
 *              the WooCommerce Orders screen cannot do: show a LAPSED member, who by
 *              definition has no recent order to sort by.
 *
 * @see docs/superpowers/specs/2026-09-09-fcmc-membership-system-design.md
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const FCMC_ROSTER_ENDPOINT = 'club-roster';
const FCMC_ROSTER_CAP      = 'fcmc_manage_members';

/* -------------------------------------------------------------------------
 * 1. Role and capability
 * ---------------------------------------------------------------------- */

/**
 * Create the officer role and grant the capability.
 *
 * mu-plugins have no activation hook, so this runs behind a version option and
 * re-runs only when that version changes.
 */
function fcmc_roster_install() {
	$version = '2';
	if ( get_option( 'fcmc_roster_version' ) === $version ) {
		return;
	}

	// An officer is a member first: everything `customer` can do, plus the roster.
	$customer = get_role( 'customer' );
	$caps     = $customer ? $customer->capabilities : array( 'read' => true );
	$caps[ FCMC_ROSTER_CAP ] = true;

	remove_role( 'membership_officer' );
	add_role( 'membership_officer', __( 'Membership Officer', 'fcmc' ), $caps );

	$admin = get_role( 'administrator' );
	if ( $admin ) {
		$admin->add_cap( FCMC_ROSTER_CAP );
	}

	// Grant the fcmc_household post type's OWN generated primitives (NOT
	// fcmc_manage_members — mapping a post type's meta caps onto that string is the
	// bug this version fixes, see fcmc-households.php). Read them off the registered
	// post type rather than hardcoding the list, so a WP core change in primitive
	// naming can't silently desync this from what's actually registered.
	$household_type = get_post_type_object( 'fcmc_household' );
	if ( $household_type ) {
		$officer = get_role( 'membership_officer' );
		foreach ( (array) $household_type->cap as $cap ) {
			if ( 'do_not_allow' === $cap ) {
				continue;
			}
			if ( $officer ) {
				$officer->add_cap( $cap );
			}
			if ( $admin ) {
				$admin->add_cap( $cap );
			}
		}
	}

	update_option( 'fcmc_roster_version', $version );

	// The endpoint is a rewrite rule; without this the tab 404s until someone
	// re-saves permalinks by hand.
	flush_rewrite_rules();
}
add_action( 'init', 'fcmc_roster_install', 20 );

/* -------------------------------------------------------------------------
 * 2. The My Account endpoint
 * ---------------------------------------------------------------------- */

add_action(
	'init',
	function () {
		add_rewrite_endpoint( FCMC_ROSTER_ENDPOINT, EP_PAGES );
	}
);

add_filter(
	'woocommerce_get_query_vars',
	function ( $vars ) {
		$vars[ FCMC_ROSTER_ENDPOINT ] = FCMC_ROSTER_ENDPOINT;
		return $vars;
	}
);

/**
 * Insert "Club Roster" just above Log out, and only for officers.
 */
add_filter(
	'woocommerce_account_menu_items',
	function ( $items ) {
		if ( ! current_user_can( FCMC_ROSTER_CAP ) ) {
			return $items;
		}

		$logout = isset( $items['customer-logout'] ) ? array( 'customer-logout' => $items['customer-logout'] ) : array();
		unset( $items['customer-logout'] );

		$items[ FCMC_ROSTER_ENDPOINT ] = __( 'Club Roster', 'fcmc' );

		return $items + $logout;
	}
);

add_action(
	'woocommerce_account_' . FCMC_ROSTER_ENDPOINT . '_endpoint',
	'fcmc_render_roster'
);

/* -------------------------------------------------------------------------
 * 3. Gathering the households
 * ---------------------------------------------------------------------- */

/**
 * Build the roster rows.
 *
 * One row per HOUSEHOLD — including lapsed ones and those who never paid, which is
 * the entire reason this exists rather than reading the Orders screen — PLUS one row
 * for every WP user who is NOT already represented by a household (no
 * `fcmc_household_id` meta). A claimed household never doubles up with its user: the
 * household row is authoritative and the user is skipped in the second pass.
 *
 * For a claimed household, status/paid-through/since are read from the LINKED USER,
 * not the household's own imported fields — a live order must win over the imported
 * baseline (see fcmc_recompute_member()'s floor rule). An unclaimed household derives
 * its status from its own `paid_through` via fcmc_status_for(). `user` is null for an
 * unclaimed household — the renderers never dereference it, only read the flat keys
 * below.
 *
 * @param array $filters status, joined_since.
 * @return array[] One row per household (claimed or not) plus unlinked users.
 */
function fcmc_roster_rows( $filters = array() ) {
	$rows           = array();
	$linked_user_ids = array();

	foreach ( fcmc_household_all() as $household_id ) {
		$h = fcmc_household_get( $household_id );

		$claimed_user = $h['claimed_by'] ? get_userdata( (int) $h['claimed_by'] ) : false;

		if ( $claimed_user ) {
			$linked_user_ids[] = $claimed_user->ID;

			$row_user     = $claimed_user;
			$status       = function_exists( 'fcmc_get_status' ) ? fcmc_get_status( $claimed_user->ID ) : 'none';
			$primary      = trim( $claimed_user->first_name . ' ' . $claimed_user->last_name ) ?: $claimed_user->display_name;
			$email        = $claimed_user->user_email;
			$member2      = get_user_meta( $claimed_user->ID, 'fcmc_member2_name', true );
			$member2_mail = get_user_meta( $claimed_user->ID, 'fcmc_member2_email', true );
			$paid_through = get_user_meta( $claimed_user->ID, 'fcmc_paid_through', true );
			$since        = get_user_meta( $claimed_user->ID, 'fcmc_member_since', true );
			$cars         = get_user_meta( $claimed_user->ID, 'fcmc_car_profiles', true );
			$cars         = is_array( $cars ) ? $cars : array();
		} else {
			// Unclaimed (or claimed_by points at a deleted user, which we treat the
			// same way — there is no live account to read from).
			$row_user     = null;
			$primary      = trim( (string) $h['member1_name'] );
			$email        = $h['member1_email'];
			$member2      = $h['member2_name'];
			$member2_mail = $h['member2_email'];
			$paid_through = $h['paid_through'];
			$since        = $h['member_since'];
			$cars         = $h['cars'];

			$paid_through_date = $h['paid_through']
				? DateTimeImmutable::createFromFormat( 'Y-m-d', $h['paid_through'], wp_timezone() )
				: null;
			$status = fcmc_status_for( $paid_through_date ?: null );
		}

		if ( ! empty( $filters['status'] ) && $filters['status'] !== $status ) {
			continue;
		}
		if ( ! empty( $filters['joined_since'] ) ) {
			// No join date means we cannot claim they joined after the cutoff.
			if ( ! $since || $since < $filters['joined_since'] ) {
				continue;
			}
		}

		$rows[] = array(
			'user'         => $row_user,
			'primary'      => $primary,
			'email'        => $email,
			'member2'      => $member2,
			'member2_mail' => $member2_mail,
			'status'       => $status,
			'paid_through' => $paid_through,
			'since'        => $since,
			'cars'         => $cars,
			'household_id' => $household_id,
			'claimed'      => null !== $row_user,
		);
	}

	// Second pass: WP users with no household of their own — an account that
	// pre-dates the import, or that registered without a matching household email.
	foreach ( get_users( array( 'orderby' => 'display_name' ) ) as $user ) {
		if ( get_user_meta( $user->ID, 'fcmc_household_id', true ) ) {
			continue;
		}
		if ( in_array( $user->ID, $linked_user_ids, true ) ) {
			continue;
		}

		$status = function_exists( 'fcmc_get_status' ) ? fcmc_get_status( $user->ID ) : 'none';

		if ( ! empty( $filters['status'] ) && $filters['status'] !== $status ) {
			continue;
		}

		$since = get_user_meta( $user->ID, 'fcmc_member_since', true );
		if ( ! empty( $filters['joined_since'] ) ) {
			if ( ! $since || $since < $filters['joined_since'] ) {
				continue;
			}
		}

		$cars = get_user_meta( $user->ID, 'fcmc_car_profiles', true );
		$cars = is_array( $cars ) ? $cars : array();

		$rows[] = array(
			'user'         => $user,
			'primary'      => trim( $user->first_name . ' ' . $user->last_name ) ?: $user->display_name,
			'email'        => $user->user_email,
			'member2'      => get_user_meta( $user->ID, 'fcmc_member2_name', true ),
			'member2_mail' => get_user_meta( $user->ID, 'fcmc_member2_email', true ),
			'status'       => $status,
			'paid_through' => get_user_meta( $user->ID, 'fcmc_paid_through', true ),
			'since'        => $since,
			'cars'         => $cars,
			'household_id' => null,
			'claimed'      => true,
		);
	}

	return $rows;
}

/**
 * The car columns shown on the roster, in order.
 *
 * Purchase date is deliberately absent: it is a "tell me about your car" detail
 * rather than something an officer manages a roster by, and it already appears on
 * the member's own account page. Add it here if that turns out to be wrong.
 *
 * @return array<string,string> column key => heading
 */
function fcmc_roster_car_columns() {
	return array(
		'car_model_year' => __( 'Year', 'fcmc' ),
		'car_generation' => __( 'Gen', 'fcmc' ),
		'car_package'    => __( 'Trim', 'fcmc' ),
		'car_colors'     => __( 'Colors', 'fcmc' ),
		'car_name'       => __( 'Car name', 'fcmc' ),
	);
}

/**
 * One car's value for one roster column.
 *
 * Every car renders exactly one line in every car column, in the same order, so a
 * two-car household reads straight across.
 *
 * @param array  $car Car profile.
 * @param string $key Column key from fcmc_roster_car_columns().
 * @return string Empty string renders as a dash.
 */
function fcmc_roster_car_value( $car, $key ) {
	if ( 'car_colors' === $key ) {
		return implode(
			' / ',
			array_filter(
				array(
					$car['car_color_body'] ?? '',
					$car['car_color_top'] ?? '',
				)
			)
		);
	}

	return (string) ( $car[ $key ] ?? '' );
}

/* -------------------------------------------------------------------------
 * 4. Rendering
 * ---------------------------------------------------------------------- */

function fcmc_render_roster() {
	if ( ! current_user_can( FCMC_ROSTER_CAP ) ) {
		echo '<p>' . esc_html__( 'You do not have permission to view the club roster.', 'fcmc' ) . '</p>';
		return;
	}

	// Read-only filters, so no nonce — nothing here changes state.
	// phpcs:disable WordPress.Security.NonceVerification.Recommended
	$status_filter = isset( $_GET['fcmc_status'] ) ? sanitize_key( wp_unslash( $_GET['fcmc_status'] ) ) : '';
	$joined_since  = isset( $_GET['fcmc_joined_since'] ) ? sanitize_text_field( wp_unslash( $_GET['fcmc_joined_since'] ) ) : '';
	// phpcs:enable

	$valid = array( 'active', 'grace', 'lapsed', 'none' );
	if ( $status_filter && ! in_array( $status_filter, $valid, true ) ) {
		$status_filter = '';
	}
	if ( $joined_since && ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $joined_since ) ) {
		$joined_since = '';
	}

	$rows = fcmc_roster_rows(
		array(
			'status'       => $status_filter,
			'joined_since' => $joined_since,
		)
	);

	// Counts are always across the WHOLE roster, not the filtered view — otherwise
	// filtering to "lapsed" would make it look like the club had shrunk.
	$totals = array_count_values( wp_list_pluck( fcmc_roster_rows(), 'status' ) );

	$colors = array(
		'active' => array( '#1c6b3c', '#e4f4ea' ),
		'grace'  => array( '#8a6100', '#fdf3dc' ),
		'lapsed' => array( '#8a1f1f', '#fbe6e6' ),
		'none'   => array( '#555', '#eee' ),
	);
	?>
	<h2><?php esc_html_e( 'Club Roster', 'fcmc' ); ?></h2>

	<p>
		<?php
		printf(
			/* translators: 1: household count, 2: active, 3: in grace, 4: lapsed, 5: never paid */
			esc_html__( '%1$d households — %2$d active, %3$d in grace, %4$d lapsed, %5$d never paid.', 'fcmc' ),
			(int) array_sum( $totals ),
			(int) ( $totals['active'] ?? 0 ),
			(int) ( $totals['grace'] ?? 0 ),
			(int) ( $totals['lapsed'] ?? 0 ),
			(int) ( $totals['none'] ?? 0 )
		);
		?>
	</p>

	<form method="get" style="margin-bottom:1.5em;display:flex;flex-wrap:wrap;gap:1em;align-items:flex-end;">
		<label>
			<?php esc_html_e( 'Status', 'fcmc' ); ?><br />
			<select name="fcmc_status">
				<option value=""><?php esc_html_e( 'All', 'fcmc' ); ?></option>
				<?php foreach ( $valid as $key ) : ?>
					<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $status_filter, $key ); ?>>
						<?php echo esc_html( fcmc_status_label( $key ) ); ?>
					</option>
				<?php endforeach; ?>
			</select>
		</label>

		<label>
			<?php esc_html_e( 'Joined on or after', 'fcmc' ); ?><br />
			<input type="date" name="fcmc_joined_since" value="<?php echo esc_attr( $joined_since ); ?>" />
		</label>

		<button type="submit" class="button"><?php esc_html_e( 'Filter', 'fcmc' ); ?></button>

		<?php if ( $status_filter || $joined_since ) : ?>
			<a class="button" href="<?php echo esc_url( wc_get_account_endpoint_url( FCMC_ROSTER_ENDPOINT ) ); ?>">
				<?php esc_html_e( 'Clear', 'fcmc' ); ?>
			</a>
		<?php endif; ?>
	</form>

	<?php if ( $joined_since ) : ?>
		<p><em><?php
			printf(
				/* translators: %s: date */
				esc_html__( 'Showing members who joined on or after %s — this is the list for the Road Runner new-member note.', 'fcmc' ),
				esc_html( $joined_since )
			);
		?></em></p>
	<?php endif; ?>

	<?php if ( empty( $rows ) ) : ?>
		<p><?php esc_html_e( 'No households match that filter.', 'fcmc' ); ?></p>
		<?php return; ?>
	<?php endif; ?>

	<style>
		/* Dates and per-car values are short and must never wrap onto a second line —
		   a date broken across two lines is unreadable at a glance, and wrapped car
		   values would break the line-per-car alignment across the car columns. */
		/* Ten columns at the theme's 16px need ~1360px, which overflows even a widened
		   account column. Measured at a 1000px window: 16px -> 1360, 15px -> 1030,
		   14px -> 933, 13px -> 916. 15px fits comfortably on any realistic officer
		   laptop (1280px leaves ~1230 available) and stays readable — this membership
		   skews older, and a roster is for reading. Below ~1100px it scrolls. */
		.fcmc-roster { font-size: 15px; }
		.fcmc-roster td, .fcmc-roster th { vertical-align: top; padding: .5em .6em; }
		.fcmc-roster .fcmc-col-date,
		.fcmc-roster .fcmc-col-car { white-space: nowrap; }
		.fcmc-roster .fcmc-car-value { display: block; line-height: 1.6; }
		.fcmc-roster .fcmc-none { opacity: .45; }

		/* Ten columns do not fit the standard My Account content column (measured:
		   the table wants ~1360px, the column offers ~700). WooCommerce puts a
		   `woocommerce-club-roster` class on <body> for this endpoint, so the wider
		   layout applies to the roster tab ONLY — every other My Account tab keeps
		   its normal sidebar layout. The account nav becomes a horizontal strip
		   above the table rather than being hidden, so officers can still navigate. */
		.woocommerce-club-roster .col-full { max-width: 96vw; }
		.woocommerce-club-roster .woocommerce-MyAccount-navigation,
		.woocommerce-club-roster .woocommerce-MyAccount-content {
			width: 100%;
			float: none;
		}
		.woocommerce-club-roster .woocommerce-MyAccount-navigation { margin-bottom: 1.5em; }
		.woocommerce-club-roster .woocommerce-MyAccount-navigation ul {
			display: flex;
			flex-wrap: wrap;
			gap: .25em 1.25em;
			margin: 0;
			padding: 0;
			list-style: none;
			border-bottom: 1px solid rgba(0,0,0,.1);
		}
		.woocommerce-club-roster .woocommerce-MyAccount-navigation li { border: 0; margin: 0; }
		/* ---- Cards below 768px ----------------------------------------------
		   WooCommerce's own responsive-table CSS (`table.shop_table_responsive`)
		   already switches this table to a stacked layout at max-width 768px, and
		   its selectors outrank anything here — so 768px is the real breakpoint
		   whether or not this file agrees with it. Matching it deliberately.

		   That single breakpoint is also what produces the behavior the club
		   wanted, for free: a phone is under 768px held tall and over it held wide,
		   so portrait gets cards and landscape gets columns with no orientation
		   query at all.

		   Two earlier attempts here were wrong and are recorded so they are not
		   repeated: (1) invented width tiers (13px between 1000-1149px, cards below
		   1000px) — these guessed at device widths that cannot be measured from the
		   dev machine, and being ~50px out truncated the last two columns in
		   landscape; (2) an `@media (orientation: …)` pair — correct as a statement
		   of intent, but it cannot win against WooCommerce's more specific
		   max-width rule, so landscape still rendered as cards on a narrow screen.

		   What this block does is style the stacked layout WooCommerce produces:
		   the header row is hidden and every cell prints its own label from the
		   data-title attribute already on it. The nowrap and the widened container
		   are both undone — nowrap exists to protect column alignment, and there
		   are no columns left to align once the table has stopped being a table. */
		@media (max-width: 768px) {
			.woocommerce-club-roster .col-full { max-width: 100%; }

			.fcmc-roster,
			.fcmc-roster tbody,
			.fcmc-roster tr,
			.fcmc-roster td { display: block; width: auto; }

			.fcmc-roster thead { display: none; }

			.fcmc-roster tr {
				border: 1px solid rgba(0,0,0,.15);
				border-radius: 6px;
				padding: .75em 1em;
				margin-bottom: 1em;
			}

			.fcmc-roster td {
				display: flex;
				justify-content: space-between;
				gap: 1em;
				border: 0;
				padding: .25em 0;
				text-align: right;
			}

			/* The label the desktop table puts in <thead>. */
			.fcmc-roster td::before {
				content: attr(data-title);
				font-weight: 600;
				text-align: left;
				flex: 0 0 auto;
				opacity: .7;
			}

			/* The member cell is the card's heading — full width, no label, no
			   right alignment, and long email addresses must break rather than
			   push the card wide. */
			.fcmc-roster td:first-child {
				display: block;
				text-align: left;
				font-size: 1.05em;
				padding-bottom: .5em;
				margin-bottom: .5em;
				border-bottom: 1px solid rgba(0,0,0,.08);
			}
			/* WooCommerce's own responsive-table CSS also injects a data-title label
			   (`table.shop_table_responsive tbody tr td:before`). Suppressing it on
			   the heading cell needs !important, or the card reads
			   "Member:fcmc-test-member". Verified. */
			.fcmc-roster tbody tr td:first-child::before { content: none !important; }
			.fcmc-roster td a { word-break: break-word; }

			/* Alignment across car columns is meaningless in card form, and nowrap
			   here would force the card wider than the screen. */
			.fcmc-roster .fcmc-col-date,
			.fcmc-roster .fcmc-col-car { white-space: normal; }

			/* Multi-car households: keep each car's values on one line together. */
			.fcmc-roster .fcmc-car-value { display: inline-block; margin-left: .5em; }
			.fcmc-roster .fcmc-car-value + .fcmc-car-value::before {
				content: "•";
				margin-right: .5em;
				opacity: .4;
			}

			/* The filter form stacks rather than crowding three controls onto a row. */
			.woocommerce-club-roster form[method="get"] { flex-direction: column; align-items: stretch; }
			.woocommerce-club-roster form[method="get"] select,
			.woocommerce-club-roster form[method="get"] input[type="date"] { width: 100%; }
		}
	</style>

	<div style="overflow-x:auto;">
	<table class="shop_table shop_table_responsive fcmc-roster">
		<thead>
			<tr>
				<th><?php esc_html_e( 'Member', 'fcmc' ); ?></th>
				<th><?php esc_html_e( '2nd member', 'fcmc' ); ?></th>
				<th><?php esc_html_e( 'Status', 'fcmc' ); ?></th>
				<th class="fcmc-col-date"><?php esc_html_e( 'Paid through', 'fcmc' ); ?></th>
				<?php foreach ( fcmc_roster_car_columns() as $car_heading ) : ?>
					<th class="fcmc-col-car"><?php echo esc_html( $car_heading ); ?></th>
				<?php endforeach; ?>
				<th class="fcmc-col-date"><?php esc_html_e( 'Joined', 'fcmc' ); ?></th>
			</tr>
		</thead>
		<tbody>
		<?php foreach ( $rows as $row ) : ?>
			<?php list( $fg, $bg ) = $colors[ $row['status'] ] ?? $colors['none']; ?>
			<tr>
				<td data-title="<?php esc_attr_e( 'Member', 'fcmc' ); ?>">
					<strong><?php echo esc_html( $row['primary'] ); ?></strong><br />
					<a href="mailto:<?php echo esc_attr( $row['email'] ); ?>"><?php echo esc_html( $row['email'] ); ?></a>
				</td>
				<td data-title="<?php esc_attr_e( '2nd member', 'fcmc' ); ?>">
					<?php if ( $row['member2'] ) : ?>
						<?php echo esc_html( $row['member2'] ); ?>
						<?php if ( $row['member2_mail'] ) : ?>
							<br /><a href="mailto:<?php echo esc_attr( $row['member2_mail'] ); ?>"><?php echo esc_html( $row['member2_mail'] ); ?></a>
						<?php endif; ?>
					<?php else : ?>
						<span style="opacity:.5;">—</span>
					<?php endif; ?>
				</td>
				<td data-title="<?php esc_attr_e( 'Status', 'fcmc' ); ?>">
					<span style="display:inline-block;padding:.15em .6em;border-radius:1em;font-size:.85em;color:<?php echo esc_attr( $fg ); ?>;background:<?php echo esc_attr( $bg ); ?>;">
						<?php echo esc_html( fcmc_status_label( $row['status'] ) ); ?>
					</span>
				</td>
				<td class="fcmc-col-date" data-title="<?php esc_attr_e( 'Paid through', 'fcmc' ); ?>">
					<?php echo $row['paid_through'] ? esc_html( $row['paid_through'] ) : '<span class="fcmc-none">—</span>'; ?>
				</td>

				<?php foreach ( fcmc_roster_car_columns() as $car_key => $car_heading ) : ?>
					<td class="fcmc-col-car" data-title="<?php echo esc_attr( $car_heading ); ?>">
						<?php if ( $row['cars'] ) : ?>
							<?php foreach ( $row['cars'] as $car ) : ?>
								<?php $value = fcmc_roster_car_value( $car, $car_key ); ?>
								<span class="fcmc-car-value">
									<?php echo '' !== $value ? esc_html( $value ) : '<span class="fcmc-none">—</span>'; ?>
								</span>
							<?php endforeach; ?>
						<?php else : ?>
							<span class="fcmc-none">—</span>
						<?php endif; ?>
					</td>
				<?php endforeach; ?>

				<td class="fcmc-col-date" data-title="<?php esc_attr_e( 'Joined', 'fcmc' ); ?>">
					<?php echo $row['since'] ? esc_html( $row['since'] ) : '<span class="fcmc-none">—</span>'; ?>
				</td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>
	</div>

	<?php fcmc_render_needs_linking(); ?>
	<?php
}

/* -------------------------------------------------------------------------
 * 5. Needs-linking — officers connect an account to a household by hand
 * ---------------------------------------------------------------------- */

const FCMC_LINK_ACTION = 'fcmc_link_household';
const FCMC_LINK_NONCE  = 'fcmc_link_household_nonce';

/**
 * Households with no linked user account.
 *
 * @return array[] fcmc_household_get() arrays.
 */
function fcmc_roster_unclaimed_households() {
	$out = array();
	foreach ( fcmc_household_all() as $id ) {
		if ( get_post_meta( $id, 'claimed_by', true ) ) {
			continue;
		}
		$out[] = fcmc_household_get( $id );
	}
	return $out;
}

/**
 * WP users with no household of their own.
 *
 * @return WP_User[]
 */
function fcmc_roster_unlinked_users() {
	$out = array();
	foreach ( get_users( array( 'orderby' => 'display_name' ) ) as $user ) {
		if ( get_user_meta( $user->ID, 'fcmc_household_id', true ) ) {
			continue;
		}
		$out[] = $user;
	}
	return $out;
}

/**
 * A short, human label for a household in the linking <select> — the contact
 * name if there is one, a non-identifying placeholder otherwise. Escaped by the
 * caller; this returns raw data.
 *
 * @param array $h fcmc_household_get() array.
 * @return string
 */
function fcmc_household_label( $h ) {
	return '' !== trim( (string) $h['member1_name'] )
		? $h['member1_name']
		/* translators: %d: household post ID */
		: sprintf( __( 'Household #%d', 'fcmc' ), $h['id'] );
}

/**
 * The needs-linking section, rendered below the roster table.
 *
 * Officer-only — checked again here rather than assuming the caller (
 * fcmc_render_roster(), which already gates the whole tab) is the only way in.
 * Lists unclaimed households and WP accounts with no household, and lets an
 * officer connect one pair at a time via a form posting to admin-post.php.
 */
function fcmc_render_needs_linking() {
	if ( ! current_user_can( FCMC_ROSTER_CAP ) ) {
		return;
	}

	$unclaimed = fcmc_roster_unclaimed_households();
	$unlinked  = fcmc_roster_unlinked_users();
	?>
	<h2><?php esc_html_e( 'Needs linking', 'fcmc' ); ?></h2>

	<p>
		<em>
			<?php esc_html_e( '7 payments on file carry no email address, so the site has no way to match them automatically — those need a human pass against Square.', 'fcmc' ); ?>
		</em>
	</p>

	<?php
	// Read-only display of a redirect flag from the form handler below — nothing
	// here changes state, so no nonce is needed to read it.
	// phpcs:disable WordPress.Security.NonceVerification.Recommended
	$just_linked  = isset( $_GET['fcmc_linked'] );
	$link_failed  = isset( $_GET['fcmc_link_error'] );
	// phpcs:enable
	?>
	<?php if ( $just_linked ) : ?>
		<div class="woocommerce-message"><?php esc_html_e( 'Household linked.', 'fcmc' ); ?></div>
	<?php elseif ( $link_failed ) : ?>
		<div class="woocommerce-error"><?php esc_html_e( 'Could not link that account — please check the selection and try again.', 'fcmc' ); ?></div>
	<?php endif; ?>

	<h3>
		<?php
		printf(
			/* translators: %d: count of unclaimed households */
			esc_html__( 'Unclaimed households (%d)', 'fcmc' ),
			count( $unclaimed )
		);
		?>
	</h3>
	<?php if ( empty( $unclaimed ) ) : ?>
		<p><?php esc_html_e( 'None — every household is linked to an account.', 'fcmc' ); ?></p>
	<?php else : ?>
		<ul>
			<?php foreach ( $unclaimed as $h ) : ?>
				<li>
					<?php echo esc_html( fcmc_household_label( $h ) ); ?>
					<?php if ( $h['member1_email'] ) : ?>
						&mdash; <a href="mailto:<?php echo esc_attr( $h['member1_email'] ); ?>"><?php echo esc_html( $h['member1_email'] ); ?></a>
					<?php endif; ?>
				</li>
			<?php endforeach; ?>
		</ul>
	<?php endif; ?>

	<h3>
		<?php
		printf(
			/* translators: %d: count of accounts with no household */
			esc_html__( 'Accounts with no household (%d)', 'fcmc' ),
			count( $unlinked )
		);
		?>
	</h3>
	<?php if ( empty( $unlinked ) ) : ?>
		<p><?php esc_html_e( 'None — every account is linked to a household.', 'fcmc' ); ?></p>
	<?php elseif ( empty( $unclaimed ) ) : ?>
		<p><?php esc_html_e( 'These accounts have no household, and there are no unclaimed households left to link them to.', 'fcmc' ); ?></p>
		<ul>
			<?php foreach ( $unlinked as $user ) : ?>
				<li><?php echo esc_html( $user->display_name ); ?> (<?php echo esc_html( $user->user_email ); ?>)</li>
			<?php endforeach; ?>
		</ul>
	<?php else : ?>
		<div style="overflow-x:auto;">
		<table class="widefat">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Account', 'fcmc' ); ?></th>
					<th><?php esc_html_e( 'Link to household', 'fcmc' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $unlinked as $user ) : ?>
					<tr>
						<td>
							<?php echo esc_html( $user->display_name ); ?><br />
							<a href="mailto:<?php echo esc_attr( $user->user_email ); ?>"><?php echo esc_html( $user->user_email ); ?></a>
						</td>
						<td>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
								<?php wp_nonce_field( FCMC_LINK_ACTION, FCMC_LINK_NONCE ); ?>
								<input type="hidden" name="action" value="<?php echo esc_attr( FCMC_LINK_ACTION ); ?>" />
								<input type="hidden" name="fcmc_user_id" value="<?php echo esc_attr( $user->ID ); ?>" />
								<select name="fcmc_household_id">
									<option value=""><?php esc_html_e( '— select a household —', 'fcmc' ); ?></option>
									<?php foreach ( $unclaimed as $h ) : ?>
										<option value="<?php echo esc_attr( $h['id'] ); ?>"><?php echo esc_html( fcmc_household_label( $h ) ); ?></option>
									<?php endforeach; ?>
								</select>
								<button type="submit" class="button"><?php esc_html_e( 'Link', 'fcmc' ); ?></button>
							</form>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		</div>
	<?php endif; ?>
	<?php
}

/**
 * admin-post.php handler: link one account to one household by hand.
 *
 * Verifies the nonce AND re-checks the capability independently — admin-post.php
 * is a generic dispatcher shared by the whole site, so this must not trust that
 * only the roster's own form can reach it.
 */
function fcmc_handle_link_household() {
	$redirect = wc_get_account_endpoint_url( FCMC_ROSTER_ENDPOINT );

	if ( ! isset( $_POST[ FCMC_LINK_NONCE ] )
		|| ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ FCMC_LINK_NONCE ] ) ), FCMC_LINK_ACTION )
	) {
		wp_die( esc_html__( 'Security check failed.', 'fcmc' ), '', array( 'response' => 403 ) );
	}
	if ( ! current_user_can( FCMC_ROSTER_CAP ) ) {
		wp_die( esc_html__( 'You do not have permission to do that.', 'fcmc' ), '', array( 'response' => 403 ) );
	}

	$user_id      = isset( $_POST['fcmc_user_id'] ) ? absint( $_POST['fcmc_user_id'] ) : 0;
	$household_id = isset( $_POST['fcmc_household_id'] ) ? absint( $_POST['fcmc_household_id'] ) : 0;

	$ok = $user_id && $household_id
		&& get_userdata( $user_id )
		&& ! get_user_meta( $user_id, 'fcmc_household_id', true )
		&& 'fcmc_household' === get_post_type( $household_id )
		&& 'publish' === get_post_status( $household_id )
		&& ! get_post_meta( $household_id, 'claimed_by', true )
		&& function_exists( 'fcmc_household_claim' );

	if ( $ok ) {
		fcmc_household_claim( $household_id, $user_id );
		$redirect = add_query_arg( 'fcmc_linked', '1', $redirect );
	} else {
		$redirect = add_query_arg( 'fcmc_link_error', '1', $redirect );
	}

	wp_safe_redirect( $redirect );
	exit;
}
add_action( 'admin_post_' . FCMC_LINK_ACTION, 'fcmc_handle_link_household' );
