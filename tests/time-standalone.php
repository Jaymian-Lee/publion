<?php
/**
 * Standalone checks for publion_mysql_to_timestamp() and the cron scheduled-slot
 * guard it feeds. No WordPress required: minimal stubs, then the real
 * functions-time.php is loaded. Run: php tests/time-standalone.php
 */

error_reporting( E_ALL );
define( 'ABSPATH', '/tmp/' );

$GLOBALS['__tz'] = 'UTC';
function wp_timezone() { return new DateTimeZone( $GLOBALS['__tz'] ); }

require __DIR__ . '/../publion/includes/functions-time.php';

$fails = 0;
function check( $label, $cond ) {
	global $fails;
	if ( $cond ) { echo "OK   $label\n"; } else { $fails++; echo "FAIL $label\n"; }
}

// 1. UTC: the string converts as-is.
$GLOBALS['__tz'] = 'UTC';
check( 'UTC: 16:30 converts to the same UTC epoch', publion_mysql_to_timestamp( '2026-11-06 16:30:00' ) === strtotime( '2026-11-06 16:30:00 UTC' ) );

// 2. Europe/Amsterdam winter (CET, +1): local 16:30 is 15:30 UTC.
$GLOBALS['__tz'] = 'Europe/Amsterdam';
check( 'Amsterdam winter: local 16:30 is UTC-3600', publion_mysql_to_timestamp( '2026-11-06 16:30:00' ) === strtotime( '2026-11-06 16:30:00 UTC' ) - 3600 );

// 3. Europe/Amsterdam summer (CEST, +2, DST): local 16:30 is 14:30 UTC.
check( 'Amsterdam summer DST: local 16:30 is UTC-7200', publion_mysql_to_timestamp( '2026-07-06 16:30:00' ) === strtotime( '2026-07-06 16:30:00 UTC' ) - 7200 );

// 4. Empty input converts to 0 (guard treats it as "not scheduled").
check( 'empty datetime converts to 0', 0 === publion_mysql_to_timestamp( '' ) );

// 5. Guard semantics with true epochs (the 1.9.59 fix): a slot 30 minutes ahead
//    must block, a slot 30 minutes behind must allow, in every offset.
foreach ( array( 'UTC', 'Europe/Amsterdam', 'Australia/Sydney' ) as $tz ) {
	$GLOBALS['__tz'] = $tz;
	$z               = new DateTimeZone( $tz );
	$future          = ( new DateTimeImmutable( '@' . ( time() + 1800 ) ) )->setTimezone( $z )->format( 'Y-m-d H:i:s' );
	$past            = ( new DateTimeImmutable( '@' . ( time() - 1800 ) ) )->setTimezone( $z )->format( 'Y-m-d H:i:s' );
	check( "$tz: slot 30 min ahead blocks under time()", time() < publion_mysql_to_timestamp( $future ) );
	check( "$tz: slot 30 min behind allows under time()", ! ( time() < publion_mysql_to_timestamp( $past ) ) );
}

// 6. Regression for the old comparison: current_time('timestamp') returns the
//    offset-shifted value time() + gmt_offset, which let slots run up to
//    gmt_offset early. Demonstrate the old expression would have allowed a
//    slot 30 minutes ahead in a +1 timezone while the fixed expression blocks.
$GLOBALS['__tz'] = 'Europe/Amsterdam';
$z               = new DateTimeZone( 'Europe/Amsterdam' );
$offset          = $z->getOffset( new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) ) );
$future          = ( new DateTimeImmutable( '@' . ( time() + 1800 ) ) )->setTimezone( $z )->format( 'Y-m-d H:i:s' );
$ts              = publion_mysql_to_timestamp( $future );
$old_now         = time() + $offset; // current_time( 'timestamp' ) semantics
check( 'regression: old shifted comparison would publish ~30 min early', ! ( $old_now < $ts ) );
check( 'regression: fixed true-epoch comparison blocks the same slot', time() < $ts );

// 7. Zero-offset timezone: old and new comparisons agree (bug was offset-only).
$GLOBALS['__tz'] = 'UTC';
$future          = ( new DateTimeImmutable( '@' . ( time() + 1800 ) ) )->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
$ts              = publion_mysql_to_timestamp( $future );
check( 'UTC: old and new comparisons agree', ( time() < $ts ) === ( ( time() + 0 ) < $ts ) );

echo $fails ? "\n$fails FAILURES\n" : "\nALL PASS\n";
exit( $fails ? 1 : 0 );
