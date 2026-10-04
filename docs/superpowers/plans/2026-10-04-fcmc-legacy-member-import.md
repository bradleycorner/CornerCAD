# FCMC Legacy Member History Import — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Load the club's 1991–2020 membership sheet into firstcoastmiataclub.org as households, backfill real join dates, and show current members by default in the officer roster.

**Architecture:** A WordPress-free library (`fcmc-legacy-lib.php`) reads, translates, merges and decides — unit-tested locally with plain `php`. Thin WordPress glue in `fcmc-import-roster.php` builds the match index from live data, writes households/meta with a per-batch change log, and exposes `wp fcmc import-legacy` (dry run, overrides, undo). Roster and household-editor changes are small edits to existing files. Everything that touches the live site is a separate, approval-gated task.

**Tech Stack:** PHP 8.3 (server: ea-php83; local: Homebrew `php` for tests only), WordPress mu-plugins, WP-CLI, WooCommerce My Account endpoint.

**Spec:** `docs/superpowers/specs/2026-10-04-fcmc-legacy-member-import-design.md` (read it first — this plan argues from it).

## Global Constraints

- **PII never enters the repo, commit messages, docs, or the session transcript.** Reports print row keys (`id:632` or `r:<8-hex>`), counts and target IDs (`h:12`, `u:18`) only — never names, emails, phones, addresses. Test fixtures use made-up people with `@example.invalid` emails.
- Real data lives only at `~/Documents/FCMC-private/` on Bradley's Mac and `~/fcmc-import/` on the server (outside the web root); server copies are deleted after the run.
- **2026 data wins:** the import never changes contact details, consents or newsletter preference on an existing household or user (spec § 3).
- Matching is **email only**; names are reported as near-matches, never acted on (spec § 2).
- Household paid date key is **`paid_through`** (household post meta); the user-side floor is `fcmc_paid_through_manual`. Never write `fcmc_paid_through` (derived cache).
- `member_since` only ever moves **earlier**; `paid_through` only ever moves **later**.
- Consent values are `'1'` (on) or `''` (off) — identical to `fcmc_save_account_fields()`. Newsletter values are `'digital'` / `'paper'` / `''`.
- Car arrays use exactly the keys `car_model_year, car_generation, car_package, car_color_body, car_color_top, car_purchase_date, car_name` (`fcmc_car_fields()`); `car_purchase_date` format `MM/YYYY`.
- Created households: `fcmc_source = 'legacy-2020'`, `import_batch = <batch id>`, `post_author = 0`, title = member 1's name (never an email).
- **Live-site rule:** any task marked 🔒 GATED must stop and get Bradley's explicit "yes" before its first command. SSH work is one batched session per gated task, never parallel (account process limits — see `docs/sites.md` *Account-wide hazards*). MCP cannot reach mu-plugins.
- Git: branch `feature/fcmc-legacy-import`; commit after each task; commit messages end with the two attribution lines used in this repo.

## Review Focus

1. **Rows with no `MEMBER_ID`** (members added 2019–2020 have blank IDs) — expect a stable `r:<hash>` key so re-runs and overrides still work. → tested in Task 1 (`map_row` key fallback).
2. **`E-MAIL ADDRESS 2` holding garbage** (one row has a phone number there) — expect it dropped with a warning, never used for matching. → Task 1 test `bad email 2`.
3. **Same person twice in the sheet** (a second row with only dates, or a moved household) — expect a merge by shared email, or a "same-name-in-sheet" report when emails differ. → Task 2 tests.
4. **Re-running the real import** after a first real run — expect enrich (not duplicate) because the created households now match by email. → Task 2 test `second run enriches` + Task 8 verification.
5. **Undo after an officer edited a value** — expect that value reported as a conflict and left alone. → Task 3 server test in Task 7 step "undo with a hand edit".

---

### Task 0 🔒 GATED: Local PHP for unit tests

Bradley's Mac has no `php`. The library tests need one. This installs a developer tool on Bradley's machine — **ask first**.

- [ ] **Step 1: Ask Bradley:** "Install PHP locally with Homebrew (`brew install php`) so the import library can be unit-tested on your Mac? It's only used for tests." Wait for yes. If no, every `php fcmc/tests/...` step in Tasks 1–2 instead runs on the server inside Task 6's SSH session (slower; batch them).
- [ ] **Step 2:** `brew install php` then `php -v` → expect `PHP 8.x`.
- [ ] **Step 3:** No commit (no repo change).

---

### Task 1: Library — reading and translating rows

**Files:**
- Create: `fcmc/mu-plugins/fcmc-legacy-lib.php`
- Create: `fcmc/tests/test-legacy-lib.php`
- Create: `fcmc/tests/fixtures/legacy-synthetic.csv`

**Interfaces — Produces (exact names; later tasks rely on them):**
- `const FCMC_LEGACY_SOURCE = 'legacy-2020';`
- `fcmc_legacy_read_csv( string $path ): array` — list of assoc rows keyed by trimmed header; strips a UTF-8 BOM.
- `fcmc_legacy_parse_date( $raw ): ?string` — `m/d/Y` or `m/d/yy` → `Y-m-d`; else `null`.
- `fcmc_legacy_paid_through( string $expiry_ymd ): string` — first `YYYY-06-01` on or after the date.
- `fcmc_legacy_generation( $year ): string` — `NA|NB|NC|ND|''`.
- `fcmc_legacy_car_name( string $call, string $tag ): string`
- `fcmc_legacy_consent( $raw ): string` — `'1'|''`.
- `fcmc_legacy_newsletter( $raw ): string` — `'digital'|'paper'|''`.
- `fcmc_legacy_email( $raw ): string` — lowercased valid email or `''`.
- `fcmc_legacy_purchase_date( $raw ): string` — `MM/YYYY` or `''`.
- `fcmc_legacy_car( array $r, int $n ): ?array` — car 1 or 2, or null.
- `fcmc_legacy_history_keys(): array` — `['legacy_member_id','home_area','birthdays','directory_listed','legacy_stat']`.
- `fcmc_legacy_map_row( array $r ): array` — `['skip'=>?string,'key'=>string,'legacy_id'=>string,'emails'=>string[],'name_key'=>string,'warnings'=>string[],'data'=>array]`. `data` keys: `member1_name, member1_phone, member1_email, member2_name, member2_phone, member2_email, address, city, state, zip, cars, paid_through, member_since`, the five history keys, `fcmc_consent_directory_name, fcmc_consent_directory_address, fcmc_consent_directory_phone, fcmc_consent_directory_email, fcmc_consent_directory_car, fcmc_consent_public_name, fcmc_newsletter_pref`.
- `fcmc_legacy_name_key_from_full( string $full ): string` — `"First Middle Last"` → `"last|first middle"`, lowercased.

- [ ] **Step 1: Write the test harness and the failing translation tests**

Create `fcmc/tests/test-legacy-lib.php`:

```php
<?php
/**
 * Unit tests for fcmc-legacy-lib.php. Run: php fcmc/tests/test-legacy-lib.php
 * Synthetic data only — never real member rows.
 */
define( 'FCMC_TESTING', true );
require __DIR__ . '/../mu-plugins/fcmc-legacy-lib.php';

$GLOBALS['fails'] = 0;
function check( $label, $got, $want ) {
	if ( $got !== $want ) {
		$GLOBALS['fails']++;
		fwrite( STDERR, "FAIL {$label}\n  got:  " . var_export( $got, true ) . "\n  want: " . var_export( $want, true ) . "\n" );
		return;
	}
	echo "ok   {$label}\n";
}

/* ---- Task 1: translation ---- */
check( 'date m/d/Y', fcmc_legacy_parse_date( '2/11/2012' ), '2012-02-11' );
check( 'date m/d/yy', fcmc_legacy_parse_date( '8/12/20' ), '2020-08-12' );
check( 'date blank', fcmc_legacy_parse_date( '' ), null );
check( 'date garbage', fcmc_legacy_parse_date( 'April 21 1948' ), null );
check( 'date impossible', fcmc_legacy_parse_date( '2/30/2019' ), null );

check( 'paid 5/31 -> same year 6/1', fcmc_legacy_paid_through( '2019-05-31' ), '2019-06-01' );
check( 'paid 6/1 stays', fcmc_legacy_paid_through( '2019-06-01' ), '2019-06-01' );
check( 'paid 8/31 -> next 6/1', fcmc_legacy_paid_through( '2015-08-31' ), '2016-06-01' );

check( 'gen 1997 NA', fcmc_legacy_generation( '1997' ), 'NA' );
check( 'gen 1998 NB', fcmc_legacy_generation( 1998 ), 'NB' );
check( 'gen 2005 NB', fcmc_legacy_generation( '2005' ), 'NB' );
check( 'gen 2006 NC', fcmc_legacy_generation( '2006' ), 'NC' );
check( 'gen 2015 NC', fcmc_legacy_generation( '2015' ), 'NC' );
check( 'gen 2016 ND', fcmc_legacy_generation( '2016' ), 'ND' );
check( 'gen blank', fcmc_legacy_generation( '' ), '' );
check( 'gen 1985 out of range', fcmc_legacy_generation( '1985' ), '' );

check( 'car name both', fcmc_legacy_car_name( 'Zippy', 'ZOOM1' ), 'Zippy (ZOOM1)' );
check( 'car name call only', fcmc_legacy_car_name( 'Zippy', '' ), 'Zippy' );
check( 'car name tag only', fcmc_legacy_car_name( '', 'ZOOM1' ), 'ZOOM1' );
check( 'car name none', fcmc_legacy_car_name( '', '' ), '' );

check( 'consent YES', fcmc_legacy_consent( 'YES' ), '1' );
check( 'consent Yes', fcmc_legacy_consent( ' Yes ' ), '1' );
check( 'consent No', fcmc_legacy_consent( 'No' ), '' );
check( 'consent blank', fcmc_legacy_consent( '' ), '' );

check( 'newsletter D', fcmc_legacy_newsletter( 'D' ), 'digital' );
check( 'newsletter P', fcmc_legacy_newsletter( 'p' ), 'paper' );
check( 'newsletter blank', fcmc_legacy_newsletter( '' ), '' );

check( 'email ok', fcmc_legacy_email( ' Pat@Example.Invalid ' ), 'pat@example.invalid' );
check( 'bad email 2 (phone)', fcmc_legacy_email( '5615550100' ), '' );

check( 'purchase date', fcmc_legacy_purchase_date( '1/1/2006' ), '01/2006' );
check( 'purchase date blank', fcmc_legacy_purchase_date( '' ), '' );

check( 'name key from full', fcmc_legacy_name_key_from_full( 'Pat Q Sample' ), 'sample|pat q' );

$row = array(
	'MEMBER_ID' => '9001', 'Directory' => 'Y', 'LastName1' => 'Sample', 'FirstName1' => 'Pat',
	'LastName2' => 'Sample', 'FirstName2' => 'Lee', 'PERSONAL_ADDRESS' => '1 Test St', 'APT' => 'Unit 2',
	'CITY' => 'Testville', 'STATE' => 'FL', 'ZIP' => '32000', 'PRIMARY PHONE' => '(904) 555-0100',
	'PERSONAL_E-MAIL ADDRESS' => 'pat@example.invalid', 'E-MAIL ADDRESS 2' => 'lee@example.invalid',
	'STAT' => 'E', 'ORIG_ENTRY' => '2/11/2012', 'JOIN_DATE' => '1/10/2017', 'EXP DATE' => '5/31/2019',
	'COLOR' => 'Crystal White', 'Top COLOR' => 'Black', 'PKG_EDIT' => 'PRHT', 'YRMANF' => '2013', 'TAG' => 'ZOOM1',
	'STATS_CAR/CALL NAME' => 'Zippy', 'COLORB 2' => 'Red', 'COLORT 2' => 'Black', 'PKG_EDIT 2' => '',
	'YRMANF 2' => '1999', 'TAG 2' => '', 'CAR/CALL NAME 2' => 'Redline', 'PURCH_DATE 2' => '1/1/2006',
	'HOMEAREA' => 'Southside', 'MARITAL' => 'M', 'NAME(S)' => 'YES', 'REGISTRY_ADDRESS' => 'NO',
	'PHONE NUMBER' => 'YES', 'REGISTRY_E-MAIL ADDRESS' => 'YES', 'CAR INFORMATION' => 'YES', 'HOMEPAGE' => 'NO',
	'Birthdays' => '6/11', 'RoadRunner' => 'D',
);
$m = fcmc_legacy_map_row( $row );
check( 'map key id', $m['key'], 'id:9001' );
check( 'map not skipped', $m['skip'], null );
check( 'map emails', $m['emails'], array( 'pat@example.invalid', 'lee@example.invalid' ) );
check( 'map name_key', $m['name_key'], 'sample|pat' );
check( 'map member1', $m['data']['member1_name'], 'Pat Sample' );
check( 'map member2', $m['data']['member2_name'], 'Lee Sample' );
check( 'map address+apt', $m['data']['address'], '1 Test St, Unit 2' );
check( 'map since = earliest', $m['data']['member_since'], '2012-02-11' );
check( 'map paid_through', $m['data']['paid_through'], '2019-06-01' );
check( 'map consent name', $m['data']['fcmc_consent_directory_name'], '1' );
check( 'map consent address', $m['data']['fcmc_consent_directory_address'], '' );
check( 'map consent public', $m['data']['fcmc_consent_public_name'], '' );
check( 'map newsletter', $m['data']['fcmc_newsletter_pref'], 'digital' );
check( 'map history id', $m['data']['legacy_member_id'], '9001' );
check( 'map home area', $m['data']['home_area'], 'Southside' );
check( 'map directory', $m['data']['directory_listed'], 'Y' );
check( 'map no marital', array_key_exists( 'marital', $m['data'] ), false );
check( 'map car count', count( $m['data']['cars'] ), 2 );
check( 'map car1', $m['data']['cars'][0], array(
	'car_model_year' => '2013', 'car_generation' => 'NC', 'car_package' => 'PRHT',
	'car_color_body' => 'Crystal White', 'car_color_top' => 'Black', 'car_purchase_date' => '',
	'car_name' => 'Zippy (ZOOM1)',
) );
check( 'map car2 purchase', $m['data']['cars'][1]['car_purchase_date'], '01/2006' );
check( 'map car2 gen', $m['data']['cars'][1]['car_generation'], 'NB' );

$noid = $row; $noid['MEMBER_ID'] = '';
$k1 = fcmc_legacy_map_row( $noid )['key'];
check( 'key fallback is r:hash', (bool) preg_match( '/^r:[0-9a-f]{8}$/', $k1 ), true );
check( 'key fallback stable', fcmc_legacy_map_row( $noid )['key'], $k1 );
check( 'key fallback has no email', false === strpos( $k1, '@' ), true );

$bad2 = $row; $bad2['E-MAIL ADDRESS 2'] = '5615550100';
$b = fcmc_legacy_map_row( $bad2 );
check( 'bad email 2 dropped', $b['emails'], array( 'pat@example.invalid' ) );
check( 'bad email 2 warned', in_array( 'bad-email-2', $b['warnings'], true ), true );

$nn = $row; $nn['LastName1'] = '';
check( 'skip no-name', fcmc_legacy_map_row( $nn )['skip'], 'no-name' );
$nc = $row; $nc['PERSONAL_E-MAIL ADDRESS'] = ''; $nc['E-MAIL ADDRESS 2'] = ''; $nc['PERSONAL_ADDRESS'] = '';
check( 'skip no-contact', fcmc_legacy_map_row( $nc )['skip'], 'no-contact' );
$bd = $row; $bd['ORIG_ENTRY'] = 'sometime';
$bm = fcmc_legacy_map_row( $bd );
check( 'bad date warned', in_array( 'bad-date-ORIG_ENTRY', $bm['warnings'], true ), true );
check( 'bad date falls back to join', $bm['data']['member_since'], '2017-01-10' );
$odd = $row; $odd['EXP DATE'] = '8/31/2015';
check( 'odd expiry warned', in_array( 'expiry-not-5/31', fcmc_legacy_map_row( $odd )['warnings'], true ), true );
$nocar = $row; $nocar['YRMANF'] = ''; $nocar['COLOR'] = ''; $nocar['YRMANF 2'] = ''; $nocar['COLORB 2'] = '';
check( 'no cars', fcmc_legacy_map_row( $nocar )['data']['cars'], array() );

$tmp = tempnam( sys_get_temp_dir(), 'fcmc' );
file_put_contents( $tmp, "\xEF\xBB\xBFMEMBER_ID,LastName1\n9001,Sample\n,\n" );
$rows = fcmc_legacy_read_csv( $tmp );
unlink( $tmp );
check( 'csv bom stripped', array_keys( $rows[0] ), array( 'MEMBER_ID', 'LastName1' ) );
check( 'csv rows', count( $rows ), 2 );

/* ---- Task 2 tests are appended below this line ---- */

echo $GLOBALS['fails'] ? "\n{$GLOBALS['fails']} FAILED\n" : "\nALL PASSED\n";
exit( $GLOBALS['fails'] ? 1 : 0 );
```

- [ ] **Step 2: Run to confirm it fails**

Run: `php fcmc/tests/test-legacy-lib.php`
Expected: fatal error `Failed opening required '.../fcmc-legacy-lib.php'`.

- [ ] **Step 3: Write the library**

Create `fcmc/mu-plugins/fcmc-legacy-lib.php`:

```php
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
	$head = fgetcsv( $fh );
	if ( ! is_array( $head ) ) {
		fclose( $fh );
		return array();
	}
	$head[0] = preg_replace( '/^\xEF\xBB\xBF/', '', (string) $head[0] );
	$head    = array_map( 'trim', $head );
	$n       = count( $head );
	$rows    = array();
	while ( ( $r = fgetcsv( $fh ) ) !== false ) {
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
```

Note for the implementer: the `bad date falls back to join` test expects `member_since` = JOIN_DATE when ORIG_ENTRY is unparseable — `min()` over the parseable dates does that.

- [ ] **Step 4: Run tests — all Task 1 checks pass**

Run: `php fcmc/tests/test-legacy-lib.php`
Expected: every line `ok …`, final `ALL PASSED`, exit 0.

- [ ] **Step 5: Create the synthetic fixture** (made-up people only; used on the server in Task 7)

Create `fcmc/tests/fixtures/legacy-synthetic.csv` with the sheet's exact header row (copy the header line from the spec's column list in this order: `PERSONAL_ID,MEMBER_ID,STATS_ID,REGISTRY_ID,Directory,LastName1,FirstName1,LastName2,FirstName2,PERSONAL_ADDRESS,APT,CITY,STATE,ZIP,PRIMARY PHONE,PERSONAL_E-MAIL ADDRESS,E-MAIL ADDRESS 2,CATEGORY,STAT,ORIG_ENTRY,JOIN_DATE,EXP DATE,REGISTER,C_COUNTER,P_COUNTER,COLOR,Top COLOR,PKG_EDIT,YRMANF,TAG,STATS_CAR/CALL NAME,COLORB 2,COLORT 2,PKG_EDIT 2,YRMANF 2,Expr1032,TAG 2,CAR/CALL NAME 2,PURCH_DATE 2,HOMEAREA,MARITAL,NAME(S),REGISTRY_ADDRESS,PHONE NUMBER,REGISTRY_E-MAIL ADDRESS,CAR INFORMATION,HOMEPAGE,REGISTRY_CAR/CALL NAME,Birthdays,RoadRunner`) and these rows:

```csv
9101,9101,9101,9101,Y,Testlapsed,Alpha,,,1 Test St,,Testville,FL,32000,(904) 555-0101,alpha.lapsed@example.invalid,,M,E,3/1/2005,3/1/2005,5/31/2012,TRUE,1,1,Classic Red,Black,,1994,,Alpha Red,,,,,,,,,Southside,SM,YES,NO,YES,YES,YES,NO,YES,,P
9102,9102,9102,9102,Y,Testlinked,Bravo,,,2 Test St,,Testville,FL,32000,(904) 555-0102,bravo.linked@example.invalid,,M,E,2/11/2012,1/10/2017,5/31/2019,TRUE,1,1,Crystal White,Black,PRHT,2013,,Bravo Zoom,,,,,,,,,Mandarin,SM,YES,YES,YES,YES,YES,YES,YES,,D
,,,,,Testjunk,,,,,,,,,,,,,,,,,,,,,,,,,,,,,,,,,,,,,,,,,,,,,
```

(Row 3 has a name but no email/address → `no-contact`. The footer-style rows are covered by unit tests.)

- [ ] **Step 6: Commit**

```bash
git add fcmc/mu-plugins/fcmc-legacy-lib.php fcmc/tests/test-legacy-lib.php fcmc/tests/fixtures/legacy-synthetic.csv
git commit -m "feat(fcmc): legacy import library — read and translate 2020 sheet rows"
```

---

### Task 2: Library — duplicate merge, decisions, overrides, report

**Files:**
- Modify: `fcmc/mu-plugins/fcmc-legacy-lib.php` (append)
- Modify: `fcmc/tests/test-legacy-lib.php` (insert tests at the `Task 2 tests` marker)

**Interfaces:**
- Consumes: `fcmc_legacy_map_row()` output shape (Task 1).
- Produces:
  - `fcmc_legacy_min_date( string $a, string $b ): string` — earlier non-empty.
  - `fcmc_legacy_merge_duplicates( array $rows ): array` → `['rows'=>array,'merges'=>array<array{0:string,1:string}>]` (pairs of row keys).
  - `fcmc_legacy_parse_overrides( array $rows ): array` → `['valid'=>array<string,array{action:string,target:int}>,'invalid'=>string[]]`. CSV columns: `key,action,target`; actions `skip|link-household|link-user`.
  - `fcmc_legacy_decide( array $rows, array $email_index, array $name_index, array $overrides ): array` — list of actions `['type'=>'create'|'create_linked'|'enrich'|'skip','target'=>int,'reason'=>string,'targets'=>string[],'near'=>string[],'dup_name'=>bool,'override'=>bool,'row'=>array]`. Indexes map normalised email / name key → list of target strings `h:<post id>` or `u:<user id>`.
  - `fcmc_legacy_report_lines( array $actions, array $merges, array $invalid_overrides ): array` — PII-free strings.

- [ ] **Step 1: Write the failing tests** — insert at the `/* ---- Task 2 tests … ---- */` marker:

```php
/* ---- Task 2: merge, decide, overrides, report ---- */
check( 'min date', fcmc_legacy_min_date( '2015-01-01', '2012-02-11' ), '2012-02-11' );
check( 'min date empty a', fcmc_legacy_min_date( '', '2012-02-11' ), '2012-02-11' );
check( 'min date empty b', fcmc_legacy_min_date( '2015-01-01', '' ), '2015-01-01' );

$a = fcmc_legacy_map_row( $row );                     // id:9001, pat@ + lee@, since 2012-02-11, paid 2019-06-01
$dupe = $row; $dupe['MEMBER_ID'] = ''; $dupe['ORIG_ENTRY'] = '6/1/2008'; $dupe['JOIN_DATE'] = '';
$dupe['EXP DATE'] = '5/31/2021'; $dupe['HOMEAREA'] = ''; $dupe['E-MAIL ADDRESS 2'] = 'pat.alt@example.invalid';
$b2 = fcmc_legacy_map_row( $dupe );
$merged = fcmc_legacy_merge_duplicates( array( $a, $b2 ) );
check( 'merge -> one row', count( $merged['rows'] ), 1 );
check( 'merge reported', $merged['merges'], array( array( 'id:9001', $b2['key'] ) ) );
check( 'merge since earliest', $merged['rows'][0]['data']['member_since'], '2008-06-01' );
check( 'merge paid latest', $merged['rows'][0]['data']['paid_through'], '2021-06-01' );
check( 'merge keeps first non-empty', $merged['rows'][0]['data']['home_area'], 'Southside' );
check( 'merge emails union', $merged['rows'][0]['emails'], array( 'pat@example.invalid', 'lee@example.invalid', 'pat.alt@example.invalid' ) );
check( 'merge leaves skips alone', count( fcmc_legacy_merge_duplicates( array( $a, fcmc_legacy_map_row( $nn ) ) )['rows'] ), 2 );

$ov = fcmc_legacy_parse_overrides( array(
	array( 'key' => 'id:9001', 'action' => 'link-user', 'target' => '18' ),
	array( 'key' => 'id:9002', 'action' => 'skip', 'target' => '' ),
	array( 'key' => 'id:9003', 'action' => 'link-household', 'target' => '' ),
	array( 'key' => 'id:9004', 'action' => 'delete', 'target' => '5' ),
) );
check( 'override valid', $ov['valid'], array(
	'id:9001' => array( 'action' => 'link-user', 'target' => 18 ),
	'id:9002' => array( 'action' => 'skip', 'target' => 0 ),
) );
check( 'override invalid', $ov['invalid'], array( 'id:9003', 'id:9004' ) );

$other = $row; $other['MEMBER_ID'] = '9002'; $other['LastName1'] = 'Other'; $other['FirstName1'] = 'Ollie';
$other['PERSONAL_E-MAIL ADDRESS'] = 'ollie@example.invalid'; $other['E-MAIL ADDRESS 2'] = '';
$o = fcmc_legacy_map_row( $other );

$act = fcmc_legacy_decide( array( $a ), array(), array(), array() );
check( 'decide create', $act[0]['type'], 'create' );
$act = fcmc_legacy_decide( array( $a ), array( 'lee@example.invalid' => array( 'h:12' ) ), array(), array() );
check( 'decide enrich via email 2', array( $act[0]['type'], $act[0]['target'] ), array( 'enrich', 12 ) );
$act = fcmc_legacy_decide( array( $a ), array( 'pat@example.invalid' => array( 'u:18' ) ), array(), array() );
check( 'decide create_linked', array( $act[0]['type'], $act[0]['target'] ), array( 'create_linked', 18 ) );
$act = fcmc_legacy_decide( array( $a ), array( 'pat@example.invalid' => array( 'h:12' ), 'lee@example.invalid' => array( 'h:12' ) ), array(), array() );
check( 'decide same target twice is one', $act[0]['type'], 'enrich' );
$act = fcmc_legacy_decide( array( $a ), array( 'pat@example.invalid' => array( 'h:12' ), 'lee@example.invalid' => array( 'u:30' ) ), array(), array() );
check( 'decide ambiguous', array( $act[0]['type'], $act[0]['reason'], $act[0]['targets'] ), array( 'skip', 'ambiguous', array( 'h:12', 'u:30' ) ) );
$act = fcmc_legacy_decide( array( $a ), array(), array( 'sample|pat' => array( 'u:44' ) ), array() );
check( 'decide near-match reported, still create', array( $act[0]['type'], $act[0]['near'] ), array( 'create', array( 'u:44' ) ) );
$act = fcmc_legacy_decide( array( $a ), array( 'pat@example.invalid' => array( 'h:12' ) ), array(), array( 'id:9001' => array( 'action' => 'link-user', 'target' => 18 ) ) );
check( 'decide override beats email', array( $act[0]['type'], $act[0]['target'], $act[0]['override'] ), array( 'create_linked', 18, true ) );
$act = fcmc_legacy_decide( array( $a ), array(), array(), array( 'id:9001' => array( 'action' => 'skip', 'target' => 0 ) ) );
check( 'decide override skip', array( $act[0]['type'], $act[0]['reason'] ), array( 'skip', 'override' ) );
$act = fcmc_legacy_decide( array( fcmc_legacy_map_row( $nn ) ), array(), array(), array() );
check( 'decide passes row skips', array( $act[0]['type'], $act[0]['reason'] ), array( 'skip', 'no-name' ) );
$twin = $o; $twin['name_key'] = $a['name_key'];
$act = fcmc_legacy_decide( array( $a, $twin ), array(), array(), array() );
check( 'decide same-name-in-sheet', array( $act[0]['dup_name'], $act[1]['dup_name'] ), array( true, true ) );

// Review Focus 4: after a first real run, the created household matches by email -> enrich, not duplicate.
$act = fcmc_legacy_decide( array( $a ), array( 'pat@example.invalid' => array( 'h:77' ), 'lee@example.invalid' => array( 'h:77' ) ), array(), array() );
check( 'second run enriches', array( $act[0]['type'], $act[0]['target'] ), array( 'enrich', 77 ) );

$lines = fcmc_legacy_report_lines(
	fcmc_legacy_decide( array( $a, fcmc_legacy_map_row( $nc ) ), array(), array( 'sample|pat' => array( 'u:44' ) ), array() ),
	array( array( 'id:9001', 'r:abcdef12' ) ),
	array( 'id:9003' )
);
$joined = implode( "\n", $lines );
check( 'report has no emails', false === strpos( $joined, '@' ), true );
check( 'report has no names', false === stripos( $joined, 'sample' ), true );
check( 'report counts create', in_array( 'create: 1', $lines, true ), true );
check( 'report counts skip', in_array( 'skip: 1', $lines, true ), true );
check( 'report near-match', in_array( 'near-match id:9001 -> u:44', $lines, true ), true );
check( 'report merge', in_array( 'merged id:9001 + r:abcdef12', $lines, true ), true );
check( 'report invalid override', in_array( 'invalid override id:9003 (ignored)', $lines, true ), true );
```

- [ ] **Step 2: Run to confirm failures**

Run: `php fcmc/tests/test-legacy-lib.php`
Expected: fatal `Call to undefined function fcmc_legacy_min_date()`.

- [ ] **Step 3: Implement** — append to `fcmc/mu-plugins/fcmc-legacy-lib.php`:

```php
/** Earlier of two Y-m-d strings, ignoring empties. */
function fcmc_legacy_min_date( string $a, string $b ): string {
	if ( '' === $a ) {
		return $b;
	}
	return '' === $b ? $a : min( $a, $b );
}

/**
 * Merge rows that share any email (the same person entered twice). Earliest member_since,
 * latest paid_through, first non-empty value for everything else. Skipped rows pass through.
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
		$hit = null;
		foreach ( $row['emails'] as $e ) {
			if ( isset( $by_email[ $e ] ) ) {
				$hit = $by_email[ $e ];
				break;
			}
		}
		if ( null === $hit ) {
			$out[] = $row;
			$i     = count( $out ) - 1;
			foreach ( $row['emails'] as $e ) {
				$by_email[ $e ] = $i;
			}
			continue;
		}

		$merges[] = array( $out[ $hit ]['key'], $row['key'] );
		$keep     = $out[ $hit ];
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
		$out[ $hit ]      = $keep;
		foreach ( $keep['emails'] as $e ) {
			$by_email[ $e ] = $hit;
		}
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
```

- [ ] **Step 4: Run tests**

Run: `php fcmc/tests/test-legacy-lib.php`
Expected: `ALL PASSED`, exit 0.

- [ ] **Step 5: Commit**

```bash
git add fcmc/mu-plugins/fcmc-legacy-lib.php fcmc/tests/test-legacy-lib.php
git commit -m "feat(fcmc): legacy import library — merge, decide, overrides, PII-free report"
```

---

### Task 3: WordPress glue — index, writes with change log, undo, WP-CLI command

**Files:**
- Modify: `fcmc/mu-plugins/fcmc-import-roster.php` (append after the existing `WP_CLI::add_command( 'fcmc import-roster', … );` block — the file already returns early unless WP-CLI is running, so everything here is CLI-only)

**Interfaces:**
- Consumes (Task 1–2): `fcmc_legacy_read_csv`, `fcmc_legacy_map_row`, `fcmc_legacy_merge_duplicates`, `fcmc_legacy_parse_overrides`, `fcmc_legacy_decide`, `fcmc_legacy_report_lines`, `fcmc_legacy_history_keys`, `fcmc_legacy_name_key_from_full`, `FCMC_LEGACY_SOURCE`. Existing: `fcmc_household_all()`, `fcmc_household_get()`, `fcmc_normalize_email()`, `fcmc_household_sync_to_user()`, `fcmc_recompute_member()`.
- Produces: `wp fcmc import-legacy <csv> [--overrides=<csv>] [--dry-run]` and `wp fcmc import-legacy --undo=<batch> [--dry-run]`; option `fcmc_legacy_import_log` = `array<batch, array{created:int[], changes:array<array{t:'post'|'user',id:int,k:string,had:bool,old:mixed,new:mixed}>}>`.

No local unit test is possible (WordPress required). Verification is Task 7 on the server. Keep logic minimal and follow this code exactly.

- [ ] **Step 1: Append the glue**

```php
/* ===========================================================================
 * Legacy member history import (1991–2020 sheet).
 * Pure logic: fcmc-legacy-lib.php. This section only touches WordPress.
 * @see docs/superpowers/specs/2026-10-04-fcmc-legacy-member-import-design.md
 * ======================================================================== */

/** Email and name indexes over live households and users. A user with a household is that household. */
function fcmc_legacy_build_index(): array {
	$email = array();
	$name  = array();
	$add   = function ( array &$map, string $k, string $t ): void {
		if ( '' !== $k && '|' !== $k ) {
			$map[ $k ][ $t ] = true;
		}
	};

	foreach ( fcmc_household_all() as $hid ) {
		$t = 'h:' . $hid;
		foreach ( array( 'member1_email', 'member2_email' ) as $k ) {
			$add( $email, fcmc_normalize_email( get_post_meta( $hid, $k, true ) ), $t );
		}
		$add( $name, fcmc_legacy_name_key_from_full( (string) get_post_meta( $hid, 'member1_name', true ) ), $t );
	}
	foreach ( get_users( array( 'fields' => 'all' ) ) as $u ) {
		$hid = (int) get_user_meta( $u->ID, 'fcmc_household_id', true );
		$t   = $hid ? 'h:' . $hid : 'u:' . $u->ID;
		$add( $email, fcmc_normalize_email( $u->user_email ), $t );
		$add( $email, fcmc_normalize_email( (string) get_user_meta( $u->ID, 'fcmc_member2_email', true ) ), $t );
		$add( $name, strtolower( trim( $u->last_name ) . '|' . trim( $u->first_name ) ), $t );
	}

	$flat = function ( array $m ): array {
		return array_map( 'array_keys', $m );
	};
	return array( $flat( $email ), $flat( $name ) );
}

/** Turn actions whose target no longer fits into skips (bad override, or two rows claiming one user). */
function fcmc_legacy_validate_targets( array $actions ): array {
	$users_taken = array();
	foreach ( $actions as &$a ) {
		if ( 'enrich' === $a['type'] && 'fcmc_household' !== get_post_type( (int) $a['target'] ) ) {
			$a['type']   = 'skip';
			$a['reason'] = 'bad-target';
		}
		if ( 'create_linked' === $a['type'] ) {
			$uid = (int) $a['target'];
			if ( ! get_userdata( $uid ) || get_user_meta( $uid, 'fcmc_household_id', true ) || isset( $users_taken[ $uid ] ) ) {
				$a['type']   = 'skip';
				$a['reason'] = 'bad-target';
			} else {
				$users_taken[ $uid ] = true;
			}
		}
	}
	unset( $a );
	return $actions;
}

/** Write one meta value and record what was there, so --undo can put it back. */
function fcmc_legacy_set( string $type, int $id, string $key, $new, array &$log ): void {
	$had = metadata_exists( $type, $id, $key );
	$old = $had ? ( 'post' === $type ? get_post_meta( $id, $key, true ) : get_user_meta( $id, $key, true ) ) : null;
	$log['changes'][] = array( 't' => $type, 'id' => $id, 'k' => $key, 'had' => $had, 'old' => $old, 'new' => $new );
	if ( 'post' === $type ) {
		update_post_meta( $id, $key, $new );
	} else {
		update_user_meta( $id, $key, $new );
	}
}

/** member_since on a user only ever moves earlier. */
function fcmc_legacy_user_since( int $uid, string $since, array &$log ): void {
	if ( '' === $since ) {
		return;
	}
	$cur = (string) get_user_meta( $uid, 'fcmc_member_since', true );
	if ( '' === $cur || $since < $cur ) {
		fcmc_legacy_set( 'user', $uid, 'fcmc_member_since', $since, $log );
	}
}

/** Create a legacy household. Title is the member's name, never an email. */
function fcmc_legacy_create_household( array $d, string $batch, array &$log ): int {
	$hid = wp_insert_post( array(
		'post_type'   => 'fcmc_household',
		'post_status' => 'publish',
		'post_title'  => '' !== $d['member1_name'] ? $d['member1_name'] : 'Household (legacy)',
		'post_author' => 0,
	), true );
	if ( is_wp_error( $hid ) ) {
		throw new RuntimeException( 'Household insert failed: ' . $hid->get_error_message() );
	}
	$hid = (int) $hid;
	foreach ( $d as $k => $v ) {
		update_post_meta( $hid, $k, $v );
	}
	update_post_meta( $hid, 'fcmc_source', FCMC_LEGACY_SOURCE );
	update_post_meta( $hid, 'import_batch', $batch );
	$log['created'][] = $hid;
	return $hid;
}

/** Enrich an existing household: history only, 2026 data wins (spec § 3). */
function fcmc_legacy_enrich( int $hid, array $d, array &$log ): void {
	$h = fcmc_household_get( $hid );

	if ( '' !== $d['member_since'] && ( '' === (string) $h['member_since'] || $d['member_since'] < $h['member_since'] ) ) {
		fcmc_legacy_set( 'post', $hid, 'member_since', $d['member_since'], $log );
	}
	$paid_changed = false;
	if ( '' !== $d['paid_through'] && $d['paid_through'] > (string) $h['paid_through'] ) {
		fcmc_legacy_set( 'post', $hid, 'paid_through', $d['paid_through'], $log );
		$paid_changed = true;
	}
	foreach ( fcmc_legacy_history_keys() as $k ) {
		if ( '' !== (string) $d[ $k ] && '' === (string) get_post_meta( $hid, $k, true ) ) {
			fcmc_legacy_set( 'post', $hid, $k, $d[ $k ], $log );
		}
	}
	if ( ! empty( $d['cars'] ) && empty( $h['cars'] ) ) {
		fcmc_legacy_set( 'post', $hid, 'cars', $d['cars'], $log );
	}

	$uid = (int) $h['claimed_by'];
	if ( $uid ) {
		fcmc_legacy_user_since( $uid, (string) $d['member_since'], $log );
		if ( $paid_changed ) {
			fcmc_household_sync_to_user( $hid );
		}
	}
}

/** Link a household created this batch to an existing account. The account's own data is untouched. */
function fcmc_legacy_link_user( int $hid, int $uid, array $d, array &$log ): void {
	update_post_meta( $hid, 'claimed_by', $uid ); // household is new this batch; undo deletes it
	fcmc_legacy_set( 'user', $uid, 'fcmc_household_id', $hid, $log );
	fcmc_legacy_user_since( $uid, (string) $d['member_since'], $log );
	if ( function_exists( 'fcmc_recompute_member' ) ) {
		fcmc_recompute_member( $uid );
	}
}

/** Execute actions. Dry run writes nothing. Returns per-type counts. */
function fcmc_legacy_apply( array $actions, string $batch, bool $dry ): array {
	$log    = array( 'created' => array(), 'changes' => array() );
	$errors = 0;
	foreach ( $actions as $a ) {
		if ( $dry || 'skip' === $a['type'] ) {
			continue;
		}
		try {
			$d = $a['row']['data'];
			if ( 'enrich' === $a['type'] ) {
				fcmc_legacy_enrich( (int) $a['target'], $d, $log );
				continue;
			}
			$hid = fcmc_legacy_create_household( $d, $batch, $log );
			if ( 'create_linked' === $a['type'] ) {
				fcmc_legacy_link_user( $hid, (int) $a['target'], $d, $log );
			}
		} catch ( \Throwable $e ) {
			$errors++;
			WP_CLI::warning( sprintf( 'Row %s failed and was skipped: %s', $a['row']['key'], $e->getMessage() ) );
		}
	}
	if ( ! $dry ) {
		$all           = get_option( 'fcmc_legacy_import_log', array() );
		$all           = is_array( $all ) ? $all : array();
		$all[ $batch ] = $log;
		update_option( 'fcmc_legacy_import_log', $all, false );
	}
	return array( 'errors' => $errors, 'created' => count( $log['created'] ), 'changes' => count( $log['changes'] ) );
}

/** Same value? Arrays compared loosely, scalars as strings (meta round-trips ints as strings). */
function fcmc_legacy_same( $a, $b ): bool {
	return ( is_array( $a ) || is_array( $b ) ) ? $a == $b : (string) $a === (string) $b; // phpcs:ignore Universal.Operators.StrictComparisons
}

/** Reverse one batch. Values changed since the batch are reported, never reverted. */
function fcmc_legacy_undo( string $batch, bool $dry ): array {
	$all = get_option( 'fcmc_legacy_import_log', array() );
	if ( ! is_array( $all ) || ! isset( $all[ $batch ] ) ) {
		throw new RuntimeException( "Unknown batch: {$batch}" );
	}
	$log = $all[ $batch ];
	$r   = array( 'deleted' => 0, 'restored' => 0, 'conflicts' => array() );

	foreach ( array_reverse( $log['changes'] ) as $c ) {
		if ( 'post' === $c['t'] && in_array( (int) $c['id'], $log['created'], true ) ) {
			continue; // the whole household goes below
		}
		$cur = 'post' === $c['t'] ? get_post_meta( $c['id'], $c['k'], true ) : get_user_meta( $c['id'], $c['k'], true );
		if ( ! fcmc_legacy_same( $cur, $c['new'] ) ) {
			$r['conflicts'][] = "{$c['t']} {$c['id']} {$c['k']} changed since import — left as is";
			continue;
		}
		$r['restored']++;
		if ( $dry ) {
			continue;
		}
		if ( $c['had'] ) {
			'post' === $c['t'] ? update_post_meta( $c['id'], $c['k'], $c['old'] ) : update_user_meta( $c['id'], $c['k'], $c['old'] );
		} else {
			'post' === $c['t'] ? delete_post_meta( $c['id'], $c['k'] ) : delete_user_meta( $c['id'], $c['k'] );
		}
		if ( 'post' === $c['t'] && 'paid_through' === $c['k'] ) {
			fcmc_household_sync_to_user( (int) $c['id'] );
		}
		if ( 'user' === $c['t'] && function_exists( 'fcmc_recompute_member' ) ) {
			fcmc_recompute_member( (int) $c['id'] );
		}
	}

	foreach ( $log['created'] as $hid ) {
		if ( ! get_post( $hid ) ) {
			continue;
		}
		$r['deleted']++;
		if ( ! $dry ) {
			wp_delete_post( $hid, true );
		}
	}

	if ( ! $dry ) {
		unset( $all[ $batch ] );
		update_option( 'fcmc_legacy_import_log', $all, false );
	}
	return $r;
}

WP_CLI::add_command( 'fcmc import-legacy', function ( $args, $assoc ) {
	$dry = ! empty( $assoc['dry-run'] );

	if ( ! empty( $assoc['undo'] ) ) {
		try {
			$r = fcmc_legacy_undo( (string) $assoc['undo'], $dry );
		} catch ( \Throwable $e ) {
			WP_CLI::error( $e->getMessage() );
		}
		WP_CLI::log( sprintf( '%sdeleted households: %d, restored values: %d, conflicts: %d', $dry ? '[DRY RUN] ' : '', $r['deleted'], $r['restored'], count( $r['conflicts'] ) ) );
		foreach ( $r['conflicts'] as $line ) {
			WP_CLI::log( $line );
		}
		WP_CLI::success( $dry ? 'Undo dry run complete — nothing written.' : 'Undo complete.' );
		return;
	}

	$path = (string) ( $args[0] ?? '' );
	if ( ! is_readable( $path ) ) {
		WP_CLI::error( "Cannot read: {$path}" );
	}
	$overrides = array( 'valid' => array(), 'invalid' => array() );
	if ( ! empty( $assoc['overrides'] ) ) {
		if ( ! is_readable( $assoc['overrides'] ) ) {
			WP_CLI::error( 'Cannot read overrides file.' );
		}
		$overrides = fcmc_legacy_parse_overrides( fcmc_legacy_read_csv( $assoc['overrides'] ) );
	}

	$merged             = fcmc_legacy_merge_duplicates( array_map( 'fcmc_legacy_map_row', fcmc_legacy_read_csv( $path ) ) );
	list( $ei, $ni )    = fcmc_legacy_build_index();
	$actions            = fcmc_legacy_validate_targets( fcmc_legacy_decide( $merged['rows'], $ei, $ni, $overrides['valid'] ) );
	$batch              = 'legacy-' . gmdate( 'Ymd\THis\Z' );
	$result             = fcmc_legacy_apply( $actions, $batch, $dry );

	foreach ( fcmc_legacy_report_lines( $actions, $merged['merges'], $overrides['invalid'] ) as $line ) {
		WP_CLI::log( ( $dry ? '[DRY RUN] ' : '' ) . $line );
	}
	WP_CLI::log( sprintf( 'rows read: %d, households created: %d, values written: %d, errors: %d', count( $actions ) + count( $merged['merges'] ), $result['created'], $result['changes'], $result['errors'] ) );
	WP_CLI::success( $dry ? 'Dry run complete — nothing written.' : "Import complete. Batch {$batch} (undo: wp fcmc import-legacy --undo={$batch})" );
} );
```

- [ ] **Step 2: Self-check** — re-read against spec § 2–§ 4 and § 9: email-only; enrich never touches contact/consent/newsletter keys (only `member_since`, `paid_through`, history keys, `cars`); `member_since` only earlier; undo skips changed values. Fix any mismatch.

- [ ] **Step 3: Commit**

```bash
git add fcmc/mu-plugins/fcmc-import-roster.php
git commit -m "feat(fcmc): wp fcmc import-legacy — index, logged writes, dry run, undo"
```

---

### Task 4: Household editor shows the history fields

**Files:**
- Modify: `fcmc/mu-plugins/fcmc-households.php` — `fcmc_household_get()` keys list (~line 111), `fcmc_household_editable_fields()` (~line 522), `$labels` in `fcmc_render_household_meta_box()` (~line 591)

**Interfaces:**
- Produces: `fcmc_household_get()` additionally returns `legacy_member_id, home_area, birthdays, directory_listed, legacy_stat` (strings, '' when absent). Task 5 relies on these keys.

- [ ] **Step 1: Add keys to `fcmc_household_get()`** — extend the `$keys` array:

```php
		'fcmc_source', 'claimed_by', 'import_batch',
		'legacy_member_id', 'home_area', 'birthdays', 'directory_listed', 'legacy_stat',
	);
```

- [ ] **Step 2: Make them editable** — append to the array returned by `fcmc_household_editable_fields()`:

```php
		'zip'              => 'text',
		'legacy_member_id' => 'text',
		'home_area'        => 'text',
		'birthdays'        => 'text',
		'directory_listed' => 'text',
		'legacy_stat'      => 'text',
	);
```

- [ ] **Step 3: Add labels** — append to `$labels` in `fcmc_render_household_meta_box()`:

```php
		'zip'              => __( 'ZIP', 'fcmc' ),
		'legacy_member_id' => __( 'Old member ID (1991–2020 database)', 'fcmc' ),
		'home_area'        => __( 'Home area', 'fcmc' ),
		'birthdays'        => __( 'Birthdays', 'fcmc' ),
		'directory_listed' => __( 'Listed in old printed directory (Y/N)', 'fcmc' ),
		'legacy_stat'      => __( 'Old status code (C/E/P — reference only)', 'fcmc' ),
	);
```

- [ ] **Step 4: Verify by reading** — the save loop already handles `'text'` fields via `sanitize_text_field`; no other change. Confirm every key in `fcmc_household_editable_fields()` has a `$labels` entry (a missing one would print an "undefined index" notice).

- [ ] **Step 5: Commit**

```bash
git add fcmc/mu-plugins/fcmc-households.php
git commit -m "feat(fcmc): household editor shows legacy history fields"
```

---

### Task 5: Roster — current members by default, complete-history filter, history line, quieter Needs-linking

**Files:**
- Modify: `fcmc/mu-plugins/fcmc-roster.php` — `fcmc_roster_rows()` (~line 164), roster render filter block (~lines 328–400), Member cell (~line 572), `fcmc_render_needs_linking()` unclaimed list (~lines 755–780)

**Interfaces:**
- Consumes: `fcmc_household_get()` history keys (Task 4), `FCMC_LEGACY_SOURCE` is NOT available here (lib loads for web requests too, but do not depend on it — compare to the literal `'legacy-2020'`).
- Produces: `fcmc_roster_household_status( array $h ): string`; roster status filter values `current` (default) and `all`.

- [ ] **Step 1: Extract the unclaimed-household status rule into a helper** (used by rows and Needs-linking). Add above `fcmc_roster_rows()`:

```php
/**
 * Status of an UNCLAIMED household, derived from its own paid_through — the rule
 * fcmc_roster_rows() has always used inline.
 */
function fcmc_roster_household_status( array $h ): string {
	$d = $h['paid_through'] ? DateTimeImmutable::createFromFormat( 'Y-m-d', $h['paid_through'], wp_timezone() ) : null;
	return fcmc_status_for( $d ?: null );
}
```

In `fcmc_roster_rows()` replace the inline block

```php
			$paid_through_date = $h['paid_through']
				? DateTimeImmutable::createFromFormat( 'Y-m-d', $h['paid_through'], wp_timezone() )
				: null;
			$status = fcmc_status_for( $paid_through_date ?: null );
```

with

```php
			$status = fcmc_roster_household_status( $h );
```

- [ ] **Step 2: Support `current` and `all` in the row filter.** Replace

```php
		if ( ! empty( $filters['status'] ) && $filters['status'] !== $status ) {
			continue;
		}
```

(both occurrences — the household loop and the unlinked-user loop) with

```php
		if ( ! fcmc_roster_status_matches( $filters['status'] ?? '', $status ) ) {
			continue;
		}
```

and add above `fcmc_roster_rows()`:

```php
/** '' or 'all' = everyone; 'current' = active + grace; otherwise an exact status. */
function fcmc_roster_status_matches( string $filter, string $status ): bool {
	if ( '' === $filter || 'all' === $filter ) {
		return true;
	}
	if ( 'current' === $filter ) {
		return in_array( $status, array( 'active', 'grace' ), true );
	}
	return $filter === $status;
}
```

- [ ] **Step 3: Carry history fields on each row.** In the household loop's `$rows[] = array( … )` add:

```php
			'legacy_id'    => $h['legacy_member_id'],
			'home_area'    => $h['home_area'],
			'birthdays'    => $h['birthdays'],
```

and in the unlinked-user loop's `$rows[]` add the same three keys with `''` values.

- [ ] **Step 4: Default the view to current members.** In the render function replace

```php
	$status_filter = isset( $_GET['fcmc_status'] ) ? sanitize_key( wp_unslash( $_GET['fcmc_status'] ) ) : '';
```

with

```php
	$status_filter = isset( $_GET['fcmc_status'] ) ? sanitize_key( wp_unslash( $_GET['fcmc_status'] ) ) : 'current';
```

replace

```php
	$valid = array( 'active', 'grace', 'lapsed', 'none' );
	if ( $status_filter && ! in_array( $status_filter, $valid, true ) ) {
		$status_filter = '';
	}
```

with

```php
	$valid = array( 'active', 'grace', 'lapsed', 'none' );
	if ( ! in_array( $status_filter, array_merge( array( 'current', 'all' ), $valid ), true ) ) {
		$status_filter = 'current';
	}
```

replace the first `<option>` line

```php
				<option value=""><?php esc_html_e( 'All', 'fcmc' ); ?></option>
```

with

```php
				<option value="current" <?php selected( $status_filter, 'current' ); ?>><?php esc_html_e( 'Current members', 'fcmc' ); ?></option>
				<option value="all" <?php selected( $status_filter, 'all' ); ?>><?php esc_html_e( 'All (complete history)', 'fcmc' ); ?></option>
```

and change the Clear-button condition from `<?php if ( $status_filter || $joined_since ) : ?>` to `<?php if ( 'current' !== $status_filter || $joined_since ) : ?>`.

- [ ] **Step 5: History line in the Member cell.** Replace

```php
					<strong><?php echo esc_html( $row['primary'] ); ?></strong><br />
					<a href="mailto:<?php echo esc_attr( $row['email'] ); ?>"><?php echo esc_html( $row['email'] ); ?></a>
```

with

```php
					<strong><?php echo esc_html( $row['primary'] ); ?></strong><br />
					<a href="mailto:<?php echo esc_attr( $row['email'] ); ?>"><?php echo esc_html( $row['email'] ); ?></a>
					<?php
					$history = array_filter( array(
						$row['legacy_id'] ? sprintf( /* translators: %s: old member number */ __( 'Old #%s', 'fcmc' ), $row['legacy_id'] ) : '',
						$row['home_area'],
						$row['birthdays'] ? sprintf( /* translators: %s: birthdays */ __( 'Birthdays %s', 'fcmc' ), $row['birthdays'] ) : '',
					) );
					?>
					<?php if ( $history ) : ?>
						<br /><small class="fcmc-history"><?php echo esc_html( implode( ' · ', $history ) ); ?></small>
					<?php endif; ?>
```

and add `.fcmc-roster .fcmc-history { opacity: .7; }` to the `<style>` block next to `.fcmc-roster .fcmc-none`.

- [ ] **Step 6: Keep Needs-linking usable.** In `fcmc_render_needs_linking()`, after `$unclaimed = fcmc_roster_unclaimed_households();` add:

```php
	// Former members from the 1991–2020 history import aren't expected to have accounts —
	// listing ~110 of them would bury the real work. They stay in the Link dropdown below.
	$unclaimed_to_list = array_values( array_filter( $unclaimed, function ( $h ) {
		return 'legacy-2020' !== $h['fcmc_source']
			|| in_array( fcmc_roster_household_status( $h ), array( 'active', 'grace' ), true );
	} ) );
	$former_count = count( $unclaimed ) - count( $unclaimed_to_list );
```

In the "Unclaimed households" heading/list use `$unclaimed_to_list` instead of `$unclaimed` (the `printf` count, the `empty()` check and the `foreach`), and right after the list add:

```php
	<?php if ( $former_count > 0 ) : ?>
		<p><em>
			<?php
			printf(
				/* translators: %d: number of former members */
				esc_html__( 'Plus %d former members from the 1991–2020 history, not listed here — they are not expected to have accounts. They can still be picked in the Link control below.', 'fcmc' ),
				(int) $former_count
			);
			?>
		</em></p>
	<?php endif; ?>
```

Leave the `elseif ( empty( $unclaimed ) )` branch and the Link `<select>` using the full `$unclaimed` list.

- [ ] **Step 7: Self-check** — counts line still uses `fcmc_roster_rows()` with no filter (whole roster); `fcmc_roster_rows()` callers elsewhere (`grep -n "fcmc_roster_rows(" fcmc/mu-plugins/*.php`) still get every row when called without a status.

- [ ] **Step 8: Commit**

```bash
git add fcmc/mu-plugins/fcmc-roster.php
git commit -m "feat(fcmc): roster defaults to current members, adds complete-history view"
```

---

### Task 6 🔒 GATED: Lint and deploy the four mu-plugins (one SSH session)

**Ask Bradley first:** "Ready to deploy the four FCMC mu-plugin files to firstcoastmiataclub.org over one SSH session (lint, back up the current files, install, byte-check)? Nothing imports yet." Wait for yes.

Server paths: mu-plugins at `~/public_html/fcmc-dev/wp-content/mu-plugins/`; PHP `/opt/cpanel/ea-php83/root/usr/bin/php`; WP-CLI `cd ~/public_html/fcmc-dev && /opt/cpanel/ea-php83/root/usr/bin/php /usr/local/bin/wp`.

- [ ] **Step 1: Copy to a staging folder and lint** (from the repo root):

```bash
scp fcmc/mu-plugins/fcmc-legacy-lib.php fcmc/mu-plugins/fcmc-import-roster.php fcmc/mu-plugins/fcmc-households.php fcmc/mu-plugins/fcmc-roster.php cornerfa:~/fcmc-deploy/
```

then one session:

```bash
ssh cornerfa 'set -e; P=/opt/cpanel/ea-php83/root/usr/bin/php; M=~/public_html/fcmc-dev/wp-content/mu-plugins; S=~/fcmc-deploy; TS=$(date +%Y%m%d-%H%M%S);
for f in fcmc-legacy-lib.php fcmc-import-roster.php fcmc-households.php fcmc-roster.php; do $P -l $S/$f; done;
mkdir -p ~/fcmc-backup/$TS; for f in fcmc-import-roster.php fcmc-households.php fcmc-roster.php; do cp -p $M/$f ~/fcmc-backup/$TS/; done;
for f in fcmc-legacy-lib.php fcmc-import-roster.php fcmc-households.php fcmc-roster.php; do cp $S/$f $M/$f; done;
sha256sum $M/fcmc-legacy-lib.php $M/fcmc-import-roster.php $M/fcmc-households.php $M/fcmc-roster.php; echo backup=~/fcmc-backup/$TS;
cd ~/public_html/fcmc-dev && $P /usr/local/bin/wp help fcmc import-legacy | head -3'
```

Expected: `No syntax errors detected` ×4; four hashes; `wp help` prints the command.

- [ ] **Step 2: Byte-check locally:** `shasum -a 256 fcmc/mu-plugins/fcmc-legacy-lib.php fcmc/mu-plugins/fcmc-import-roster.php fcmc/mu-plugins/fcmc-households.php fcmc/mu-plugins/fcmc-roster.php` — every hash must equal the server's.
- [ ] **Step 3: Smoke check in a browser (Bradley):** open My Account → Club Roster as an officer: page loads, filter shows "Current members" selected, counts line unchanged from before. If anything errors: restore from the printed backup folder in one SSH command and stop.
- [ ] **Step 4: Rollback command (only if needed):** `ssh cornerfa 'cp -p ~/fcmc-backup/<TS>/*.php ~/public_html/fcmc-dev/wp-content/mu-plugins/ && rm ~/public_html/fcmc-dev/wp-content/mu-plugins/fcmc-legacy-lib.php'`

---

### Task 7 🔒 GATED: Server test with synthetic data (create, link, claim, undo)

**Ask Bradley first:** "Run the synthetic test on the live site? It creates 2 fake households and 1 fake account (@example.invalid), checks them, then removes everything with undo + delete." Wait for yes.

- [ ] **Step 1: One SSH session — setup, dry run, real run, checks**

```bash
scp fcmc/tests/fixtures/legacy-synthetic.csv cornerfa:~/fcmc-import/
ssh cornerfa 'cd ~/public_html/fcmc-dev && W="/opt/cpanel/ea-php83/root/usr/bin/php /usr/local/bin/wp";
U=$($W user create legacytest-bravo bravo.linked@example.invalid --role=customer --porcelain --skip-email);
echo user=$U;
$W fcmc import-legacy ~/fcmc-import/legacy-synthetic.csv --dry-run;
$W fcmc import-legacy ~/fcmc-import/legacy-synthetic.csv;
$W eval "\$h=get_posts([\"post_type\"=>\"fcmc_household\",\"meta_key\"=>\"legacy_member_id\",\"meta_value\"=>\"9101\",\"fields\"=>\"ids\"]); \$x=fcmc_household_get(\$h[0]); echo \$x[\"paid_through\"],\" \",\$x[\"member_since\"],\" \",fcmc_roster_household_status(\$x),\" \",count(\$x[\"cars\"]),\" \",\$x[\"cars\"][0][\"car_generation\"],\"\n\";";
$W eval "echo get_user_meta($U,\"fcmc_member_since\",true),\" hh=\",get_user_meta($U,\"fcmc_household_id\",true),\"\n\";"'
```

Expected: dry run reports `create: 1`, `create_linked: 1`, `skip: 1` (`no-contact`); real run prints a batch id; household 9101 → `2012-06-01 2005-03-01 lapsed 1 NA`; user → `2012-02-11 hh=<id>`.

Note: `wp user create` fires `user_register` → `fcmc_maybe_claim_household`, which sends a verification email to the `.invalid` address if a household already matches that email (for `legacytest-alpha`, created in Step 2 after the import, it will). `--skip-email` only suppresses WordPress's new-user notice, not this one. The mail bounces harmlessly; ignore it. (`legacytest-bravo` is created before the import, so no household matches yet and nothing is sent.)

- [ ] **Step 2: Claim test (Review Focus — returning member)** — same session style:

```bash
ssh cornerfa 'cd ~/public_html/fcmc-dev && W="/opt/cpanel/ea-php83/root/usr/bin/php /usr/local/bin/wp";
U2=$($W user create legacytest-alpha alpha.lapsed@example.invalid --role=customer --porcelain --skip-email); echo user=$U2;
$W eval "\$h=fcmc_household_find_by_email(\"alpha.lapsed@example.invalid\"); fcmc_household_claim(\$h,$U2); echo get_user_meta($U2,\"fcmc_member_since\",true),\" cars=\",count((array)get_user_meta($U2,\"fcmc_car_profiles\",true)),\" consent=\",var_export(get_user_meta($U2,\"fcmc_consent_directory_name\",true),true),\"\n\";"'
```

Expected: `2005-03-01 cars=1 consent=''` — history copied, consents NOT copied (spec § 4). (`fcmc_household_claim` is called directly to stand in for the emailed verification click.)

- [ ] **Step 3: Undo with a hand edit (Review Focus 5)** — undo keeps any household that has been claimed since the import (it reports `household <id> was claimed since import — left as is` and leaves it live), and Step 2 claimed household 9101 for `legacytest-alpha`. So first **unclaim** 9101, then change one imported value by hand, then undo:

```bash
ssh cornerfa 'cd ~/public_html/fcmc-dev && W="/opt/cpanel/ea-php83/root/usr/bin/php /usr/local/bin/wp";
B=$($W eval "echo array_key_last(get_option(\"fcmc_legacy_import_log\"));");
U2=$($W user get legacytest-alpha --field=ID);
$W eval "\$h=get_posts([\"post_type\"=>\"fcmc_household\",\"meta_key\"=>\"legacy_member_id\",\"meta_value\"=>\"9101\",\"fields\"=>\"ids\"]); delete_post_meta(\$h[0],\"claimed_by\"); delete_user_meta($U2,\"fcmc_household_id\");";
U=$($W user get legacytest-bravo --field=ID); $W user meta update $U fcmc_member_since 2010-01-01;
$W fcmc import-legacy --undo=$B --dry-run; $W fcmc import-legacy --undo=$B;
$W eval "echo count(get_posts([\"post_type\"=>\"fcmc_household\",\"meta_key\"=>\"fcmc_source\",\"meta_value\"=>\"legacy-2020\",\"fields\"=>\"ids\"])),\" since=\",get_user_meta($U,\"fcmc_member_since\",true),\" hh=\",get_user_meta($U,\"fcmc_household_id\",true),\"\n\";";
$W user delete $U $($W user get legacytest-alpha --field=ID) --yes; rm ~/fcmc-import/legacy-synthetic.csv'
```

Expected (worked out from `fcmc_legacy_undo`): the household loop deletes every household in the batch's `created` list whose `claimed_by` equals what the log expects — `0` for the unclaimed 9101 after the unclaim above, and the bravo user's id for the create_linked household (its `fcmc_household_id` change is in the log) — so `deleted households: 2`. The only value that no longer equals its logged `new` is bravo's hand-edited `fcmc_member_since`, so exactly one conflict line `user <U> fcmc_member_since changed since import — left as is`; every other logged user value (`fcmc_household_id`, any `fcmc_paid_through_manual` floor) still matches and is restored. Without the unclaim you would instead see `deleted households: 1` and a second conflict `household <9101 id> was claimed since import — left as is`, with 9101 left live. Final result: final eval prints `0 since=2010-01-01 hh=` (household gone, hand edit preserved, link removed); test users deleted.

- [ ] **Step 4:** Record outcomes (counts only) in the task notes; if any expectation fails, stop and fix before Task 8.

---

### Task 8 🔒 GATED: Real data — export and dry run

**Ask Bradley first:** "Please download the Google Sheet *Total DB Record - Members* as CSV (File → Download → .csv) and save it as `~/Documents/FCMC-private/fcmc-legacy-2020-members.csv`. Then may I copy it to the server and run the dry run (one SSH session)?" Downloading it yourself keeps member data out of this session's transcript.

- [ ] **Step 1:** Confirm the file exists without printing contents: `wc -l ~/Documents/FCMC-private/fcmc-legacy-2020-members.csv` (expect ~133).
- [ ] **Step 2: One session — copy and dry run**

```bash
ssh cornerfa 'mkdir -p ~/fcmc-import && chmod 700 ~/fcmc-import'
scp ~/Documents/FCMC-private/fcmc-legacy-2020-members.csv cornerfa:~/fcmc-import/
ssh cornerfa 'cd ~/public_html/fcmc-dev && /opt/cpanel/ea-php83/root/usr/bin/php /usr/local/bin/wp fcmc import-legacy ~/fcmc-import/fcmc-legacy-2020-members.csv --dry-run' > ~/Documents/FCMC-private/dry-run-1.txt; tail -3 ~/Documents/FCMC-private/dry-run-1.txt
```

- [ ] **Step 3: Check the report (keys and counts only — the report contains no PII by construction):**
  - `create + create_linked + enrich + skip + merges` = data rows read.
  - `id:632` appears as `create_linked` (not in skips, not a near-match only).
  - Present to Bradley: the counts, every `skip`, `near-match`, `same-name-in-sheet`, `ambiguous` and `warning` line.

---

### Task 9 🔒 GATED: Decide near-matches, real run, verify, clean up

- [ ] **Step 1:** For each near-match/ambiguous line, Bradley decides: link to household (`link-household,<id>`), link to account (`link-user,<id>`), `skip`, or leave as a new household. Write `~/Documents/FCMC-private/fcmc-legacy-overrides.csv`:

```csv
key,action,target
```

(one line per decision, e.g. `id:845,link-user,52`).

- [ ] **Step 2:** 🔒 Ask: "Run the second dry run with your overrides?" → one session: scp overrides, dry run with `--overrides=~/fcmc-import/fcmc-legacy-overrides.csv`, save to `dry-run-2.txt`, show counts + override lines.
- [ ] **Step 3:** 🔒 Ask: "Run the real import now?" → one session: real run with the same arguments, save output to `~/Documents/FCMC-private/real-run.txt` (note the batch id), then `rm ~/fcmc-import/*.csv`.
- [ ] **Step 4: Verify (Bradley in browser + one read-only SSH eval):**
  - Roster default shows only current members; "All (complete history)" shows the history; counts line: Active unchanged, Lapsed ≈ previous + new households.
  - Old #632's row: Joined `2012-02-11`, history line `Old #632 · Southside`.
  - Needs-linking shows "Plus N former members…" instead of a ~110-row list.
  - Re-run check (Review Focus 4): `wp fcmc import-legacy <csv> --dry-run` again → `create: 0` (everything now enriches or is skipped). Requires re-copying the CSV for that one command, then deleting it again.
- [ ] **Step 5:** If anything is wrong: `wp fcmc import-legacy --undo=<batch>` (dry run first), fix, repeat Task 9.
- [ ] **Step 6: Close the undo window.** Once Bradley says the import is accepted and undo is no longer needed, delete the log: `wp option delete fcmc_legacy_import_log` (one SSH session). ⚠️ The option holds old and new member values (dates, history fields, car data) for every changed household/account — **never run `wp option get fcmc_legacy_import_log` unfiltered** (it dumps that into the terminal/transcript); to find a batch id use `wp eval 'echo implode(",", array_keys((array) get_option("fcmc_legacy_import_log")));'`. Until it is deleted, undo stays possible; after, it is not.

---

### Task 10: Docs and release

**Files:**
- Modify: `fcmc/README.md` (add a "Legacy history import" section: command, flags, PII rules, batch undo, where the source lives)
- Modify: `docs/sites.md` (FCMC section: history imported, roster default, 2021–2025 gap open)

- [ ] **Step 1:** Write both doc updates (no member data; counts only). Confirm `fcmc_legacy_import_log` was deleted (Task 9 Step 6) or note in `docs/sites.md` that it still exists and holds member values.
- [ ] **Step 2:** Commit on the feature branch.
- [ ] **Step 3:** Release per gitflow (code is live after Task 6): merge `feature/fcmc-legacy-import` → `develop` (`--no-ff`), create `release/2026-10-xx` from `develop`, merge to `main` and back to `develop`, delete branches.
- [ ] **Step 4:** 🔒 Ask before `git push origin main develop`.
- [ ] **Step 5:** Update project memory: legacy import done, batch id, counts, 2021–2025 gap still open (Lisa's records).
