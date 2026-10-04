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

// Transitive merge: A(a), B(b), C(a,b) → 1 row, 2 merge pairs
$rowA = $row; $rowA['MEMBER_ID'] = '9001'; $rowA['PERSONAL_E-MAIL ADDRESS'] = 'aaa@example.invalid'; $rowA['E-MAIL ADDRESS 2'] = '';
$a_trans = fcmc_legacy_map_row( $rowA );
$rowB = $row; $rowB['MEMBER_ID'] = '9002'; $rowB['PERSONAL_E-MAIL ADDRESS'] = 'bbb@example.invalid'; $rowB['E-MAIL ADDRESS 2'] = '';
$b_trans = fcmc_legacy_map_row( $rowB );
$rowC = $row; $rowC['MEMBER_ID'] = '9003'; $rowC['PERSONAL_E-MAIL ADDRESS'] = 'aaa@example.invalid'; $rowC['E-MAIL ADDRESS 2'] = 'bbb@example.invalid';
$c_trans = fcmc_legacy_map_row( $rowC );
$merged_trans = fcmc_legacy_merge_duplicates( array( $a_trans, $b_trans, $c_trans ) );
check( 'transitive merge -> one row', count( $merged_trans['rows'] ), 1 );
check( 'transitive merge emails', $merged_trans['rows'][0]['emails'], array( 'aaa@example.invalid', 'bbb@example.invalid' ) );
check( 'transitive merge pairs count', count( $merged_trans['merges'] ), 2 );
check( 'transitive merge first pair', $merged_trans['merges'][0], array( 'id:9001', 'id:9003' ) );
check( 'transitive merge second pair', $merged_trans['merges'][1], array( 'id:9001', 'id:9002' ) );

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

/* ---- Final-review fixes ---- */
// C1: two-digit year pivot (yy > current yy -> 19yy, else 20yy)
check( 'date yy 99 -> 1999', fcmc_legacy_parse_date( '5/31/99' ), '1999-05-31' );
check( 'date yy 95 -> 1995', fcmc_legacy_parse_date( '3/1/95' ), '1995-03-01' );
check( 'date yy 05 -> 2005', fcmc_legacy_parse_date( '1/1/05' ), '2005-01-01' );

// C1b: PII-free sanity lines (grace = 60 days after paid_through, mirroring fcmc_status_for)
$mk = function ( string $type, string $paid, string $since ) {
	return array( 'type' => $type, 'row' => array( 'key' => 'id:1', 'data' => array( 'paid_through' => $paid, 'member_since' => $since ) ) );
};
$sl = fcmc_legacy_sanity_lines( array(
	$mk( 'create', '2026-10-05', '1991-01-01' ),   // active (today < paid)
	$mk( 'create', '2026-10-04', '2001-01-01' ),   // paid == today -> grace
	$mk( 'create_linked', '2026-08-06', '' ),      // today < paid+60d (2026-10-05) -> grace
	$mk( 'create', '2026-08-05', '2020-05-01' ),   // paid+60d == today -> lapsed
	$mk( 'create', '', '' ),                       // none
	$mk( 'enrich', '2015-06-01', '1995-01-01' ),   // not counted for status; counted for years
	array( 'type' => 'skip', 'row' => array( 'key' => 'id:2', 'data' => array() ) ),
), '2026-10-04' );
check( 'sanity active', in_array( 'would-create status active: 1', $sl, true ), true );
check( 'sanity grace', in_array( 'would-create status grace: 2', $sl, true ), true );
check( 'sanity lapsed', in_array( 'would-create status lapsed: 1', $sl, true ), true );
check( 'sanity none', in_array( 'would-create status none: 1', $sl, true ), true );
check( 'sanity since years', in_array( 'member_since years: 1991–2020', $sl, true ), true );
check( 'sanity paid years', in_array( 'paid_through years: 2015–2026', $sl, true ), true );
check( 'sanity empty years', fcmc_legacy_sanity_lines( array(), '2026-10-04' )[4], 'member_since years: n/a' );

// I3: row-key index for email-less rows
$nomail = $row; $nomail['MEMBER_ID'] = '9005'; $nomail['PERSONAL_E-MAIL ADDRESS'] = ''; $nomail['E-MAIL ADDRESS 2'] = '';
$nm = fcmc_legacy_map_row( $nomail );
check( 'nomail row key', $nm['key'], 'id:9005' );
$act = fcmc_legacy_decide( array( $nm ), array(), array(), array(), array( 'id:9005' => array( 'h:31' ) ) );
check( 'key index -> enrich', array( $act[0]['type'], $act[0]['target'] ), array( 'enrich', 31 ) );
$act = fcmc_legacy_decide( array( $a ), array( 'pat@example.invalid' => array( 'h:31' ) ), array(), array(), array( 'id:9001' => array( 'h:31' ) ) );
check( 'key hit + same email household -> one target', array( $act[0]['type'], $act[0]['target'] ), array( 'enrich', 31 ) );
$act = fcmc_legacy_decide( array( $a ), array( 'pat@example.invalid' => array( 'h:40' ) ), array(), array(), array( 'id:9001' => array( 'h:31' ) ) );
check( 'key hit + different email target -> ambiguous', array( $act[0]['type'], $act[0]['reason'] ), array( 'skip', 'ambiguous' ) );
$act = fcmc_legacy_decide( array( $nm ), array(), array(), array(), array() );
check( 'no key index -> create', $act[0]['type'], 'create' );

// I4a: non-numeric link targets rejected
$ov2 = fcmc_legacy_parse_overrides( array(
	array( 'key' => 'id:1', 'action' => 'link-user', 'target' => '12abc' ),
	array( 'key' => 'id:2', 'action' => 'link-household', 'target' => ' 12 ' ),
) );
check( 'override 12abc invalid', $ov2['invalid'], array( 'id:1' ) );
check( 'override padded digits ok', $ov2['valid'], array( 'id:2' => array( 'action' => 'link-household', 'target' => 12 ) ) );

// I4c: override keys matching no row
$l = fcmc_legacy_report_lines( fcmc_legacy_decide( array( $a ), array(), array(), array() ), array(), array(), array( 'id:9001', 'id:7777' ) );
check( 'override unmatched reported', in_array( 'override id:7777 matched no row', $l, true ), true );
check( 'override matched not reported', in_array( 'override id:9001 matched no row', $l, true ), false );

echo $GLOBALS['fails'] ? "\n{$GLOBALS['fails']} FAILED\n" : "\nALL PASSED\n";
exit( $GLOBALS['fails'] ? 1 : 0 );
