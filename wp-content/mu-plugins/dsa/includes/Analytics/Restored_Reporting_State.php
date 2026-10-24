<?php
namespace DSA\Analytics;
if ( ! defined( 'ABSPATH' ) ) exit;

/** Copied historical identifiers are inert until an explicit provenance adapter exists. */
final class Restored_Reporting_State {
	public const OPTION = 'kiwe_restored_reporting_v1';
	public const TABLES = [ 'kiwe_search_buckets_v1','kiwe_search_rows_v1','stp_report_days','stp_report_sessions','stp_report_activity' ];
	public static function blocks( string $family ): bool {
		$state = get_option( self::OPTION, [] );
		if ( [] === $state ) return false;
		if ( ! is_array( $state ) || array_diff( array_keys( $state ), [ 'schema','identityMapped','recoverySha256','tables' ] ) || ( $state['schema'] ?? null ) !== 1 || ( $state['identityMapped'] ?? null ) !== false || ! is_string( $state['recoverySha256'] ?? null ) || ! preg_match( '/^[a-f0-9]{64}$/D', $state['recoverySha256'] ) || ! is_array( $state['tables'] ?? null ) || count( $state['tables'] ) > 5 ) return true;
		foreach ( $state['tables'] as $table ) if ( ! is_string( $table ) || ! in_array( $table, self::TABLES, true ) ) return true;
		$prefix = 'search' === $family ? 'kiwe_search_' : 'stp_report_';
		foreach ( $state['tables'] as $table ) if ( str_starts_with( $table, $prefix ) ) return true;
		return false;
	}
}
