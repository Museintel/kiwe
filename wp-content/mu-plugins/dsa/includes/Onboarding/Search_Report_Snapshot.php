<?php
namespace DSA\Onboarding;

if ( ! defined( 'ABSPATH' ) ) exit;

/** Passive shared projection of the existing completed search report; no worker or provider dependencies. */
final class Search_Report_Snapshot {
	public const OPTION = 'kiwe_seo_checklist_v2';
	public const LEGACY_OPTION = 'kiwe_overall_seo_report_v1';
	public const BYTES = 16384;
	public static function read(): array {
		$raw = get_option( self::OPTION, null );
		$state = self::decode( $raw );
		if ( null !== $raw && ! $state ) return [ 'report'=>null,'pending'=>false,'inspected'=>0,'error'=>'The checklist state needs inspection by your website manager.' ];
		$report = $state['report'] ?? get_option( self::LEGACY_OPTION, null );
		if ( ! is_array( $report ) || ! is_int( $report['score'] ?? null ) || $report['score'] < 0 || $report['score'] > 100 ) $report = null;
		return [ 'report'=>$report,'legacy'=>! isset( $state['report'] ) && null !== $report,'pending'=>! $state || $state['requested'] !== $state['completed'],'inspected'=>$state['job']['inspected'] ?? 0,'error'=>$state['error'] ?? '' ];
	}
	public static function decode( $raw ): array {
		if ( ! is_string( $raw ) || strlen( $raw ) > self::BYTES ) return [];
		$s = json_decode( $raw, true );
		if ( ! is_array( $s ) || 1 !== ( $s['schema'] ?? null ) || ! is_string( $s['error'] ?? null ) || strlen( $s['error'] ) > 300 ) return [];
		foreach ( [ 'requested','completed' ] as $key ) if ( ! is_string( $s[ $key ] ?? null ) || ( '' !== $s[ $key ] && ! preg_match( '/^[a-f0-9]{32}$/D', $s[ $key ] ) ) ) return [];
		foreach ( [ 'nextAt','updatedAt' ] as $key ) if ( ! is_int( $s[ $key ] ?? null ) || $s[ $key ] < 0 ) return [];
		foreach ( [ 'lease','job','report' ] as $key ) if ( ! array_key_exists( $key, $s ) ) return [];
		if ( null !== $s['lease'] && ( ! is_array( $s['lease'] ) || ! is_string( $s['lease']['token'] ?? null ) || ! preg_match( '/^[a-f0-9]{32}$/D', $s['lease']['token'] ) || ! is_int( $s['lease']['until'] ?? null ) || $s['lease']['until'] < 0 ) ) return [];
		if ( null !== $s['job'] ) {
			$j = $s['job']; if ( ! is_array( $j ) || ! is_string( $j['request'] ?? null ) || ! preg_match( '/^[a-f0-9]{32}$/D', $j['request'] ) || ! is_bool( $j['commerce'] ?? null ) || ! is_array( $j['onboarding'] ?? null ) || ! is_array( $j['counts'] ?? null ) ) return [];
			foreach ( [ 'upper','cursor','inspected','startedAt' ] as $key ) if ( ! is_int( $j[ $key ] ?? null ) || $j[ $key ] < 0 ) return [];
			if ( $j['cursor'] > $j['upper'] || ! self::component( $j['onboarding'] ) ) return [];
			foreach ( [ 'pages','posts','products','media' ] as $scope ) {
				$c = $j['counts'][ $scope ] ?? [];
				foreach ( [ 'points','ready','total' ] as $key ) if ( ! is_int( $c[ $key ] ?? null ) || $c[ $key ] < 0 ) return [];
				if ( $c['ready'] > $c['total'] || $c['points'] > $c['total'] * 100 ) return [];
			}
		}
		if ( null !== $s['report'] ) {
			$r = $s['report']; if ( ! is_array( $r ) || ! is_int( $r['score'] ?? null ) || $r['score'] < 0 || $r['score'] > 100 || ! is_string( $r['calculatedAt'] ?? null ) || ! is_array( $r['components'] ?? null ) ) return [];
			foreach ( [ 'onboarding','pages','posts','products','media' ] as $scope ) if ( ! self::component( $r['components'][ $scope ] ?? [] ) ) return [];
		}
		return $s;
	}
	public static function component( $c ): bool {
		if ( ! is_array( $c ) ) return false;
		foreach ( [ 'score','ready','total','weight' ] as $key ) if ( ! is_int( $c[ $key ] ?? null ) || $c[ $key ] < 0 ) return false;
		return $c['score'] <= 100 && $c['ready'] <= $c['total'] && $c['weight'] <= 100 && is_string( $c['label'] ?? null ) && is_bool( $c['active'] ?? null );
	}
}
