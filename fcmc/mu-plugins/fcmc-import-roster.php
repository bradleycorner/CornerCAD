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

	// Accept a paid_date only if it round-trips through Y-m-d exactly — this rejects both
	// unparseable strings AND out-of-range values that createFromFormat() would otherwise
	// silently roll over (e.g. a nonexistent day). A row that fails this is skipped, never
	// allowed to reach `new DateTimeImmutable()` uncaught and abort the run mid-loop.
	$validate_ymd = function ( $raw ) {
		$s = substr( trim( (string) $raw ), 0, 10 );
		$d = DateTimeImmutable::createFromFormat( 'Y-m-d', $s );
		if ( ! ( $d instanceof DateTimeImmutable ) || $d->format( 'Y-m-d' ) !== $s ) {
			return null;
		}
		return $s;
	};

	$forms  = $read( $forms_path );
	$orders = $read( $orders_path );
	$batch  = gmdate( 'c' );

	// Payment index: normalised email => earliest and latest paid dates. Every date in here
	// is already canonical Y-m-d (validated above), so plain string min()/max() below is a
	// safe stand-in for chronological comparison — ISO 8601 dates sort lexicographically.
	$pay      = array();
	$noMail   = 0;
	$badDates = 0;
	foreach ( $orders as $o ) {
		$e = fcmc_normalise_email( $o['email'] ?? '' );
		if ( '' === $e ) { $noMail++; continue; }
		$d = $validate_ymd( $o['paid_date'] ?? '' );
		if ( null === $d ) { $badDates++; continue; }
		if ( ! isset( $pay[ $e ] ) ) {
			$pay[ $e ] = array( 'first' => $d, 'last' => $d );
		}
		$pay[ $e ]['first'] = min( $pay[ $e ]['first'], $d );
		$pay[ $e ]['last']  = max( $pay[ $e ]['last'], $d );
	}

	$created     = $updated = $merged = 0;
	$conflicts   = 0;
	$backfilled  = 0;
	$noFormEmail = 0;
	$rowErrors   = 0;
	$seen        = array();

	// Households with no import_values snapshot get backfilled at most once per run, even
	// if a merge touches the same post twice, so a --dry-run (which never writes the
	// backfilled snapshot back to the DB) can't double-count the same household.
	$backfilled_ids = array();

	// Compares a household's CURRENT stored values against `import_values` — the snapshot
	// of what THIS importer last wrote — so a field an officer has hand-edited since (e.g.
	// correcting a merged household's car description, which the design doc expects) is
	// never silently clobbered by a re-run.
	//
	// A household with NO recorded baseline (every one of the 42 live records predates this
	// snapshot, though they were all demonstrably written by this importer — they carry
	// fcmc_source/import_batch) is backfilled HERE, in the same pass, before any comparison:
	// the baseline is set to THIS run's freshly computed incoming values, never to whatever
	// happens to be currently stored. That distinction matters — backfilling from "current"
	// verbatim would always trivially equal "current" and could never detect a conflict on
	// this very first run, which would mean the 42 existing households stay unprotected for
	// one more run (exactly the gap this fix exists to close). Backfilling from the incoming
	// values instead means: a field that already matches the incoming value is indistinguishable
	// either way (safe, overwritten as before); a field that does NOT match is flagged as a
	// conflict immediately and the presumed-correct incoming value is what's pinned as the
	// baseline going forward, so the conflict keeps being reported on every future run for as
	// long as the officer's value differs from the CSV, rather than silently resolving itself
	// one run later. (Trade-off: on this one bootstrap run only, a field that legitimately
	// changed in the source data since the original import — not hand-edited by anyone — would
	// also read as a "conflict" and be left alone rather than updated. That is a conservative
	// false positive surfaced to a human, not a silent wrong overwrite, which is the right side
	// to err on for a household nobody has looked at since the day it was created.)
	//
	// Used by both the dry-run prediction and the real write, so what --dry-run reports is
	// exactly what a real run will do.
	$classify_fields = function ( $id, $data ) use ( &$backfilled, &$backfilled_ids ) {
		$prev = get_post_meta( $id, 'import_values', true );
		if ( ! is_array( $prev ) ) {
			$prev = $data;
			if ( ! isset( $backfilled_ids[ $id ] ) ) {
				$backfilled_ids[ $id ] = true;
				$backfilled++;
			}
		}

		$clean      = array();
		$conflicted = array();
		foreach ( $data as $k => $v ) {
			$current        = get_post_meta( $id, $k, true );
			$baseline_known = array_key_exists( $k, $prev );
			if ( ! $baseline_known || $current === $prev[ $k ] ) {
				$clean[ $k ] = $v;
			} else {
				$conflicted[] = $k;
			}
		}
		return array( 'clean' => $clean, 'conflicted' => $conflicted, 'prev' => $prev );
	};

	// $seen tracks, per normalised email, the household this run has already resolved to
	// PLUS the source row it first appeared on — populated on BOTH the dry and real paths
	// so a --dry-run predicts merges exactly as a real run would produce them, rather than
	// treating every occurrence of a within-run duplicate email as a fresh "created" row.
	// In dry-run mode there is no real post ID yet, so a truthy placeholder (`true`) stands
	// in for "this row would create/match a household" — merge/updated-vs-created counting
	// only needs truthiness, never the actual ID.
	$upsert = function ( $key_email, $data, $source, $row = null ) use (
		&$created, &$updated, &$merged, &$seen, &$noFormEmail, &$conflicts,
		$classify_fields, $batch, $dry
	) {
		$key_email = fcmc_normalise_email( $key_email );

		// Reject an unusable key outright rather than let it fall through to the lookup
		// below. Without this, every row with both emails blank shares the SAME empty-
		// string key: the first creates a household with member1_email = '', and every
		// later blank-email row then matches it via $seen[''] and gets "merged" into it —
		// splicing unrelated households' names, phones and addresses together.
		if ( '' === $key_email ) {
			$noFormEmail++;
			WP_CLI::warning( sprintf( 'Row %s has no usable email address — skipped (needs a human pass).', $row ?? '?' ) );
			return;
		}

		$merged_this_call = isset( $seen[ $key_email ] );

		if ( $merged_this_call ) {
			$merged++;
			$first_row = $seen[ $key_email ]['row'];
			if ( $first_row && $row ) {
				WP_CLI::warning( sprintf( 'Merge: rows %d and %d share an email (household merged).', $first_row, $row ) );
			} else {
				WP_CLI::warning( 'Merged duplicate row for a household (email repeated across rows).' );
			}
			// Reuse the FIRST occurrence's post ID and whether it's a REAL, already-existing
			// database post — never inferred from the type of $id. In a dry run, a
			// household not yet in the database gets a truthy non-integer placeholder for
			// $id; is_int() on that placeholder reads as "new" even on the household's
			// SECOND (merge) occurrence within the same run, undercounting it as created++
			// again instead of updated++ — a real run's second occurrence always finds the
			// post the first occurrence just inserted, so a dry run must count it the same
			// way to agree with what the real run will actually produce.
			$id      = $seen[ $key_email ]['id'];
			$real_id = $seen[ $key_email ]['real'];
		} else {
			// Matches on EITHER stored member1_email OR member2_email, normalised — never
			// member1_email alone. The upsert key is `$e1 ?: $e2` (a household with a blank
			// email1 but a populated email2 is keyed on $e2), but $data always writes $e1
			// into member1_email. A member1-only lookup would never find that household
			// again on a re-run and would silently create a duplicate every time.
			//
			// Deliberately NOT fcmc_household_find_by_email(): that helper skips CLAIMED
			// households by design (it exists for signup-matching), which here would
			// create a duplicate for any household a member has already claimed instead
			// of updating it. This importer must see every household, claimed or not.
			$id = null;
			foreach ( fcmc_household_all() as $hid ) {
				$stored_e1 = fcmc_normalise_email( get_post_meta( $hid, 'member1_email', true ) );
				$stored_e2 = fcmc_normalise_email( get_post_meta( $hid, 'member2_email', true ) );
				if ( $key_email === $stored_e1 || $key_email === $stored_e2 ) {
					$id = $hid;
					break;
				}
			}
			$real_id = ( null !== $id );
		}

		if ( $dry ) {
			// Counting is deliberately separate from whether $id is a REAL post:
			// - a merge (this key already resolved once this run) always counts as an
			//   update, whether or not that first resolution was itself a real database
			//   post yet — exactly what the real run's second occurrence would see, since
			//   by then the first occurrence has already inserted it.
			// - the conflict check, however, only ever runs against a genuine post ID:
			//   there is nothing in the database yet to compare a same-run placeholder
			//   against, and calling get_post_meta() on the placeholder value would silently
			//   read whichever unrelated post happens to have that coerced integer ID.
			$count_as_update = $merged_this_call || $real_id;
			if ( $count_as_update ) {
				$updated++;
				if ( $real_id ) {
					$c = $classify_fields( $id, $data );
					foreach ( $c['conflicted'] as $k ) {
						$conflicts++;
						WP_CLI::warning( sprintf( '[DRY RUN] Conflict: field %s on household #%d differs from the import; would be left as-is.', $k, $id ) );
					}
				}
			} else {
				$created++;
			}
			$seen[ $key_email ] = array( 'id' => $id ?: true, 'row' => $row, 'real' => $real_id );
			return;
		}

		if ( ! $real_id ) {
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
			foreach ( $data as $k => $v ) {
				update_post_meta( $id, $k, $v );
			}
			update_post_meta( $id, 'import_values', $data );
		} else {
			$updated++;
			$c = $classify_fields( $id, $data );
			foreach ( $c['clean'] as $k => $v ) {
				update_post_meta( $id, $k, $v );
			}
			foreach ( $c['conflicted'] as $k ) {
				$conflicts++;
				WP_CLI::warning( sprintf( 'Conflict: field %s on household #%d differs from the import; left as-is.', $k, $id ) );
			}
			$snapshot = $c['prev'];
			foreach ( $c['clean'] as $k => $v ) {
				$snapshot[ $k ] = $v;
			}
			update_post_meta( $id, 'import_values', $snapshot );
		}

		update_post_meta( $id, 'fcmc_source', $source );
		update_post_meta( $id, 'import_batch', $batch );
		$seen[ $key_email ] = array( 'id' => $id, 'row' => $row, 'real' => true );
	};

	// 1. Form-based households. $i + 2 = 1-based CSV row number counting the header as
	// row 1, so it matches what a human sees opening the file in a spreadsheet — used only
	// to report which rows merged, never the data itself. Merges can only happen within
	// this loop: the payment-only loop below skips any email already seen here, so no
	// email can appear in both loops, and $pay is keyed by email so it holds no duplicates
	// of its own.
	//
	// Each row is isolated in a try/catch so one bad row (an unexpected exception building
	// its data, not merely a malformed date — those are already filtered out above) is
	// skipped and counted rather than aborting the run mid-loop with no summary.
	foreach ( $forms as $i => $f ) {
		try {
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

			$upsert( $e1 ?: $e2, $data, $hit ? 'form+payment' : 'form-only', $i + 2 );
		} catch ( \Throwable $ex ) {
			$rowErrors++;
			WP_CLI::warning( sprintf( 'Row %d could not be processed and was skipped (needs a human pass).', $i + 2 ) );
		}
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
		try {
			$upsert( $email, array(
				'member1_name'  => '',
				'member1_email' => $email,
				'cars'          => array(),
				'paid_through'  => fcmc_paid_through( new DateTimeImmutable( $d['last'], wp_timezone() ) )->format( 'Y-m-d' ),
				'member_since'  => $d['first'],
			), 'payment-only' );
		} catch ( \Throwable $ex ) {
			$rowErrors++;
			WP_CLI::warning( 'A payment-only row could not be processed and was skipped (needs a human pass).' );
		}
	}

	// Each merge is counted once as created/updated (the first occurrence of the email) and
	// once again as updated (the duplicate occurrence, via $seen) — so it inflates
	// created+updated by exactly 1 per merge. Subtracting $merged gives the number of
	// distinct households this run actually resolves to, in both dry and real runs alike.
	$final = $created + $updated - $merged;
	WP_CLI::log( sprintf( '%screated %d, updated %d, merged %d -> %d households after this run', $dry ? '[DRY RUN] ' : '', $created, $updated, $merged, $final ) );
	WP_CLI::log( sprintf( 'baseline backfilled for %d household(s) with no prior snapshot', $backfilled ) );
	WP_CLI::log( sprintf( 'conflicts (officer edits preserved, not overwritten): %d', $conflicts ) );
	WP_CLI::log( sprintf( '%d form rows with no email address (cannot be imported, need a human pass)', $noFormEmail ) );
	WP_CLI::log( sprintf( 'payments with no email (need a human pass against Square): %d', $noMail ) );
	WP_CLI::log( sprintf( '%d payments with an unparseable date (need a human pass)', $badDates ) );
	if ( $rowErrors > 0 ) {
		WP_CLI::log( sprintf( '%d rows failed unexpectedly and were skipped (needs a human pass)', $rowErrors ) );
	}
	WP_CLI::success( $dry ? 'Dry run complete — nothing written.' : 'Import complete.' );
} );
