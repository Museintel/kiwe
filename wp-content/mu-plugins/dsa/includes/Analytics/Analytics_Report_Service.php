<?php
namespace DSA\Analytics;

use DSA\Access\Analytics_Workspace_Service as Workspace;
use DSA\Access\Task_Workspace_UI;
use DSA\Onboarding\Design_Context_Profile_Service;
use DSA\Secure\SecureTrack_Analytics_Adapter;

if ( ! defined( 'ABSPATH' ) ) exit;

/** Read-only aggregates over native owners. Never installs tables, identifies visitors or invokes AI. */
final class Analytics_Report_Service {
	public static function goal(): array {
		return \DSA\Onboarding\SEO_Context_Service::goal();
	}
	public static function goal_hash(): string { return \DSA\Onboarding\SEO_Context_Service::goal_hash(); }
	private function table( string $suffix ): string {
		global $wpdb;
		$table = $wpdb->prefix . $suffix;
		return preg_match( '/^[a-zA-Z0-9_]+$/D',$table ) && $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s',$wpdb->esc_like( $table ) ) ) === $table ? $table : '';
	}
	private function metric( string $key, string $label, $value, $previous = null, string $unit = '' ): array {
		return [ 'key'=>$key,'label'=>$label,'value'=>is_numeric( $value ) ? (float) $value : null,'previous'=>is_numeric( $previous ) ? (float) $previous : null,'unit'=>$unit ];
	}
	private function section( string $key, string $label, string $source, array $metrics, string $note ): array {
		return [ 'key'=>$key,'label'=>$label,'source'=>$source,'available'=>count( array_filter( $metrics, static fn( $m ) => null !== $m['value'] ) ) > 0,'metrics'=>$metrics,'note'=>$note ];
	}
	private function traffic( string $from, string $to, int $id ): ?array {
		if ( ! SecureTrack_Analytics_Adapter::records_pages() ) return null;
		$url = $id ? get_permalink($id) : '';
		if ($id && !$url) return null;
		return SecureTrack_Analytics_Adapter::period($from,$to,(string)$url);
	}
	private function coverage_note(?array $current,?array $previous): string {
		if (!$current || !$previous) return 'Reporting summaries are unavailable. Native SecureTrack setup must finish before these measurements can be shown.';
		$labels=['recorded'=>'recorded coverage','partial_history'=>'partial history','catching_up'=>'processing recorded activity','stale'=>'awaiting a background update','capacity'=>'reporting capacity reached','error'=>'background update failed','budget'=>'background update paused','engine'=>'storage maintenance required','timezone'=>'timezone maintenance required'];
		$describe=static function($row)use($labels){return $labels[$row['coverage']['status']]??'incomplete coverage';};
		return 'Current period: '.$describe($current).'. Previous period: '.$describe($previous).'. Updated '.($current['coverage']['updatedAt']??'not yet').' in the website timezone. Partial periods must not be treated as complete totals or compared as growth.';
	}
	private function events( string $from, string $to, int $product = 0 ): ?array {
		global $wpdb;
		if ( '3' !== get_option( 'dsa_store_analytics_db_version' ) || ! ( $table = $this->table( 'dsa_store_events' ) ) ) return null;
		$where = 'created_at >= %s AND created_at < %s'; $params = [ $from,$to ];
		if ( $product ) { $where .= ' AND product_id=%d'; $params[] = $product; }
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT event_type,COUNT(*) events,COUNT(DISTINCT NULLIF(visitor_hash,'')) devices FROM {$table} WHERE {$where} GROUP BY event_type LIMIT 60",$params ),ARRAY_A );
		if ( ! is_array( $rows ) || $wpdb->last_error ) return null;
		$out = []; foreach ( $rows as $row ) $out[ $row['event_type'] ] = [ 'events'=>(int) $row['events'],'devices'=>(int) $row['devices'] ];
		return $out;
	}
	private function sales( string $from, string $to, int $id = 0 ): ?array {
		$class = $id ? '\\Automattic\\WooCommerce\\Admin\\API\\Reports\\Products\\Stats\\DataStore' : '\\Automattic\\WooCommerce\\Admin\\API\\Reports\\Orders\\Stats\\DataStore';
		if ( ! \DSA\Apps\Store_App_Service::enabled() || ! class_exists( $class ) || ! $this->table( 'wc_order_stats' ) || ! $this->table( 'wc_order_product_lookup' ) || 'no' === get_option( 'woocommerce_analytics_enabled','yes' ) ) return null;
		// Woo owns report status exclusions, refunds, HPOS compatibility, currency and import/cache policy.
		$args = [ 'after'=>$from,'before'=>wp_date( 'Y-m-d H:i:s',( new \DateTimeImmutable( $to,wp_timezone() ) )->getTimestamp() - 1,wp_timezone() ),'interval'=>'day','per_page'=>100,'page'=>1,'orderby'=>'date','order'=>'asc' ];
		// This calls Woo's data store directly, after its REST-to-query argument mapping.
		if ( $id ) $args['product_includes'] = [ $id ];
		try { $data = ( new $class() )->get_data( $args ); } catch ( \Throwable $e ) { return null; }
		if ( is_wp_error( $data ) || ! is_object( $data ) || ! isset( $data->totals ) ) return null;
		$totals = (array) $data->totals;
		return [ 'orders'=>$totals['orders_count'] ?? null,'items'=>$totals[ $id ? 'items_sold' : 'num_items_sold' ] ?? null,'netRevenue'=>$totals['net_revenue'] ?? null ];
	}
	private function carts( string $from, string $to ): ?array {
		global $wpdb;
		if ( ! \DSA\Apps\Store_App_Service::enabled() || ! get_option( 'dsa_abandoned_cart_db_version' ) || ! ( $table = $this->table( 'dsa_abandoned_carts' ) ) ) return null;
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT COUNT(*) carts,SUM(status='abandoned') abandoned,SUM(recovered_at IS NOT NULL) recovered,SUM(status='converted') converted FROM {$table} WHERE created_at >= %s AND created_at < %s",$from,$to ),ARRAY_A );
		return is_array( $row ) && ! $wpdb->last_error ? array_map( 'intval',$row ) : null;
	}
	private function campaign_counts( int $from, int $to ): array {
		$history = get_option( 'dsa_notification_campaign_history',[] ); $active = get_option( 'dsa_notification_campaign_active',[] );
		$rows = is_array( $history ) ? array_slice( $history,0,20 ) : [];
		if ( is_array( $active ) && ! empty( $active['id'] ) ) $rows[] = $active;
		$out = [ 'campaigns'=>0,'sent'=>0,'failed'=>0,'unknown'=>0,'skipped'=>0 ]; $seen = [];
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) || isset( $seen[ $row['id'] ?? '' ] ) || (int) ( $row['created'] ?? 0 ) < $from || (int) ( $row['created'] ?? 0 ) >= $to ) continue;
			$seen[ $row['id'] ?? '' ] = true; $out['campaigns']++;
			foreach ( [ 'sent','failed','unknown','skipped' ] as $key ) $out[ $key ] += max( 0,(int) ( $row[ $key ] ?? 0 ) );
		}
		return $out;
	}
	/** Caller has already resolved the native scope and object permission. */
	public function report( string $scope, int $id, int $days = 30, string $search_type = 'web' ): array {
		$days = in_array( $days,[ 7,30,90 ],true ) ? $days : 30;
		$end = new \DateTimeImmutable( 'tomorrow',wp_timezone() ); $start = $end->modify( '-' . $days . ' days' ); $before = $start->modify( '-' . $days . ' days' );
		$from = $start->format( 'Y-m-d H:i:s' ); $to = $end->format( 'Y-m-d H:i:s' ); $prior = $before->format( 'Y-m-d H:i:s' );
		$sections = []; $site = Workspace::site_allowed(); $store = Workspace::store_allowed();
		if ( $id || ( $site && 'site' === $scope ) ) {
			$a = $this->traffic( $from,$to,$id ); $b = $this->traffic( $prior,$from,$id );
			$section = $this->section( 'traffic','Visits','SecureTrack',[ $this->metric( 'views','Recorded page views',$a['views'] ?? null,$b['views'] ?? null ),$this->metric( 'sessions','Recorded sessions',$a['sessions'] ?? null,$b['sessions'] ?? null ),$this->metric( 'engagedSeconds','Average active time',$a['engagedSeconds'] ?? null,$b['engagedSeconds'] ?? null,'seconds' ) ],'Recorded activity follows tracking and consent settings. Sessions are distinct across the whole period, not unique people. Active time averages only positively timed visits. '.$this->coverage_note($a,$b) );
			$section['coverage']=['current'=>$a['coverage']??null,'previous'=>$b['coverage']??null];
			if (empty($a['coverage']['complete']) || empty($b['coverage']['complete'])) foreach ($section['metrics'] as &$metric) $metric['previous']=null;
			unset($metric);
			$sections[]=$section;
		}
		if ( $store && in_array( $scope,[ 'site','store','product' ],true ) && ( 'product' !== $scope || $id ) ) {
			$product = 'product' === $scope ? $id : 0; $a = $this->sales( $from,$to,$product ); $b = $this->sales( $prior,$from,$product );
			$currency = function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : '';
			$sections[] = $this->section( 'sales','Sales','WooCommerce Analytics',[ $this->metric( 'orders','Orders',$a['orders'] ?? null,$b['orders'] ?? null ),$this->metric( 'items','Net items sold',$a['items'] ?? null,$b['items'] ?? null ),$this->metric( 'netRevenue','Net sales',$a['netRevenue'] ?? null,$b['netRevenue'] ?? null,$currency ) ],'Uses the native WooCommerce Analytics report, including its refund and excluded-status rules. Imported reporting data can lag the order ledger.' );
			if ( ! $product ) { $a = $this->carts( $from,$to ); $b = $this->carts( $prior,$from );
				$sections[] = $this->section( 'carts','Cart recovery','Kiwe cart lifecycle',array_map( fn( $key,$label ) => $this->metric( $key,$label,$a[ $key ] ?? null,$b[ $key ] ?? null ),[ 'carts','abandoned','recovered','converted' ],[ 'Captured carts','Currently abandoned','Recovered carts','Converted carts' ] ),'Carts created in this period, using their latest recorded state. Recovery is an attribution signal; it does not prove campaign causation.' );
			}
			$a = $this->events( $from,$to,$product ); $b = $this->events( $prior,$from,$product );
			$keys = $product ? [ 'cart_add' ] : [ 'cart_add','checkout_visit','purchase' ];
			$labels = $product ? [ 'Add-to-cart events' ] : [ 'Add-to-cart events','Checkout visits','Recorded purchase events' ];
			$sections[] = $this->section( 'funnel','Shopping activity','Kiwe recorded events',array_map( fn( $key,$label ) => $this->metric( $key,$label,null === $a ? null : ( $a[ $key ]['events'] ?? 0 ),null === $b ? null : ( $b[ $key ]['events'] ?? 0 ) ),$keys,$labels ),'Event counters are recorded interactions, separate from native order totals. Product context includes only events carrying that product ID.' );
		}
		if ( $site && in_array( $scope,[ 'site','pwa' ],true ) ) {
			$utc=static fn($local)=>(new \DateTimeImmutable($local,wp_timezone()))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
			$app=\DSA\PWA\App_Activity_Service::period($utc($from),$utc($to));$old=\DSA\PWA\App_Activity_Service::period($utc($prior),$utc($from));
			$sections[]=$this->section('app-observations','App use','Native app observations',[
				$this->metric('app-installs','First browser install reports',$app['installs']??null,$old['installs']??null),
				$this->metric('app-first-opens','First observed app openings',$app['first_opens']??null,$old['first_opens']??null),
				$this->metric('app-known-users','Known users whose latest app use falls in this period',$app['known_users']??null,null),
			],'Observations start when this feature is installed; no historical downloads are inferred. Install reports depend on the browser. Last use is sampled at most once every five minutes and is a latest-state snapshot, not a historical active-user total. Installing does not grant notification consent.');
			$a = $this->events( $from,$to ); $b = $this->events( $prior,$from );
			$sections[] = $this->section( 'pwa','App & notifications','Kiwe adoption events',array_map( fn( $key,$label ) => $this->metric( $key,$label,null === $a ? null : ( $a[ $key ]['devices'] ?? 0 ),null === $b ? null : ( $b[ $key ]['devices'] ?? 0 ) ),[ 'pwa_install_intent','pwa_installed','pwa_standalone','notification_granted','notification_denied' ],[ 'Devices requesting install','Devices with install event','Devices opening the app','Devices granting notifications','Devices declining notifications' ] ),'Privacy hashes estimate devices, not people or the current installed base. Browser events and opt-in delivery determine what can be recorded.' );
		}
		if ( $site && in_array( $scope,[ 'site','audience' ],true ) ) {
			global $wpdb; $a = $this->campaign_counts( $start->getTimestamp(),$end->getTimestamp() ); $b = $this->campaign_counts( $before->getTimestamp(),$start->getTimestamp() );
			$sections[] = $this->section( 'campaigns','Campaigns','Kiwe shared delivery history',array_map( fn( $key,$label ) => $this->metric( $key,$label,$a[ $key ],$b[ $key ] ),[ 'campaigns','sent','failed','unknown','skipped' ],[ 'Retained campaigns','Accepted delivery attempts','Failed attempts','Uncertain attempts','Skipped recipients' ] ),'Bounded to the latest 20 retained campaigns and the active job, grouped by campaign creation date. Accepted delivery is not an open, click or guarantee of receipt.' );
				$gmt = static fn( $local ) => ( new \DateTimeImmutable( $local,wp_timezone() ) )->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
				$n = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->users} WHERE user_registered >= %s AND user_registered < %s",$gmt( $from ),$gmt( $to ) ) ); $p = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->users} WHERE user_registered >= %s AND user_registered < %s",$gmt( $prior ),$gmt( $from ) ) );
			$sections[] = $this->section( 'users','People','WordPress',[ $this->metric( 'joined','Accounts joined',$n,$p ) ],'Native account registrations. An account is not automatically subscribed to marketing.' );
		}
		if ( $site && in_array( $scope,[ 'site','security' ],true ) ) {
			$a=SecureTrack_Analytics_Adapter::period($from,$to,'','security');$b=SecureTrack_Analytics_Adapter::period($prior,$from,'','security');
			$section=$this->section( 'security','Protection','SecureTrack',array_map( fn( $key,$label ) => $this->metric( $key,$label,$a[ $key ] ?? null,$b[ $key ] ?? null ),[ 'events','login_failures','protections' ],[ 'Recorded security events','Failed sign-ins','Recorded protections' ] ),'Recorded event totals survive raw-history cleanup within the reporting window. Detailed evidence and protection stay in SecureTrack. '.$this->coverage_note($a,$b) );
			$section['coverage']=['current'=>$a['coverage']??null,'previous'=>$b['coverage']??null];
			if (empty($a['coverage']['complete']) || empty($b['coverage']['complete'])) foreach($section['metrics'] as &$metric) $metric['previous']=null;
			unset($metric);$sections[]=$section;
		}
		$goal = self::goal();$search=['available'=>false,'note'=>'Search impressions, clicks and positions need a connected search-performance source. Local visits do not measure Google rankings.'];
		if(class_exists(Search_Performance_Service::class)&&($id||($site&&'site'===$scope))){
			$search=Search_Performance_Service::report($days,$id?(string)get_permalink($id):'',$search_type);
			foreach(['current'=>'Google Search Console','history'=>'Imported Rank Math history'] as $key=>$label){
				$a=$search[$key]??null;if(!$a||empty($a['available']))continue;$b=$key==='current'&& !empty($a['complete']) && !empty($search['previous']['complete']) ? $search['previous'] : null;
				$section=$this->section('search_'.$key,$key==='current'?'Search performance':'Search history',$label,array_map(fn($k,$l,$u)=>$this->metric($k,$l,$a['metrics'][$k]??null,$b['metrics'][$k]??null,$u),['clicks','impressions','ctr','position'],['Search clicks','Search impressions','Click-through rate','Average search position'],['','','%','']),$search['note']);
				$section['coverage']=['current'=>$a,'previous'=>$b];$sections[]=$section;
			}
		}
		return [ 'schema'=>'kiwe.analytics-report.v1','scope'=>$scope,'id'=>$id,'label'=>$id ? get_the_title( $id ) : ( 'site' === $scope ? 'Your website' : ucfirst( $scope ) ),'days'=>$days,'timezone'=>wp_timezone_string(),'from'=>$start->format( 'Y-m-d' ),'to'=>$end->modify( '-1 day' )->format( 'Y-m-d' ),'comparison'=>'Previous ' . $days . ' calendar days; the current period includes today','goal'=>$goal,'sections'=>$sections,'generatedAt'=>time(),'searchPerformance'=>$search ];
	}
}
