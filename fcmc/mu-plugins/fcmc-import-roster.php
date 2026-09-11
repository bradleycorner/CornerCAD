<?php
/**
 * Plugin Name: FCMC Roster Import
 * Description: One-off WP-CLI importer for the 2026 roster. Separate from live code so it
 *              can be removed after the migration. Reads CSVs from an explicit path —
 *              member PII must never live in the repository.
 *
 * @see docs/superpowers/specs/2026-09-10-fcmc-roster-import-design.md
 */

if ( ! defined( 'ABSPATH' ) || ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

WP_CLI::add_command( 'fcmc import-roster', function ( $args, $assoc ) {
	list( $forms_path, $orders_path ) = $args + array( '', '' );
	$dry = ! empty( $assoc['dry-run'] );

	foreach ( array( $forms_path, $orders_path ) as $p ) {
		if ( ! is_readable( $p ) ) {
			WP_CLI::error( "Cannot read: {$p}" );
		}
	}

	$read = function ( $path ) {
		$rows = array();
		$fh   = fopen( $path, 'r' );
		$head = fgetcsv( $fh );
		while ( ( $r = fgetcsv( $fh ) ) !== false ) {
			$rows[] = array_combine( $head, array_pad( $r, count( $head ), '' ) );
		}
		fclose( $fh );
		return $rows;
	};

	$forms  = $read( $forms_path );
	$orders = $read( $orders_path );
	$batch  = gmdate( 'c' );

	// Payment index: normalised email => earliest and latest paid dates.
	$pay    = array();
	$noMail = 0;
	foreach ( $orders as $o ) {
		$e = fcmc_normalise_email( $o['email'] ?? '' );
		if ( '' === $e ) { $noMail++; continue; }
		$d = substr( trim( $o['paid_date'] ), 0, 10 );
		if ( ! isset( $pay[ $e ] ) ) {
			$pay[ $e ] = array( 'first' => $d, 'last' => $d );
		}
		$pay[ $e ]['first'] = min( $pay[ $e ]['first'], $d );
		$pay[ $e ]['last']  = max( $pay[ $e ]['last'], $d );
	}

	$created = $updated = $merged = 0;
	$seen    = array();

	$upsert = function ( $key_email, $data, $source ) use ( &$created, &$updated, &$merged, &$seen, $batch, $dry ) {
		$key_email = fcmc_normalise_email( $key_email );
		if ( isset( $seen[ $key_email ] ) ) {
			$merged++;
			WP_CLI::warning( "Merged duplicate row for a household (email repeated across rows)." );
			$id = $seen[ $key_email ];
		} else {
			$id = null;
			foreach ( fcmc_household_all() as $hid ) {
				if ( fcmc_normalise_email( get_post_meta( $hid, 'member1_email', true ) ) === $key_email ) {
					$id = $hid;
					break;
				}
			}
		}

		if ( $dry ) {
			$id ? $updated++ : $created++;
			return;
		}

		if ( ! $id ) {
			// post_author is set explicitly (never left to default) so that WordPress's
			// map_meta_cap() "current user is the post author" branch can never grant
			// edit rights to a member record independently of the fcmc_household
			// capability grants. The title must never contain an email address (visible
			// in wp-admin list tables) — fall back to a non-identifying placeholder for
			// payment-only rows that have no name on file.
			$id = wp_insert_post( array(
				'post_type'   => 'fcmc_household',
				'post_status' => 'publish',
				'post_title'  => '' !== $data['member1_name'] ? $data['member1_name'] : sprintf( 'Household #%s', substr( md5( $key_email ), 0, 8 ) ),
				'post_author' => 0,
			) );
			$created++;
		} else {
			$updated++;
		}

		foreach ( $data as $k => $v ) {
			update_post_meta( $id, $k, $v );
		}
		update_post_meta( $id, 'fcmc_source', $source );
		update_post_meta( $id, 'import_batch', $batch );
		$seen[ $key_email ] = $id;
	};

	// 1. Form-based households.
	foreach ( $forms as $f ) {
		$e1  = fcmc_normalise_email( $f['email1'] ?? '' );
		$e2  = fcmc_normalise_email( $f['email2'] ?? '' );
		$hit = $pay[ $e1 ] ?? $pay[ $e2 ] ?? null;

		$carText = trim( (string) ( $f['car'] ?? '' ) );
		$year    = preg_match( '/^\s*((?:19|20)\d{2})/', $carText, $m ) ? $m[1] : '';

		$data = array(
			'member1_name'  => trim( $f['member1'] ?? '' ),
			'member1_phone' => trim( $f['phone1'] ?? '' ),
			'member1_email' => $e1,
			'member2_name'  => trim( $f['member2'] ?? '' ),
			'member2_phone' => trim( $f['phone2'] ?? '' ),
			'member2_email' => $e2,
			'address'       => trim( $f['address'] ?? '' ),
			'city'          => trim( $f['city'] ?? '' ),
			'state'         => trim( $f['state'] ?? '' ),
			'zip'           => trim( $f['zip'] ?? '' ),
			// One car per household. The $60 payer is corrected by hand — the car field
			// is free text and splitting it on punctuation produces nonsense.
			'cars'          => array( array( 'car_model_year' => $year, 'car_description' => $carText ) ),
			'paid_through'  => $hit ? fcmc_paid_through( new DateTimeImmutable( $hit['last'], wp_timezone() ) )->format( 'Y-m-d' ) : '',
			'member_since'  => $hit['first'] ?? '',
		);

		$upsert( $e1 ?: $e2, $data, $hit ? 'form+payment' : 'form-only' );
	}

	// 2. Payment-only households.
	$formEmails = array();
	foreach ( $forms as $f ) {
		foreach ( array( 'email1', 'email2' ) as $k ) {
			$n = fcmc_normalise_email( $f[ $k ] ?? '' );
			if ( $n ) { $formEmails[ $n ] = true; }
		}
	}
	foreach ( $pay as $email => $d ) {
		if ( isset( $formEmails[ $email ] ) ) {
			continue;
		}
		$upsert( $email, array(
			'member1_name'  => '',
			'member1_email' => $email,
			'cars'          => array(),
			'paid_through'  => fcmc_paid_through( new DateTimeImmutable( $d['last'], wp_timezone() ) )->format( 'Y-m-d' ),
			'member_since'  => $d['first'],
		), 'payment-only' );
	}

	WP_CLI::log( sprintf( '%screated %d, updated %d, merged %d', $dry ? '[DRY RUN] ' : '', $created, $updated, $merged ) );
	WP_CLI::log( sprintf( 'payments with no email (need a human pass against Square): %d', $noMail ) );
	WP_CLI::success( $dry ? 'Dry run complete — nothing written.' : 'Import complete.' );
} );
