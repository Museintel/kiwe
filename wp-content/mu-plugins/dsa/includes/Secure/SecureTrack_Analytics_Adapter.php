<?php
namespace DSA\Secure;

if ( ! defined( 'ABSPATH' ) ) exit;

/** Keep SecureTrack configuration behind its owning boundary for reporting. */
final class SecureTrack_Analytics_Adapter {
	public static function period(string $from,string $to,string $url='',string $kind='traffic'): ?array {
		return SecureTrack_Report_Service::period($from,$to,$url,$kind);
	}
	public static function records_pages(): bool {
		if ( ! function_exists( 'stp_cfg' ) ) return false;
		$config = stp_cfg();
		return ! empty( $config['track_visitors'] ) && ! empty( $config['track_pages'] ) && SecureTrack_Report_Service::ready();
	}
}
