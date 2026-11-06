<?php
/** Timezone-safe conversions between MySQL local datetimes and true UNIX epochs. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Convert a MySQL local datetime (stored with current_time('mysql')) to a UNIX timestamp
 * honoring the site timezone (so wp_date() renders correctly).
 */
function publion_mysql_to_timestamp( $mysql_datetime ) {
	if ( empty( $mysql_datetime ) ) {
		return 0;
	}
	try {
		$tz = wp_timezone();
		$dt = DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', $mysql_datetime, $tz );
		if ( $dt instanceof DateTimeImmutable ) {
			return $dt->getTimestamp();
		}
	} catch ( Throwable $e ) {}
	return strtotime( $mysql_datetime );
}
