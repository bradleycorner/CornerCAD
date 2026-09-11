# FCMC Roster Import Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Get the club's 43 real households into the new site as officer-visible roster records, with no member accounts created, and make a member's future account attach to their record automatically.

**Architecture:** Households live in a `fcmc_household` CPT, independent of any WP user. A baseline date `fcmc_paid_through_manual` (set by import or by an officer) acts as a *floor*: the effective `paid_through` is `max(baseline, computed-from-orders)`, so a recompute can extend it but never erase it. When a member later registers, an email match links their user to the household and copies the membership facts across.

**Tech Stack:** WordPress mu-plugins (PHP 8.3), WooCommerce, WP-CLI. No build step. No PHP test framework in this repo — verification is assertion scripts run via `wp eval-file`, the pattern already used for the lifecycle code.

**Spec:** `docs/superpowers/specs/2026-09-10-fcmc-roster-import-design.md`

## Global Constraints

- **Member PII must never enter git.** Repo has a public remote. `.gitignore` already blocks `*-jobforms*.csv`, `*-paid-orders*.csv`, `*-members.csv`. The importer takes a path argument; it must not read a fixed in-repo location. Never paste member rows into commit messages or transcripts — counts and headers only.
- **Deploy target:** `scp fcmc/mu-plugins/*.php cornerfa:/home1/cornerfa/public_html/fcmc-dev/wp-content/mu-plugins/`. Git is the source of truth; never edit on the server.
- **WP-CLI invocation:** `cd /home1/cornerfa/public_html/fcmc-dev && /opt/cpanel/ea-php83/root/usr/bin/php /usr/local/bin/wp <cmd>`. All three parts load-bearing.
- **Term maths goes through `fcmc_paid_through()`** in `fcmc-membership-lifecycle.php`. Never reimplement it; the April cutoff must stay one knob (`fcmc_early_renewal_cutoff_month`).
- **Paid order statuses are `processing` and `completed` only.** `on-hold` is not paid.
- **Lint every PHP file before deploying:** `/opt/cpanel/ea-php83/root/usr/bin/php -l <file>`.
- **Source data:** `~/Desktop/temp/fcmc-jobforms-all.csv` (37 rows) and `~/Desktop/temp/fcmc-paid-orders-linked.csv` (42 rows). Expected result: **43 households**.

## File Structure

| File | Responsibility |
|---|---|
| `fcmc/mu-plugins/fcmc-membership-lifecycle.php` (modify) | Add the `max(baseline, computed)` rule to `fcmc_recompute_member()` and `fcmc_get_paid_through()` |
| `fcmc/mu-plugins/fcmc-households.php` (create) | The `fcmc_household` CPT, its meta accessors, and the registration→household linking |
| `fcmc/mu-plugins/fcmc-roster.php` (modify) | Render households rather than users; add the Needs-linking view and the officer date edit |
| `fcmc/mu-plugins/fcmc-import-roster.php` (create) | The WP-CLI importer. Separate file so it can be deleted after the migration without touching live code |

---

### Task 1: Baseline date becomes a floor

**Files:**
- Modify: `fcmc/mu-plugins/fcmc-membership-lifecycle.php`
- Test: `/tmp/fcmc-test-floor.php` (throwaway, not committed)

**Interfaces:**
- Consumes: `fcmc_paid_through()`, `fcmc_status_for()` (existing)
- Produces: `fcmc_get_baseline_paid_through( int $user_id ): ?DateTimeImmutable`, and the changed contract that `fcmc_recompute_member()` never deletes `fcmc_paid_through` when a baseline exists

- [ ] **Step 1: Write the failing test**

```php
<?php
// /tmp/fcmc-test-floor.php
$uid = 2; $fail = 0;
delete_user_meta( $uid, 'fcmc_paid_through_manual' );
delete_user_meta( $uid, 'fcmc_paid_through' );

// A member with a baseline and NO orders must keep the baseline.
update_user_meta( $uid, 'fcmc_paid_through_manual', '2027-06-01' );
fcmc_recompute_member( $uid );
$got = get_user_meta( $uid, 'fcmc_paid_through', true );
printf( "%s baseline survives recompute with no orders: %s\n", $got === '2027-06-01' ? 'PASS' : 'FAIL', $got ?: '(wiped)' );
$got === '2027-06-01' or $fail++;

$st = get_user_meta( $uid, 'fcmc_status', true );
printf( "%s status derived from baseline: %s\n", $st === 'active' ? 'PASS' : 'FAIL', $st );
$st === 'active' or $fail++;

// A baseline EARLIER than the orders must not win.
update_user_meta( $uid, 'fcmc_paid_through_manual', '2020-06-01' );
fcmc_recompute_member( $uid );
$got = get_user_meta( $uid, 'fcmc_paid_through', true );
printf( "%s earlier baseline does not override orders: %s\n", $got === '2020-06-01' ? 'PASS(no orders)' : 'check', $got );

delete_user_meta( $uid, 'fcmc_paid_through_manual' );
fcmc_recompute_member( $uid );
$got = get_user_meta( $uid, 'fcmc_paid_through', true );
printf( "%s no baseline and no orders clears the date: %s\n", $got === '' ? 'PASS' : 'FAIL', $got ?: '(empty)' );
$got === '' or $fail++;

echo $fail ? "\n*** {$fail} FAILURE(S) ***\n" : "\nall assertions passed\n";
```

- [ ] **Step 2: Run it and watch it fail**

```bash
scp /tmp/fcmc-test-floor.php cornerfa:/home1/cornerfa/
ssh cornerfa 'cd /home1/cornerfa/public_html/fcmc-dev && /opt/cpanel/ea-php83/root/usr/bin/php /usr/local/bin/wp eval-file /home1/cornerfa/fcmc-test-floor.php'
```

Expected: the first assertion FAILS — `fcmc_recompute_member()` currently deletes the key, so the baseline is wiped.

- [ ] **Step 3: Add the baseline accessor**

In `fcmc-membership-lifecycle.php`, after `fcmc_get_paid_through()`:

```php
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
```

- [ ] **Step 4: Make recompute honour the floor**

Replace the whole body of `fcmc_recompute_member()` with:

```php
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
```

- [ ] **Step 5: Lint, deploy, re-run the test**

```bash
/opt/cpanel/ea-php83/root/usr/bin/php -l fcmc/mu-plugins/fcmc-membership-lifecycle.php
scp fcmc/mu-plugins/fcmc-membership-lifecycle.php cornerfa:/home1/cornerfa/public_html/fcmc-dev/wp-content/mu-plugins/
ssh cornerfa 'cd /home1/cornerfa/public_html/fcmc-dev && /opt/cpanel/ea-php83/root/usr/bin/php /usr/local/bin/wp eval-file /home1/cornerfa/fcmc-test-floor.php'
```

Expected: `all assertions passed`.

- [ ] **Step 6: Re-run the existing lifecycle test to prove nothing regressed**

Re-run the term-calculation assertions from 2026-09-10 (spec's worked examples: 2026-09-02→2027-06-01, 2026-05-15→2027-06-01, 2026-04-15→2027-06-01, 2026-03-31→2026-06-01, plus grace day 59→grace / day 60→lapsed). Expected: all pass.

- [ ] **Step 7: Commit**

```bash
git add fcmc/mu-plugins/fcmc-membership-lifecycle.php
git commit -m "fix(fcmc): imported membership dates are a floor, not a cache

fcmc_recompute_member() deleted fcmc_paid_through for any user with no
WooCommerce orders. Every imported member paid on Square and has none, so
the first recompute would have wiped them and they'd be chased for dues
they had already paid.

paid_through is now max(fcmc_paid_through_manual, computed-from-orders)."
```

---

### Task 2: The household record

**Files:**
- Create: `fcmc/mu-plugins/fcmc-households.php`
- Test: `/tmp/fcmc-test-household.php`

**Interfaces:**
- Consumes: nothing from Task 1 at runtime
- Produces: post type `fcmc_household`; `fcmc_household_get( int $id ): array`; `fcmc_household_find_by_email( string $email ): ?int`; `fcmc_household_all( array $args = [] ): int[]`; meta keys `member1_name`, `member1_phone`, `member1_email`, `member2_name`, `member2_phone`, `member2_email`, `address`, `city`, `state`, `zip`, `cars`, `paid_through`, `member_since`, `fcmc_source`, `claimed_by`, `import_batch`

- [ ] **Step 1: Write the failing test**

```php
<?php
// /tmp/fcmc-test-household.php
$fail = 0;
printf( "%s post type registered\n", post_type_exists( 'fcmc_household' ) ? 'PASS' : 'FAIL' );
post_type_exists( 'fcmc_household' ) or $fail++;

$id = wp_insert_post( array( 'post_type' => 'fcmc_household', 'post_title' => 'Test Household', 'post_status' => 'publish' ) );
update_post_meta( $id, 'member1_email', 'Someone@Example.COM ' );
update_post_meta( $id, 'member2_email', 'other@example.com' );

$found = fcmc_household_find_by_email( 'someone@example.com' );
printf( "%s finds by member1_email, normalised\n", $found === $id ? 'PASS' : 'FAIL' );
$found === $id or $fail++;

$found2 = fcmc_household_find_by_email( '  OTHER@EXAMPLE.COM ' );
printf( "%s finds by member2_email, normalised\n", $found2 === $id ? 'PASS' : 'FAIL' );
$found2 === $id or $fail++;

printf( "%s unknown email returns null\n", fcmc_household_find_by_email( 'nobody@example.com' ) === null ? 'PASS' : 'FAIL' );
fcmc_household_find_by_email( 'nobody@example.com' ) === null or $fail++;

update_post_meta( $id, 'claimed_by', 99 );
printf( "%s claimed households are excluded from find\n", fcmc_household_find_by_email( 'someone@example.com' ) === null ? 'PASS' : 'FAIL' );
fcmc_household_find_by_email( 'someone@example.com' ) === null or $fail++;

wp_delete_post( $id, true );
echo $fail ? "\n*** {$fail} FAILURE(S) ***\n" : "\nall assertions passed\n";
```

- [ ] **Step 2: Run it and watch it fail**

```bash
scp /tmp/fcmc-test-household.php cornerfa:/home1/cornerfa/
ssh cornerfa 'cd /home1/cornerfa/public_html/fcmc-dev && /opt/cpanel/ea-php83/root/usr/bin/php /usr/local/bin/wp eval-file /home1/cornerfa/fcmc-test-household.php'
```

Expected: FAIL — post type does not exist, `fcmc_household_find_by_email` undefined.

- [ ] **Step 3: Create the CPT and accessors**

```php
<?php
/**
 * Plugin Name: FCMC Households
 * Description: The club's membership roster as records independent of WordPress user
 *              accounts. A household is one membership: up to two people and any number
 *              of cars. It exists whether or not anyone has registered on the site, which
 *              is what lets the 2026 roster be imported without creating 43 accounts
 *              nobody asked for. A user account attaches later, by email.
 *
 * @see docs/superpowers/specs/2026-09-10-fcmc-roster-import-design.md
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'init', function () {
	register_post_type( 'fcmc_household', array(
		'label'           => __( 'Households', 'fcmc' ),
		'public'          => false,          // never a front-end URL — this is member PII
		'show_ui'         => true,
		'show_in_menu'    => true,
		'menu_icon'       => 'dashicons-groups',
		'capability_type' => 'post',
		'capabilities'    => array( 'create_posts' => 'do_not_allow' ), // created by import or signup only
		'map_meta_cap'    => true,
		'supports'        => array( 'title' ),
		'show_in_rest'    => false,          // keep it off the public REST surface
	) );
} );

/**
 * Normalise an email for comparison. One rule, used everywhere.
 *
 * @param string $email Raw email.
 * @return string
 */
function fcmc_normalise_email( $email ) {
	return strtolower( trim( (string) $email ) );
}

/**
 * Find an UNCLAIMED household by either member's email.
 *
 * @param string $email Email to match.
 * @return int|null Post ID, or null.
 */
function fcmc_household_find_by_email( $email ) {
	$email = fcmc_normalise_email( $email );
	if ( '' === $email ) {
		return null;
	}

	foreach ( fcmc_household_all() as $id ) {
		if ( get_post_meta( $id, 'claimed_by', true ) ) {
			continue;
		}
		foreach ( array( 'member1_email', 'member2_email' ) as $key ) {
			if ( fcmc_normalise_email( get_post_meta( $id, $key, true ) ) === $email ) {
				return (int) $id;
			}
		}
	}

	return null;
}

/**
 * All household IDs. ~48 records, so a plain query is fine.
 *
 * @param array $args Extra WP_Query args.
 * @return int[]
 */
function fcmc_household_all( $args = array() ) {
	return get_posts( array_merge( array(
		'post_type'      => 'fcmc_household',
		'post_status'    => 'publish',
		'posts_per_page' => -1,
		'fields'         => 'ids',
		'orderby'        => 'title',
		'order'          => 'ASC',
	), $args ) );
}

/**
 * A household as a flat array, with every meta key present.
 *
 * @param int $id Post ID.
 * @return array
 */
function fcmc_household_get( $id ) {
	$keys = array(
		'member1_name', 'member1_phone', 'member1_email',
		'member2_name', 'member2_phone', 'member2_email',
		'address', 'city', 'state', 'zip',
		'cars', 'paid_through', 'member_since',
		'fcmc_source', 'claimed_by', 'import_batch',
	);

	$out = array( 'id' => (int) $id );
	foreach ( $keys as $key ) {
		$out[ $key ] = get_post_meta( $id, $key, true );
	}
	$out['cars'] = is_array( $out['cars'] ) ? $out['cars'] : array();

	return $out;
}
```

- [ ] **Step 4: Lint, deploy, re-run**

```bash
/opt/cpanel/ea-php83/root/usr/bin/php -l fcmc/mu-plugins/fcmc-households.php
scp fcmc/mu-plugins/fcmc-households.php cornerfa:/home1/cornerfa/public_html/fcmc-dev/wp-content/mu-plugins/
ssh cornerfa 'cd /home1/cornerfa/public_html/fcmc-dev && /opt/cpanel/ea-php83/root/usr/bin/php /usr/local/bin/wp eval-file /home1/cornerfa/fcmc-test-household.php'
```

Expected: `all assertions passed`.

- [ ] **Step 5: Commit**

```bash
git add fcmc/mu-plugins/fcmc-households.php
git commit -m "feat(fcmc): household records independent of user accounts"
```

---

### Task 3: The importer

**Files:**
- Create: `fcmc/mu-plugins/fcmc-import-roster.php`

**Interfaces:**
- Consumes: `fcmc_paid_through()` (Task 1 file), `fcmc_normalise_email()`, `fcmc_household_find_by_email()`, `fcmc_household_all()` (Task 2)
- Produces: WP-CLI command `wp fcmc import-roster <forms.csv> <orders.csv> [--dry-run]`

- [ ] **Step 1: Write the importer**

Key behaviours, all required by the spec:
- reads both CSVs from **explicit paths** (never in-repo)
- keys households on normalised `member1_email`; updates rather than duplicates on re-run (idempotent)
- merges rows sharing an email and reports each merge
- `car` field stored verbatim as `car_description`, plus `car_model_year` where it matches `^(19|20)\d{2}`; **never split on punctuation**
- `paid_through` via `fcmc_paid_through()`; `form-only` households get none
- sets `fcmc_source` to `form+payment` / `form-only` / `payment-only`, and `import_batch`
- reports the unattributable payments (rows with no email)

```php
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
			$id = wp_insert_post( array(
				'post_type'   => 'fcmc_household',
				'post_status' => 'publish',
				'post_title'  => $data['member1_name'] ?: $key_email,
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
```

- [ ] **Step 2: Lint and deploy**

```bash
/opt/cpanel/ea-php83/root/usr/bin/php -l fcmc/mu-plugins/fcmc-import-roster.php
scp fcmc/mu-plugins/fcmc-import-roster.php cornerfa:/home1/cornerfa/public_html/fcmc-dev/wp-content/mu-plugins/
```

- [ ] **Step 3: Copy source CSVs to the server, OUTSIDE the web root**

```bash
scp ~/Desktop/temp/fcmc-jobforms-all.csv ~/Desktop/temp/fcmc-paid-orders-linked.csv cornerfa:/home1/cornerfa/
```

- [ ] **Step 4: Dry run — MANDATORY before the real run**

```bash
ssh cornerfa 'cd /home1/cornerfa/public_html/fcmc-dev && /opt/cpanel/ea-php83/root/usr/bin/php /usr/local/bin/wp fcmc import-roster /home1/cornerfa/fcmc-jobforms-all.csv /home1/cornerfa/fcmc-paid-orders-linked.csv --dry-run'
```

Expected: `created 43, updated 0, merged 1` (or merged 0 if the duplicate email resolves to one row), and `payments with no email: 7`. **If the created count is not 43, stop and investigate before writing anything.**

- [ ] **Step 5: Real run**

```bash
ssh cornerfa 'cd /home1/cornerfa/public_html/fcmc-dev && /opt/cpanel/ea-php83/root/usr/bin/php /usr/local/bin/wp fcmc import-roster /home1/cornerfa/fcmc-jobforms-all.csv /home1/cornerfa/fcmc-paid-orders-linked.csv'
```

- [ ] **Step 6: Verify counts and idempotency**

```bash
ssh cornerfa 'cd /home1/cornerfa/public_html/fcmc-dev && WP="/opt/cpanel/ea-php83/root/usr/bin/php /usr/local/bin/wp"; $WP post list --post_type=fcmc_household --format=count'
```

Expected: `43`. Then re-run the import command and re-count — expected still `43`, with `created 0, updated 43`. That proves idempotency.

- [ ] **Step 7: Delete the CSVs from the server**

```bash
ssh cornerfa 'rm -f /home1/cornerfa/fcmc-jobforms-all.csv /home1/cornerfa/fcmc-paid-orders-linked.csv'
```

- [ ] **Step 8: Commit (code only — never the CSVs)**

```bash
git status --short   # confirm no CSV is staged
git add fcmc/mu-plugins/fcmc-import-roster.php
git commit -m "feat(fcmc): one-off roster importer for the 2026 membership"
```

---

### Task 4: Link a new account to its household

**Files:**
- Modify: `fcmc/mu-plugins/fcmc-households.php`
- Test: `/tmp/fcmc-test-link.php`

**Interfaces:**
- Consumes: `fcmc_household_find_by_email()` (Task 2), `fcmc_recompute_member()` (Task 1)
- Produces: `fcmc_household_claim( int $household_id, int $user_id ): void`; user meta `fcmc_household_id`

- [ ] **Step 1: Write the failing test**

```php
<?php
// /tmp/fcmc-test-link.php
$fail = 0;
$id = wp_insert_post( array( 'post_type' => 'fcmc_household', 'post_status' => 'publish', 'post_title' => 'Link Test' ) );
update_post_meta( $id, 'member1_email', 'linktest@example.com' );
update_post_meta( $id, 'member2_name', 'Second Person' );
update_post_meta( $id, 'paid_through', '2027-06-01' );
update_post_meta( $id, 'member_since', '2019-05-01' );
update_post_meta( $id, 'cars', array( array( 'car_model_year' => '1999', 'car_description' => '1999 / Black' ) ) );

$uid = wp_insert_user( array( 'user_login' => 'linktest', 'user_email' => 'LinkTest@example.com', 'user_pass' => wp_generate_password() ) );

printf( "%s user linked to household\n", (int) get_user_meta( $uid, 'fcmc_household_id', true ) === $id ? 'PASS' : 'FAIL' );
(int) get_user_meta( $uid, 'fcmc_household_id', true ) === $id or $fail++;

printf( "%s household marked claimed\n", (int) get_post_meta( $id, 'claimed_by', true ) === $uid ? 'PASS' : 'FAIL' );
(int) get_post_meta( $id, 'claimed_by', true ) === $uid or $fail++;

printf( "%s baseline date copied\n", get_user_meta( $uid, 'fcmc_paid_through_manual', true ) === '2027-06-01' ? 'PASS' : 'FAIL' );
get_user_meta( $uid, 'fcmc_paid_through_manual', true ) === '2027-06-01' or $fail++;

printf( "%s status active after link\n", get_user_meta( $uid, 'fcmc_status', true ) === 'active' ? 'PASS' : 'FAIL' );
get_user_meta( $uid, 'fcmc_status', true ) === 'active' or $fail++;

$cars = get_user_meta( $uid, 'fcmc_car_profiles', true );
printf( "%s car profile copied\n", is_array( $cars ) && count( $cars ) === 1 ? 'PASS' : 'FAIL' );
( is_array( $cars ) && count( $cars ) === 1 ) or $fail++;

wp_delete_user( $uid ); wp_delete_post( $id, true );
echo $fail ? "\n*** {$fail} FAILURE(S) ***\n" : "\nall assertions passed\n";
```

- [ ] **Step 2: Run it and watch it fail**

```bash
scp /tmp/fcmc-test-link.php cornerfa:/home1/cornerfa/
ssh cornerfa 'cd /home1/cornerfa/public_html/fcmc-dev && /opt/cpanel/ea-php83/root/usr/bin/php /usr/local/bin/wp eval-file /home1/cornerfa/fcmc-test-link.php'
```

Expected: FAIL on every assertion — nothing links yet.

- [ ] **Step 3: Implement the claim**

Append to `fcmc-households.php`:

```php
/**
 * Attach a user account to a household and adopt its membership facts.
 *
 * The imported date lands in fcmc_paid_through_manual — the BASELINE, not the
 * effective date — so recompute treats it as a floor and can never wipe it.
 *
 * @param int $household_id Household post ID.
 * @param int $user_id      User ID.
 */
function fcmc_household_claim( $household_id, $user_id ) {
	$h = fcmc_household_get( $household_id );

	update_post_meta( $household_id, 'claimed_by', (int) $user_id );
	update_user_meta( $user_id, 'fcmc_household_id', (int) $household_id );

	if ( $h['paid_through'] ) {
		update_user_meta( $user_id, 'fcmc_paid_through_manual', $h['paid_through'] );
	}
	if ( $h['member_since'] ) {
		update_user_meta( $user_id, 'fcmc_member_since', $h['member_since'] );
	}
	foreach ( array( 'member2_name', 'member2_phone', 'member2_email' ) as $key ) {
		if ( $h[ $key ] ) {
			update_user_meta( $user_id, 'fcmc_' . $key, $h[ $key ] );
		}
	}
	if ( ! empty( $h['cars'] ) ) {
		update_user_meta( $user_id, 'fcmc_car_profiles', $h['cars'] );
	}

	if ( function_exists( 'fcmc_recompute_member' ) ) {
		fcmc_recompute_member( $user_id );
	}
}

/**
 * On registration, attach the new account to its household if the email matches.
 * No match is not an error — an officer links it from the roster instead.
 *
 * @param int $user_id New user ID.
 */
function fcmc_maybe_claim_household( $user_id ) {
	if ( get_user_meta( $user_id, 'fcmc_household_id', true ) ) {
		return;
	}
	$user = get_userdata( $user_id );
	if ( ! $user ) {
		return;
	}
	$household_id = fcmc_household_find_by_email( $user->user_email );
	if ( $household_id ) {
		fcmc_household_claim( $household_id, $user_id );
	}
}
add_action( 'user_register', 'fcmc_maybe_claim_household', 20 );
add_action( 'woocommerce_created_customer', 'fcmc_maybe_claim_household', 20 );
```

- [ ] **Step 4: Lint, deploy, re-run**

```bash
/opt/cpanel/ea-php83/root/usr/bin/php -l fcmc/mu-plugins/fcmc-households.php
scp fcmc/mu-plugins/fcmc-households.php cornerfa:/home1/cornerfa/public_html/fcmc-dev/wp-content/mu-plugins/
ssh cornerfa 'cd /home1/cornerfa/public_html/fcmc-dev && /opt/cpanel/ea-php83/root/usr/bin/php /usr/local/bin/wp eval-file /home1/cornerfa/fcmc-test-link.php'
```

Expected: `all assertions passed`.

- [ ] **Step 5: Commit**

```bash
git add fcmc/mu-plugins/fcmc-households.php
git commit -m "feat(fcmc): attach a new account to its imported household by email"
```

---

### Task 5: Roster shows households

**Files:**
- Modify: `fcmc/mu-plugins/fcmc-roster.php`

**Interfaces:**
- Consumes: `fcmc_household_all()`, `fcmc_household_get()` (Task 2); `fcmc_status_for()`, `fcmc_get_baseline_paid_through()` (Task 1)
- Produces: `fcmc_roster_rows()` returning household-shaped rows whether or not an account exists

- [ ] **Step 1: Rewrite `fcmc_roster_rows()` to read households**

Replace the body of `fcmc_roster_rows()` so it walks `fcmc_household_all()` instead of `get_users()`. For each household: if `claimed_by` is set, read status/dates from that user (so live orders win); otherwise derive status from the household's own `paid_through` via `fcmc_status_for()`. Keep the existing `$filters` handling for `status` and `joined_since` unchanged.

Each row keeps the same keys the renderer already uses — `user`, `primary`, `email`, `member2`, `member2_mail`, `status`, `paid_through`, `since`, `cars` — plus `household_id` and `claimed`. Because the keys are unchanged, **the table and card markup need no edits**.

- [ ] **Step 2: Verify counts and both layouts**

```bash
ssh cornerfa 'cd /home1/cornerfa/public_html/fcmc-dev && /opt/cpanel/ea-php83/root/usr/bin/php /usr/local/bin/wp user set-role 2 membership_officer'
```

Log in as that officer and load `/my-account/club-roster/`. Expected: **48 households** (43 imported + 5 existing accounts), the summary line counting all of them, the status filter working, and — because the row keys are unchanged — the desktop table and the sub-768px card layout both rendering as before.

Then revert: `wp user set-role 2 customer`.

- [ ] **Step 3: Commit**

```bash
git add fcmc/mu-plugins/fcmc-roster.php
git commit -m "feat(fcmc): roster lists households, claimed or not"
```

---

### Task 6: Officer can set a membership date by hand

**Files:**
- Modify: `fcmc/mu-plugins/fcmc-households.php` (meta box on the household edit screen)

**Interfaces:**
- Consumes: `fcmc_household_get()`, `fcmc_household_claim()` (Tasks 2, 4), `fcmc_recompute_member()` (Task 1)
- Produces: nothing consumed by later tasks

- [ ] **Step 1: Add a meta box for the membership fields**

On the `fcmc_household` edit screen add a box exposing `paid_through`, `member_since` and the contact fields, gated on `current_user_can( 'fcmc_manage_members' )`. On save: sanitise dates to `Y-m-d`, write the household meta, and **if the household is claimed, mirror `paid_through` into the linked user's `fcmc_paid_through_manual` and call `fcmc_recompute_member()`** so the roster chip updates immediately.

- [ ] **Step 2: Verify by hand**

Edit an imported household in wp-admin, set `paid_through` to a past date, save, and confirm on the roster that the chip changes to `lapsed`. Then set a future date and confirm it returns to `active`. For a *claimed* household, run `wp fcmc recompute <user_id>` afterwards and confirm the date **survives** — that is the floor rule working end to end.

- [ ] **Step 3: Commit**

```bash
git add fcmc/mu-plugins/fcmc-households.php
git commit -m "feat(fcmc): officers can correct a household's membership date"
```

---

### Task 7: Needs-linking view

**Files:**
- Modify: `fcmc/mu-plugins/fcmc-roster.php`

**Interfaces:**
- Consumes: `fcmc_household_all()`, `fcmc_household_claim()` (Tasks 2, 4)
- Produces: nothing consumed by later tasks

- [ ] **Step 1: Add the view**

Below the roster table, for `fcmc_manage_members` only: list unclaimed households and, separately, users with no `fcmc_household_id`. Each user row gets a `<select>` of unclaimed households and a Link button posting to `admin-post.php` with a nonce; the handler calls `fcmc_household_claim()`.

Include a short static note naming the **7 payments with no email** as needing a human pass against Square, since the site cannot resolve them.

- [ ] **Step 2: Verify by hand**

As an officer, link one unlinked user to an unclaimed household. Confirm: the household leaves the unclaimed list, the user gains `fcmc_household_id`, their status chip updates, and the roster count does **not** change (the household was already counted).

- [ ] **Step 3: Commit**

```bash
git add fcmc/mu-plugins/fcmc-roster.php
git commit -m "feat(fcmc): officer view for linking accounts to households"
```

---

## Self-review

**Spec coverage:** §1 source data → Task 3. §2 storage → Task 2. §3 term derivation → Task 3 (via `fcmc_paid_through()`). §4 linking → Task 4. §5 floor-not-cache → Task 1. §5a officer override → Task 6. §6 roster + needs-linking → Tasks 5, 7. §7 importer → Task 3. §8 PII → Global Constraints, Task 3 steps 3/7/8. §9 reconciliation → `fcmc_source` and `import_batch` written in Task 3. §10 car parsing → Task 3 step 1. No gaps.

**Type consistency:** `fcmc_normalise_email()`, `fcmc_household_find_by_email()`, `fcmc_household_all()`, `fcmc_household_get()`, `fcmc_household_claim()`, `fcmc_get_baseline_paid_through()` are each defined once and used with the same signature throughout. Meta key `fcmc_paid_through_manual` is used consistently in Tasks 1, 4 and 6.

**Ordering:** Task 1 must precede Task 4 (claim calls recompute). Task 2 must precede Tasks 3–7. Tasks 5–7 may be done in any order once 1–4 are done.
