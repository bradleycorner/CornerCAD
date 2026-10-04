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

/** Earlier of two Y-m-d strings, ignoring empties. */
function fcmc_legacy_min_date( string $a, string $b ): string {
	if ( '' === $a ) {
		return $b;
	}
	return '' === $b ? $a : min( $a, $b );
}

/** Merge a source row into a destination row; return the merged destination. */
function fcmc_legacy_merge_into( array $keep, array $row ): array {
	foreach ( $row['data'] as $k => $v ) {
		if ( 'member_since' === $k ) {
			$keep['data'][ $k ] = fcmc_legacy_min_date( (string) $keep['data'][ $k ], (string) $v );
		} elseif ( 'paid_through' === $k ) {
			$keep['data'][ $k ] = max( (string) $keep['data'][ $k ], (string) $v );
		} elseif ( ( '' === $keep['data'][ $k ] || array() === $keep['data'][ $k ] ) && '' !== $v && array() !== $v ) {
			$keep['data'][ $k ] = $v;
		}
	}
	$keep['emails']   = array_values( array_unique( array_merge( $keep['emails'], $row['emails'] ) ) );
	$keep['warnings'] = array_merge( $keep['warnings'], $row['warnings'] );
	return $keep;
}

/**
 * Merge rows that share any email (the same person entered twice). Earliest member_since,
 * latest paid_through, first non-empty value for everything else. Skipped rows pass through.
 * Transitive: rows A(a), B(b), C(a,b) merge into one. Merge all hits into earliest hit row.
 */
function fcmc_legacy_merge_duplicates( array $rows ): array {
	$out      = array();
	$merges   = array();
	$by_email = array();

	foreach ( $rows as $row ) {
		if ( null !== $row['skip'] ) {
			$out[] = $row;
			continue;
		}

		// Find all distinct output rows that share an email with this row.
		$hits = array();
		foreach ( $row['emails'] as $e ) {
			if ( isset( $by_email[ $e ] ) ) {
				$idx      = $by_email[ $e ];
				$hits[$idx] = true;
			}
		}
		$hits = array_keys( $hits );

		if ( empty( $hits ) ) {
			// No email match; add as new row.
			$out[] = $row;
			$i     = count( $out ) - 1;
			foreach ( $row['emails'] as $e ) {
				$by_email[ $e ] = $i;
			}
			continue;
		}

		// Merge all hit rows and incoming row into the earliest hit row.
		sort( $hits );
		$keep_idx = $hits[0];
		$keep     = $out[ $keep_idx ];

		// Record merge of incoming row first.
		$merges[] = array( $keep['key'], $row['key'] );
		$keep     = fcmc_legacy_merge_into( $keep, $row );

		// Then merge other hit rows into the kept row.
		foreach ( array_slice( $hits, 1 ) as $hit_idx ) {
			$merges[] = array( $keep['key'], $out[ $hit_idx ]['key'] );
			$keep     = fcmc_legacy_merge_into( $keep, $out[ $hit_idx ] );
		}

		// Update kept row in output.
		$out[ $keep_idx ] = $keep;

		// Repoint all emails (from kept, absorbed, and incoming rows) to kept row.
		foreach ( $keep['emails'] as $e ) {
			$by_email[ $e ] = $keep_idx;
		}

		// Remove absorbed rows (other hit rows) from $out and repoint indices.
		$absorbed = array_slice( $hits, 1 );
		rsort( $absorbed );
		foreach ( $absorbed as $hit_idx ) {
			unset( $out[ $hit_idx ] );
		}
		$out = array_values( $out );

		// Update $by_email to reflect new indices.
		$by_email = array();
		foreach ( $out as $i => $r ) {
			if ( null === $r['skip'] ) {
				foreach ( $r['emails'] as $e ) {
					$by_email[ $e ] = $i;
				}
			}
		}

		// Adjust keep_idx to new position.
		$keep_idx = array_search( $keep, $out, true );
	}

	return array( 'rows' => $out, 'merges' => $merges );
}

/** Overrides CSV (key,action,target) → valid map + list of rejected keys. */
function fcmc_legacy_parse_overrides( array $rows ): array {
	$valid   = array();
	$invalid = array();
	foreach ( $rows as $r ) {
		$key    = trim( (string) ( $r['key'] ?? '' ) );
		$action = trim( (string) ( $r['action'] ?? '' ) );
		$target = (int) ( $r['target'] ?? 0 );
		if ( '' === $key ) {
			continue;
		}
		$ok = 'skip' === $action
			|| ( in_array( $action, array( 'link-household', 'link-user' ), true ) && $target > 0 );
		if ( ! $ok ) {
			$invalid[] = $key;
			continue;
		}
		$valid[ $key ] = array( 'action' => $action, 'target' => 'skip' === $action ? 0 : $target );
	}
	return array( 'valid' => $valid, 'invalid' => $invalid );
}

/** One action per row. Email-only matching; overrides beat matching; names only ever reported. */
function fcmc_legacy_decide( array $rows, array $email_index, array $name_index, array $overrides ): array {
	$name_counts = array();
	foreach ( $rows as $row ) {
		if ( null === $row['skip'] ) {
			$name_counts[ $row['name_key'] ] = ( $name_counts[ $row['name_key'] ] ?? 0 ) + 1;
		}
	}

	$actions = array();
	foreach ( $rows as $row ) {
		$a = array(
			'type'     => 'skip',
			'target'   => 0,
			'reason'   => '',
			'targets'  => array(),
			'near'     => array(),
			'dup_name' => false,
			'override' => false,
			'row'      => $row,
		);

		if ( null !== $row['skip'] ) {
			$a['reason'] = $row['skip'];
			$actions[]   = $a;
			continue;
		}
		$a['dup_name'] = ( $name_counts[ $row['name_key'] ] ?? 0 ) > 1;

		if ( isset( $overrides[ $row['key'] ] ) ) {
			$ov            = $overrides[ $row['key'] ];
			$a['override'] = true;
			if ( 'skip' === $ov['action'] ) {
				$a['reason'] = 'override';
			} else {
				$a['type']   = 'link-household' === $ov['action'] ? 'enrich' : 'create_linked';
				$a['target'] = (int) $ov['target'];
			}
			$actions[] = $a;
			continue;
		}

		$targets = array();
		foreach ( $row['emails'] as $e ) {
			foreach ( $email_index[ $e ] ?? array() as $t ) {
				$targets[ $t ] = true;
			}
		}
		$targets = array_keys( $targets );

		if ( 0 === count( $targets ) ) {
			$a['type'] = 'create';
			$a['near'] = array_values( $name_index[ $row['name_key'] ] ?? array() );
		} elseif ( 1 === count( $targets ) ) {
			list( $kind, $id ) = explode( ':', $targets[0] );
			$a['type']         = 'h' === $kind ? 'enrich' : 'create_linked';
			$a['target']       = (int) $id;
		} else {
			$a['reason']  = 'ambiguous';
			$a['targets'] = $targets;
		}
		$actions[] = $a;
	}

	return $actions;
}

/** Human-readable, PII-free run report: counts, then one line per thing needing attention. */
function fcmc_legacy_report_lines( array $actions, array $merges, array $invalid_overrides ): array {
	$counts = array( 'create' => 0, 'create_linked' => 0, 'enrich' => 0, 'skip' => 0 );
	$lines  = array();
	foreach ( $actions as $a ) {
		$counts[ $a['type'] ]++;
		$key = '' !== $a['row']['key'] ? $a['row']['key'] : '(no key)';
		if ( 'skip' === $a['type'] ) {
			$lines[] = "skip {$key} {$a['reason']}" . ( $a['targets'] ? ' -> ' . implode( ',', $a['targets'] ) : '' );
		}
		if ( $a['near'] ) {
			$lines[] = "near-match {$key} -> " . implode( ',', $a['near'] );
		}
		if ( $a['dup_name'] ) {
			$lines[] = "same-name-in-sheet {$key}";
		}
		if ( $a['override'] && 'skip' !== $a['type'] ) {
			$lines[] = "override {$key} -> {$a['type']} {$a['target']}";
		}
		foreach ( $a['row']['warnings'] as $w ) {
			$lines[] = "warning {$key} {$w}";
		}
	}
	foreach ( $merges as $pair ) {
		$lines[] = "merged {$pair[0]} + {$pair[1]}";
	}
	foreach ( $invalid_overrides as $k ) {
		$lines[] = "invalid override {$k} (ignored)";
	}
	$head = array();
	foreach ( $counts as $type => $n ) {
		$head[] = "{$type}: {$n}";
	}
	return array_merge( $head, $lines );
}
