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
