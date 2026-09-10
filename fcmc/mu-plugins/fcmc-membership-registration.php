<?php
/**
 * Plugin Name: FCMC Membership Registration
 * Description: Custom pre-purchase membership signup flow for First Coast Miata Club.
 *              Account-level fields on WooCommerce register/edit-account forms, plus a
 *              [fcmc_membership_signup] shortcode that lets a logged-in member declare how
 *              many car-memberships they're buying, fill in each car's info, and adds one
 *              cart line item per car (each carrying its own meta) before sending them to
 *              checkout. Checkout itself stays 100% native WooCommerce fields.
 *
 * Why a mu-plugin and not a WPCode snippet: this site has no WPCode installed, and a file
 * this size is better reviewed/version-controlled as code than pasted into a snippet editor
 * that also has its own "must re-save in UI to take effect" cache gotcha.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* -------------------------------------------------------------------------
 * 1. Field definitions — single source of truth
 * ---------------------------------------------------------------------- */

function fcmc_account_fields() {
	return array(
		'fcmc_member2_name' => array(
			'type'        => 'text',
			'label'       => '2nd Household Member — Name',
			'required'    => false,
		),
		'fcmc_member2_phone' => array(
			'type'        => 'tel',
			'label'       => '2nd Household Member — Phone',
			'required'    => false,
		),
		'fcmc_member2_email' => array(
			'type'        => 'email',
			'label'       => '2nd Household Member — Email',
			'required'    => false,
		),
		'fcmc_newsletter_pref' => array(
			'type'        => 'radio',
			'label'       => 'Newsletter Delivery Preference',
			'required'    => true,
			'options'     => array(
				'digital' => 'Digital (Email)',
				'paper'   => 'Paper (USPS)',
			),
		),
		'fcmc_consent_directory_name' => array(
			'type'     => 'checkbox',
			'label'    => 'OK to list my Name(s) in the private Club Directory',
			'required' => false,
		),
		'fcmc_consent_directory_address' => array(
			'type'     => 'checkbox',
			'label'    => 'OK to list my Address in the private Club Directory',
			'required' => false,
		),
		'fcmc_consent_directory_phone' => array(
			'type'     => 'checkbox',
			'label'    => 'OK to list my Phone number(s) in the private Club Directory',
			'required' => false,
		),
		'fcmc_consent_directory_email' => array(
			'type'     => 'checkbox',
			'label'    => 'OK to list my Email(s) in the private Club Directory',
			'required' => false,
		),
		'fcmc_consent_directory_car' => array(
			'type'     => 'checkbox',
			'label'    => 'OK to list my Car Information in the private Club Directory',
			'required' => false,
		),
		'fcmc_consent_public_name' => array(
			'type'     => 'checkbox',
			'label'    => 'OK to show my Name on the public Club website',
			'required' => false,
		),
	);
}

function fcmc_car_fields() {
	return array(
		'car_model_year' => array(
			'label'       => 'Car Model Year',
			'type'        => 'number',
			'required'    => true,
			'placeholder' => 'e.g. 1996',
		),
		'car_generation' => array(
			'label'    => 'Car Generation',
			'type'     => 'select',
			'required' => true,
			'options'  => array(
				''   => 'Select one',
				'NA' => 'NA (1989–1997)',
				'NB' => 'NB (1998–2005)',
				'NC' => 'NC (2006–2015)',
				'ND' => 'ND (2016–present)',
			),
		),
		'car_package' => array(
			'label'       => 'Package / Trim Level',
			'type'        => 'text',
			'required'    => false,
			'placeholder' => 'e.g. Club, Touring, Grand Touring',
		),
		'car_color_body' => array(
			'label'    => 'Body Color',
			'type'     => 'text',
			'required' => false,
		),
		'car_color_top' => array(
			'label'    => 'Top Color',
			'type'     => 'text',
			'required' => false,
		),
		'car_purchase_date' => array(
			'label'       => 'Purchase Date',
			'type'        => 'text',
			'required'    => false,
			'placeholder' => 'MM/YYYY',
		),
		'car_name' => array(
			'label'       => 'Car Name / Radio Call Name',
			'type'        => 'text',
			'required'    => false,
			'placeholder' => 'e.g. Big Red',
		),
	);
}

const FCMC_MEMBERSHIP_PRODUCT_ID = 14;
const FCMC_MAX_CARS              = 6;

/* -------------------------------------------------------------------------
 * 2. Account-level fields on Register + Edit Account forms
 * ---------------------------------------------------------------------- */

function fcmc_render_account_fields( $values = array() ) {
	foreach ( fcmc_account_fields() as $key => $field ) {
		$value = isset( $values[ $key ] ) ? $values[ $key ] : '';

		if ( 'checkbox' === $field['type'] ) {
			printf(
				'<p class="form-row form-row-wide"><label for="%1$s"><input type="checkbox" name="%1$s" id="%1$s" value="1" %2$s /> %3$s</label></p>',
				esc_attr( $key ),
				checked( $value, '1', false ),
				esc_html( $field['label'] )
			);
			continue;
		}

		if ( 'radio' === $field['type'] ) {
			echo '<p class="form-row form-row-wide"><label>' . esc_html( $field['label'] ) . ( $field['required'] ? ' <span class="required">*</span>' : '' ) . '</label><br/>';
			foreach ( $field['options'] as $opt_value => $opt_label ) {
				printf(
					'<label style="margin-right:1em;font-weight:normal;"><input type="radio" name="%1$s" value="%2$s" %3$s /> %4$s</label>',
					esc_attr( $key ),
					esc_attr( $opt_value ),
					checked( $value, $opt_value, false ),
					esc_html( $opt_label )
				);
			}
			echo '</p>';
			continue;
		}

		woocommerce_form_field(
			$key,
			array(
				'type'        => $field['type'],
				'label'       => $field['label'],
				'required'    => $field['required'],
				'class'       => array( 'form-row-wide' ),
			),
			$value
		);
	}
}

add_action( 'woocommerce_register_form', function () {
	echo '<h3>' . esc_html__( 'Membership Information', 'fcmc' ) . '</h3>';
	fcmc_render_account_fields();
} );

add_filter( 'woocommerce_registration_errors', function ( $errors, $username, $email ) {
	if ( empty( $_POST['fcmc_newsletter_pref'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
		$errors->add( 'fcmc_newsletter_pref_error', __( 'Please choose a newsletter delivery preference.', 'fcmc' ) );
	}
	return $errors;
}, 10, 3 );

function fcmc_save_account_fields( $user_id ) {
	foreach ( fcmc_account_fields() as $key => $field ) {
		if ( 'checkbox' === $field['type'] ) {
			update_user_meta( $user_id, $key, isset( $_POST[ $key ] ) ? '1' : '' ); // phpcs:ignore WordPress.Security.NonceVerification
			continue;
		}
		if ( isset( $_POST[ $key ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			update_user_meta( $user_id, $key, sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) );
		}
	}
}
add_action( 'woocommerce_created_customer', function ( $customer_id ) {
	fcmc_save_account_fields( $customer_id );
} );

add_action( 'woocommerce_edit_account_form', function () {
	$user_id = get_current_user_id();
	$values  = array();
	foreach ( fcmc_account_fields() as $key => $field ) {
		$values[ $key ] = get_user_meta( $user_id, $key, true );
	}
	echo '<h3>' . esc_html__( 'Membership Information', 'fcmc' ) . '</h3>';
	fcmc_render_account_fields( $values );
} );

add_action( 'woocommerce_save_account_details_errors', function ( $errors ) {
	if ( empty( $_POST['fcmc_newsletter_pref'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
		$errors->add( 'fcmc_newsletter_pref_error', __( 'Please choose a newsletter delivery preference.', 'fcmc' ) );
	}
} );

add_action( 'woocommerce_save_account_details', function ( $user_id ) {
	fcmc_save_account_fields( $user_id );
} );

/* -------------------------------------------------------------------------
 * 2b. Dashboard tab — show the member their saved membership info at a
 *     glance (account fields + car profiles), since Account Details only
 *     shows the account-level fields, not car info.
 * ---------------------------------------------------------------------- */

add_action( 'woocommerce_account_dashboard', function () {
	$user_id     = get_current_user_id();
	$account     = fcmc_account_fields();
	$car_fields  = fcmc_car_fields();
	$cars        = get_user_meta( $user_id, 'fcmc_car_profiles', true );
	$cars        = is_array( $cars ) ? $cars : array();

	echo '<h2>' . esc_html__( 'Your Membership Information', 'fcmc' ) . '</h2>';

	if ( empty( $cars ) ) {
		echo '<p>' . esc_html__( 'No membership info on file yet.', 'fcmc' ) . ' <a href="' . esc_url( home_url( '/membership-signup/' ) ) . '">' . esc_html__( 'Sign up or renew now', 'fcmc' ) . '</a>.</p>';
	} else {
		foreach ( $cars as $i => $car ) {
			echo '<h4>' . sprintf( esc_html__( 'Car %d', 'fcmc' ), $i + 1 ) . '</h4><ul>';
			foreach ( $car_fields as $key => $field ) {
				$value = $car[ $key ] ?? '';
				if ( '' === $value ) {
					continue;
				}
				if ( 'car_generation' === $key ) {
					$value = $field['options'][ $value ] ?? $value;
				}
				echo '<li><strong>' . esc_html( $field['label'] ) . ':</strong> ' . esc_html( $value ) . '</li>';
			}
			echo '</ul>';
		}
	}

	echo '<h4>' . esc_html__( 'Household & Preferences', 'fcmc' ) . '</h4><ul>';
	foreach ( $account as $key => $field ) {
		$value = get_user_meta( $user_id, $key, true );
		if ( '' === $value ) {
			continue;
		}
		if ( 'checkbox' === $field['type'] ) {
			$value = $value ? __( 'Yes', 'fcmc' ) : __( 'No', 'fcmc' );
		} elseif ( 'radio' === $field['type'] && isset( $field['options'][ $value ] ) ) {
			$value = $field['options'][ $value ];
		}
		echo '<li><strong>' . esc_html( $field['label'] ) . ':</strong> ' . esc_html( $value ) . '</li>';
	}
	echo '</ul>';

	echo '<p><a class="button" href="' . esc_url( home_url( '/membership-signup/' ) ) . '">' . esc_html__( 'Renew or Add a Membership', 'fcmc' ) . '</a> ';
	echo '<a class="button" href="' . esc_url( wc_get_endpoint_url( 'edit-account' ) ) . '">' . esc_html__( 'Update Household & Preferences', 'fcmc' ) . '</a></p>';
}, 5 );

/* -------------------------------------------------------------------------
 * 3. Post-login routing — send members straight to the signup/renew page
 * ---------------------------------------------------------------------- */

add_filter( 'woocommerce_login_redirect', function ( $redirect, $user ) {
	if ( $user instanceof WP_User && ! user_can( $user, 'manage_options' ) ) {
		$page = get_page_by_path( 'membership-signup' );
		if ( $page ) {
			return get_permalink( $page );
		}
	}
	return $redirect;
}, 10, 2 );

add_filter( 'woocommerce_registration_redirect', function ( $redirect ) {
	$page = get_page_by_path( 'membership-signup' );
	if ( $page ) {
		return get_permalink( $page );
	}
	return $redirect;
} );

/* -------------------------------------------------------------------------
 * 4. [fcmc_membership_signup] — quantity + per-car fields, adds cart items
 * ---------------------------------------------------------------------- */

add_shortcode( 'fcmc_membership_signup', function () {
	if ( ! is_user_logged_in() ) {
		ob_start();
		?>
		<p><?php esc_html_e( 'Please log in or create an account to sign up for or renew your membership.', 'fcmc' ); ?></p>
		<?php echo do_shortcode( '[woocommerce_my_account]' ); ?>
		<?php
		return ob_get_clean();
	}

	$car_fields = fcmc_car_fields();
	$saved_cars = get_user_meta( get_current_user_id(), 'fcmc_car_profiles', true );
	$saved_cars = is_array( $saved_cars ) && ! empty( $saved_cars ) ? $saved_cars : array( array() );

	ob_start();
	?>
	<div id="fcmc-signup">
		<p><?php esc_html_e( 'How many car-memberships are you paying for this year? Add a car for each $30 membership — most members have one.', 'fcmc' ); ?></p>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="fcmc_add_memberships_to_cart" />
			<?php wp_nonce_field( 'fcmc_add_memberships', 'fcmc_nonce' ); ?>

			<div id="fcmc-car-list"></div>

			<p>
				<button type="button" id="fcmc-add-car" class="button"><?php esc_html_e( '+ Add Another Car', 'fcmc' ); ?></button>
			</p>

			<p>
				<button type="submit" class="button alt"><?php esc_html_e( 'Add to Cart & Continue to Payment', 'fcmc' ); ?></button>
			</p>
		</form>

		<template id="fcmc-car-template">
			<fieldset class="fcmc-car-block" style="border:1px solid #ccc;padding:1em;margin-bottom:1em;">
				<legend><?php esc_html_e( 'Car', 'fcmc' ); ?> <span class="fcmc-car-number"></span>
					<button type="button" class="fcmc-remove-car" style="margin-left:1em;">&times; <?php esc_html_e( 'Remove', 'fcmc' ); ?></button>
				</legend>
				<?php foreach ( $car_fields as $key => $field ) : ?>
					<p class="form-row form-row-wide">
						<label><?php echo esc_html( $field['label'] ); ?><?php echo ! empty( $field['required'] ) ? ' <span class="required">*</span>' : ''; ?></label>
						<?php if ( 'select' === $field['type'] ) : ?>
							<select name="cars[__INDEX__][<?php echo esc_attr( $key ); ?>]" <?php echo ! empty( $field['required'] ) ? 'required' : ''; ?>>
								<?php foreach ( $field['options'] as $opt_value => $opt_label ) : ?>
									<option value="<?php echo esc_attr( $opt_value ); ?>"><?php echo esc_html( $opt_label ); ?></option>
								<?php endforeach; ?>
							</select>
						<?php else : ?>
							<input
								type="<?php echo esc_attr( $field['type'] ); ?>"
								name="cars[__INDEX__][<?php echo esc_attr( $key ); ?>]"
								placeholder="<?php echo esc_attr( $field['placeholder'] ?? '' ); ?>"
								<?php echo ! empty( $field['required'] ) ? 'required' : ''; ?>
							/>
						<?php endif; ?>
					</p>
				<?php endforeach; ?>
			</fieldset>
		</template>
	</div>

	<script>
	(function(){
		var list = document.getElementById('fcmc-car-list');
		var tpl = document.getElementById('fcmc-car-template');
		var maxCars = <?php echo (int) FCMC_MAX_CARS; ?>;
		var count = 0;

		function addCar() {
			if (count >= maxCars) { return; }
			count++;
			var frag = tpl.content.cloneNode(true);
			var block = frag.querySelector('.fcmc-car-block');
			block.innerHTML = block.innerHTML.split('__INDEX__').join(count - 1);
			block.querySelector('.fcmc-car-number').textContent = count;
			block.querySelector('.fcmc-remove-car').addEventListener('click', function(){
				block.remove();
				renumber();
			});
			list.appendChild(block);
		}

		function renumber() {
			var blocks = list.querySelectorAll('.fcmc-car-block');
			blocks.forEach(function(b, i){
				b.querySelector('.fcmc-car-number').textContent = i + 1;
			});
		}

		document.getElementById('fcmc-add-car').addEventListener('click', addCar);
		addCar(); // always start with one car block visible
	})();
	</script>
	<?php
	return ob_get_clean();
} );

/* -------------------------------------------------------------------------
 * 5. Handle the signup form submission — build cart items
 * ---------------------------------------------------------------------- */

add_action( 'admin_post_fcmc_add_memberships_to_cart', function () {
	if ( ! is_user_logged_in() ) {
		wp_safe_redirect( wc_get_page_permalink( 'myaccount' ) );
		exit;
	}

	check_admin_referer( 'fcmc_add_memberships', 'fcmc_nonce' );

	$posted_cars = isset( $_POST['cars'] ) && is_array( $_POST['cars'] ) ? wp_unslash( $_POST['cars'] ) : array(); // phpcs:ignore WordPress.Security.NonceVerification
	$car_fields  = fcmc_car_fields();
	$clean_cars  = array();

	foreach ( $posted_cars as $car ) {
		$clean = array();
		$valid = true;
		foreach ( $car_fields as $key => $field ) {
			$val = isset( $car[ $key ] ) ? sanitize_text_field( $car[ $key ] ) : '';
			if ( ! empty( $field['required'] ) && '' === $val ) {
				$valid = false;
			}
			$clean[ $key ] = $val;
		}
		if ( $valid ) {
			$clean_cars[] = $clean;
		}
	}

	if ( empty( $clean_cars ) ) {
		wp_safe_redirect( add_query_arg( 'fcmc_error', '1', wp_get_referer() ) );
		exit;
	}

	// Start clean so re-submitting doesn't stack duplicate memberships.
	WC()->cart->empty_cart();

	// One line item, quantity = number of cars. Cart/checkout only needs to show
	// "N x FCMC Annual Membership" — the per-car detail rides along as hidden data
	// and is persisted to the order for the admin roster, not shown to the customer.
	WC()->cart->add_to_cart(
		FCMC_MEMBERSHIP_PRODUCT_ID,
		count( $clean_cars ),
		0,
		array(),
		array( 'fcmc_cars_data' => $clean_cars )
	);

	// Mirror submitted cars as the member's "current" profile, for prefill next time.
	update_user_meta( get_current_user_id(), 'fcmc_car_profiles', $clean_cars );

	wp_safe_redirect( wc_get_checkout_url() );
	exit;
} );

/* -------------------------------------------------------------------------
 * 6. Persist per-car detail to the order as hidden meta (admin roster use).
 *    Deliberately NOT shown on cart/checkout/receipt — customer only needs
 *    to see "N x FCMC Annual Membership". Visible in wp-admin order edit
 *    screen and via order meta for a future roster view.
 * ---------------------------------------------------------------------- */

add_action( 'woocommerce_checkout_create_order_line_item', function ( $item, $cart_item_key, $values, $order ) {
	if ( empty( $values['fcmc_cars_data'] ) ) {
		return;
	}
	$car_fields = fcmc_car_fields();
	$readable   = array();
	foreach ( $values['fcmc_cars_data'] as $i => $car ) {
		$parts = array();
		foreach ( $car as $key => $value ) {
			if ( '' === $value || ! isset( $car_fields[ $key ] ) ) {
				continue;
			}
			if ( 'car_generation' === $key ) {
				$value = $car_fields[ $key ]['options'][ $value ] ?? $value;
			}
			$parts[] = $car_fields[ $key ]['label'] . ': ' . $value;
		}
		$readable[] = sprintf( 'Car %d — %s', $i + 1, implode( ', ', $parts ) );
	}
	// Underscore-prefixed meta: WooCommerce's OWN default behavior (verified against
	// core, not assumed) hides '_'-prefixed order item meta from the customer-facing
	// thank-you page and emails (get_formatted_meta_data() default hideprefix = '_'),
	// while the wp-admin Edit Order screen explicitly requests get_all_formatted_meta_data('')
	// — empty hideprefix — so it shows everything, including these. No custom filter needed.
	$item->add_meta_data( '_fcmc_cars_data', $values['fcmc_cars_data'], true );
	$item->add_meta_data( '_fcmc_cars_summary', implode( ' | ', $readable ), true );
}, 10, 4 );
