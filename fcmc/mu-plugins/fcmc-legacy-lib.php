<?php
/**
 * Plugin Name: FCMC Legacy Import — Library
 * Description: Pure functions behind `wp fcmc import-legacy` (fcmc-import-roster.php): reading the
 *              1991–2020 membership sheet, translating rows, merging duplicates and deciding what to
 *              do with each row. No WordPress calls, so it is unit-tested locally with plain `php`
 *              (fcmc/tests/test-legacy-lib.php). Removable after the migration.
 *
 * @see docs/superpowers/specs/2026-10-04-fcmc-legacy-member-import-design.md
 */

if ( ! defined( 'ABSPATH' ) && ! defined( 'FCMC_TESTING' ) ) {
	return;
}

const FCMC_LEGACY_SOURCE = 'legacy-2020';

/**
 * Read a CSV into assoc rows keyed by trimmed header. Strips Excel/Sheets' UTF-8 BOM, which
 * otherwise corrupts the first header (see the same fix in fcmc-import-roster.php).
 */
function fcmc_legacy_read_csv( string $path ): array {
	$fh = fopen( $path, 'r' );
	if ( false === $fh ) {
		return array();
	}
	$head = fgetcsv( $fh, length: null, escape: '\\' );
	if ( ! is_array( $head ) ) {
		fclose( $fh );
		return array();
	}
	$head[0] = preg_replace( '/^\xEF\xBB\xBF/', '', (string) $head[0] );
	$head    = array_map( 'trim', $head );
	$n       = count( $head );
	$rows    = array();
	while ( ( $r = fgetcsv( $fh, length: null, escape: '\\' ) ) !== false ) {
		$r      = array_pad( array_slice( $r, 0, $n ), $n, '' );
		$rows[] = array_combine( $head, array_map( 'trim', array_map( 'strval', $r ) ) );
	}
	fclose( $fh );
	return $rows;
}

/** `m/d/Y` or `m/d/yy` (yy read as 20yy) → `Y-m-d`; anything else → null. Never guesses. */
function fcmc_legacy_parse_date( $raw ): ?string {
	$s = trim( (string) $raw );
	if ( ! preg_match( '#^(\d{1,2})/(\d{1,2})/(\d{2}|\d{4})$#', $s, $m ) ) {
		return null;
	}
	$y = (int) $m[3] + ( 2 === strlen( $m[3] ) ? 2000 : 0 );
	if ( ! checkdate( (int) $m[1], (int) $m[2], $y ) ) {
		return null;
	}
	return sprintf( '%04d-%02d-%02d', $y, (int) $m[1], (int) $m[2] );
}

/** First YYYY-06-01 on or after the expiry date — the site's paid-through convention. */
function fcmc_legacy_paid_through( string $expiry_ymd ): string {
	$y    = (int) substr( $expiry_ymd, 0, 4 );
	$june = sprintf( '%04d-06-01', $y );
	return $expiry_ymd <= $june ? $june : sprintf( '%04d-06-01', $y + 1 );
}

/** Miata generation from model year; '' when unknown or out of range. */
function fcmc_legacy_generation( $year ): string {
	$y = (int) $year;
	if ( $y < 1989 || $y > 2100 ) {
		return '';
	}
	if ( $y <= 1997 ) {
		return 'NA';
	}
	if ( $y <= 2005 ) {
		return 'NB';
	}
	return $y <= 2015 ? 'NC' : 'ND';
}

/** "Call name (Nickname)"; either alone; '' when neither. */
function fcmc_legacy_car_name( string $call, string $tag ): string {
	$call = trim( $call );
	$tag  = trim( $tag );
	if ( '' !== $call && '' !== $tag ) {
		return "{$call} ({$tag})";
	}
	return '' !== $call ? $call : $tag;
}

/** YES → '1'; NO or blank → '' (blank = never agreed). Same values as the signup form. */
function fcmc_legacy_consent( $raw ): string {
	return 'YES' === strtoupper( trim( (string) $raw ) ) ? '1' : '';
}

/** RoadRunner newsletter: D → digital, P → paper, else ''. */
function fcmc_legacy_newsletter( $raw ): string {
	$v = strtoupper( trim( (string) $raw ) );
	return 'D' === $v ? 'digital' : ( 'P' === $v ? 'paper' : '' );
}

/** Lowercased email if it looks like one, else ''. */
function fcmc_legacy_email( $raw ): string {
	$e = strtolower( trim( (string) $raw ) );
	return preg_match( '/^[^@\s]+@[^@\s]+\.[^@\s]+$/', $e ) ? $e : '';
}

/** Sheet date → the signup form's MM/YYYY purchase-date format. */
function fcmc_legacy_purchase_date( $raw ): string {
	$d = fcmc_legacy_parse_date( $raw );
	return null === $d ? '' : substr( $d, 5, 2 ) . '/' . substr( $d, 0, 4 );
}

/** Car 1 or car 2 from a sheet row, in fcmc_car_profiles shape; null when the row has no such car. */
function fcmc_legacy_car( array $r, int $n ): ?array {
	$cols = 1 === $n
		? array( 'year' => 'YRMANF', 'pkg' => 'PKG_EDIT', 'body' => 'COLOR', 'top' => 'Top COLOR', 'call' => 'STATS_CAR/CALL NAME', 'tag' => 'TAG', 'buy' => '' )
		: array( 'year' => 'YRMANF 2', 'pkg' => 'PKG_EDIT 2', 'body' => 'COLORB 2', 'top' => 'COLORT 2', 'call' => 'CAR/CALL NAME 2', 'tag' => 'TAG 2', 'buy' => 'PURCH_DATE 2' );
	$v = function ( string $col ) use ( $r ): string {
		return '' === $col ? '' : trim( (string) ( $r[ $col ] ?? '' ) );
	};

	$year = preg_match( '/^\d{4}$/', $v( $cols['year'] ) ) ? $v( $cols['year'] ) : '';
	if ( '' === $year && '' === $v( $cols['body'] ) ) {
		return null;
	}

	return array(
		'car_model_year'    => $year,
		'car_generation'    => fcmc_legacy_generation( $year ),
		'car_package'       => $v( $cols['pkg'] ),
		'car_color_body'    => $v( $cols['body'] ),
		'car_color_top'     => $v( $cols['top'] ),
		'car_purchase_date' => fcmc_legacy_purchase_date( $v( $cols['buy'] ) ),
		'car_name'          => fcmc_legacy_car_name( $v( $cols['call'] ), $v( $cols['tag'] ) ),
	);
}

/** Officer-only history fields, filled only when empty on an existing household. */
function fcmc_legacy_history_keys(): array {
	return array( 'legacy_member_id', 'home_area', 'birthdays', 'directory_listed', 'legacy_stat' );
}

/** "First Middle Last" → "last|first middle" (lowercased) — the same shape map_row produces. */
function fcmc_legacy_name_key_from_full( string $full ): string {
	$parts = preg_split( '/\s+/', strtolower( trim( $full ) ) );
	if ( ! $parts || '' === $parts[0] ) {
		return '';
	}
	$last = array_pop( $parts );
	return $last . '|' . implode( ' ', $parts );
}

/**
 * Translate one sheet row. Never throws. The row key is `id:<MEMBER_ID>`, or — for rows added
 * in 2019–2020 without an ID — `r:<8 hex>`, a hash that carries no PII but is stable across runs.
 */
function fcmc_legacy_map_row( array $r ): array {
	$col   = function ( string $k ) use ( $r ): string {
		return trim( (string) ( $r[ $k ] ?? '' ) );
	};
	$last  = $col( 'LastName1' );
	$first = $col( 'FirstName1' );
	$id    = $col( 'MEMBER_ID' );
	$warn  = array();

	$e1 = fcmc_legacy_email( $col( 'PERSONAL_E-MAIL ADDRESS' ) );
	$e2 = fcmc_legacy_email( $col( 'E-MAIL ADDRESS 2' ) );
	if ( '' === $e1 && '' !== $col( 'PERSONAL_E-MAIL ADDRESS' ) ) {
		$warn[] = 'bad-email-1';
	}
	if ( '' === $e2 && '' !== $col( 'E-MAIL ADDRESS 2' ) ) {
		$warn[] = 'bad-email-2';
	}
	$emails = array_values( array_unique( array_filter( array( $e1, $e2 ) ) ) );

	$out = array(
		'skip'      => null,
		'key'       => '',
		'legacy_id' => $id,
		'emails'    => $emails,
		'name_key'  => strtolower( $last . '|' . $first ),
		'warnings'  => array(),
		'data'      => array(),
	);

	if ( '' === $last ) {
		$out['skip'] = 'no-name';
		return $out;
	}
	if ( empty( $emails ) && '' === $col( 'PERSONAL_ADDRESS' ) ) {
		$out['skip'] = 'no-contact';
		return $out;
	}

	$out['key'] = '' !== $id
		? 'id:' . $id
		: 'r:' . substr( md5( $emails ? $emails[0] : $out['name_key'] ), 0, 8 );

	$dates = array();
	foreach ( array( 'ORIG_ENTRY', 'JOIN_DATE' ) as $k ) {
		$d = fcmc_legacy_parse_date( $col( $k ) );
		if ( null === $d && '' !== $col( $k ) ) {
			$warn[] = 'bad-date-' . $k;
		}
		if ( null !== $d ) {
			$dates[] = $d;
		}
	}
	$exp = fcmc_legacy_parse_date( $col( 'EXP DATE' ) );
	if ( null === $exp && '' !== $col( 'EXP DATE' ) ) {
		$warn[] = 'bad-date-EXP DATE';
	}
	if ( null !== $exp && '05-31' !== substr( $exp, 5 ) ) {
		$warn[] = 'expiry-not-5/31';
	}

	$address = $col( 'PERSONAL_ADDRESS' );
	if ( '' !== $col( 'APT' ) ) {
		$address .= ', ' . $col( 'APT' );
	}
	$dir = strtoupper( $col( 'Directory' ) );

	$out['warnings'] = $warn;
	$out['data']     = array(
		'member1_name'                   => trim( "{$first} {$last}" ),
		'member1_phone'                  => $col( 'PRIMARY PHONE' ),
		'member1_email'                  => $e1,
		'member2_name'                   => trim( $col( 'FirstName2' ) . ' ' . $col( 'LastName2' ) ),
		'member2_phone'                  => '',
		'member2_email'                  => $e2,
		'address'                        => $address,
		'city'                           => $col( 'CITY' ),
		'state'                          => $col( 'STATE' ),
		'zip'                            => $col( 'ZIP' ),
		'cars'                           => array_values( array_filter( array( fcmc_legacy_car( $r, 1 ), fcmc_legacy_car( $r, 2 ) ) ) ),
		'paid_through'                   => null === $exp ? '' : fcmc_legacy_paid_through( $exp ),
		'member_since'                   => $dates ? min( $dates ) : '',
		'legacy_member_id'               => $id,
		'home_area'                      => $col( 'HOMEAREA' ),
		'birthdays'                      => $col( 'Birthdays' ),
		'directory_listed'               => in_array( $dir, array( 'Y', 'N' ), true ) ? $dir : '',
		'legacy_stat'                    => $col( 'STAT' ),
		'fcmc_consent_directory_name'    => fcmc_legacy_consent( $col( 'NAME(S)' ) ),
		'fcmc_consent_directory_address' => fcmc_legacy_consent( $col( 'REGISTRY_ADDRESS' ) ),
		'fcmc_consent_directory_phone'   => fcmc_legacy_consent( $col( 'PHONE NUMBER' ) ),
		'fcmc_consent_directory_email'   => fcmc_legacy_consent( $col( 'REGISTRY_E-MAIL ADDRESS' ) ),
		'fcmc_consent_directory_car'     => fcmc_legacy_consent( $col( 'CAR INFORMATION' ) ),
		'fcmc_consent_public_name'       => fcmc_legacy_consent( $col( 'HOMEPAGE' ) ),
		'fcmc_newsletter_pref'           => fcmc_legacy_newsletter( $col( 'RoadRunner' ) ),
	);

	return $out;
}
