<?php
namespace DSA\Access;

use DSA\Settings;
use DSA\Secure\SecureTrack_Db_Service;
use DSA\Secure\SecureTrack_Report_Service;
use DSA\Utilities\Origin_Checker;

if ( ! defined( 'ABSPATH' ) ) exit;

/** Guided initialization of the existing native owners, never a reporting-page side effect. */
final class Analytics_Reporting_Setup_Service {
	private Settings $settings;
	private \DSA\Commerce\Abandoned_Cart_Service|\Closure $carts;
	private static bool $preparing = false;
	public function __construct( Settings $settings, \DSA\Commerce\Abandoned_Cart_Service|\Closure $carts ) { $this->settings=$settings; $this->carts=$carts; }
	public static function maintenance_authorized(): bool { return self::$preparing && self::can_prepare(); }
	public static function can_prepare(): bool { return Analytics_Workspace_Service::allowed() && current_user_can( 'kiwe_manage_context' ) && ! Team_Workspace_Service::suspended( get_current_user_id() ) && ( ! is_multisite() || is_user_member_of_blog( get_current_user_id() ) ); }
	public static function projection(): array {
		$settings=new Settings(); $secure=$settings->get('secure',[]); $config=SecureTrack_Report_Service::runtime_config();
		$woo=\DSA\Apps\Store_App_Service::enabled(); $ready=SecureTrack_Report_Service::ready();
		$out=[ 'canPrepare'=>self::can_prepare(), 'secureEnabled'=>!empty($secure['enabled']), 'secureReady'=>$ready,
			'recordingVisits'=>!empty($config['track_visitors'])&&!empty($config['track_pages']), 'woo'=>$woo,
			'cartsReady'=>$woo&&'4'===get_option('dsa_abandoned_cart_db_version',''),
			'capturingCarts'=>$woo&&'4'===get_option('dsa_abandoned_cart_db_version','')&&!\DSA\Commerce\Store_Recovery_Gate_Service::is_paused(),
			'restoredBlocked'=>\DSA\Analytics\Restored_Reporting_State::blocks('secure')||($woo&&\DSA\Commerce\Store_Recovery_Gate_Service::is_paused()),
			'endpoint'=>self::can_prepare()?rest_url('dsa/v1/workspace/analytics/setup'):'',
		];
		$out['needed']=!$out['secureEnabled']||!$ready||($woo&&(!$out['cartsReady']||!$out['capturingCarts']));
		$out['expected']=hash('sha256',wp_json_encode([$out,get_option(DSA_OPTION_SETTINGS,[]),get_option('stp_settings',[]),get_option('stp_db_version',''),get_option('stp_report_schema','')]));
		return $out;
	}
	public function register(): void { add_action('rest_api_init',[$this,'routes']); }
	public function routes(): void { register_rest_route('dsa/v1','/workspace/analytics/setup',['methods'=>'POST','callback'=>[$this,'prepare'],'permission_callback'=>[$this,'permission']]); }
	public function permission( \WP_REST_Request $request ): bool {
		return 'POST'===$request->get_method()&&self::can_prepare()&&wp_verify_nonce((string)$request->get_header('X-WP-Nonce'),'wp_rest')&&true===Origin_Checker::mutation_allowed($request);
	}
	public function prepare( \WP_REST_Request $request ): \WP_REST_Response {
		if(!$this->permission($request))return Task_Workspace_UI::failure(new \WP_Error('kiwe_reporting_denied','Only the website owner can prepare reporting.'),403);
		$b=$request->get_json_params();$current=self::projection();
		if(!is_array($b)||true!==($b['confirmed']??null)||!is_string($b['expected']??null)||!hash_equals($current['expected'],$b['expected'])||!is_bool($b['recordVisits']??null))return Task_Workspace_UI::failure(new \WP_Error('kiwe_reporting_changed','Refresh and review the current reporting setup.'),409);
		if($current['restoredBlocked'])return Task_Workspace_UI::failure(new \WP_Error('kiwe_reporting_restore','Review the restored reporting and cart connection in Backups before starting recording.'),409);
		if(SecureTrack_Report_Service::runtime_loaded()&&!defined('DSA_OWNS_SECURETRACK_ADMIN'))return Task_Workspace_UI::failure(new \WP_Error('kiwe_reporting_collision','Another SecureTrack installation owns this setup. Resolve that installation before continuing.'),409);
		$before=get_option(DSA_OPTION_SETTINGS,[]);
		try {
			 $choices=[];if(!$current['secureEnabled'])$choices['secure']=['enabled'=>true,'protection_profile'=>'monitor'];
			if($choices&&!$this->settings->compare_and_update_selected($before,$choices))throw new \RuntimeException('The monitoring choice could not be confirmed. Refresh before retrying.');
			if(!SecureTrack_Report_Service::runtime_loaded())(new \DSA\Secure\SecureTrack_Loader())->boot();
			if(!SecureTrack_Report_Service::runtime_loaded())throw new \RuntimeException('SecureTrack could not start on this server.');
			self::$preparing=true;
			if(!SecureTrack_Report_Service::ready()&&(!SecureTrack_Db_Service::repair_database()||!SecureTrack_Report_Service::ready()))throw new \RuntimeException('Native reporting preparation did not finish. Refresh its status before retrying.');
			if($b['recordVisits']&&!SecureTrack_Report_Service::start_visit_recording())throw new \RuntimeException('Visit recording could not be confirmed.');
			if(\DSA\Apps\Store_App_Service::enabled()) { if($this->carts instanceof \Closure)$this->carts=($this->carts)(); $this->carts->maybe_install(); if(!$this->carts->ready()||!$this->carts->configure_runtime())throw new \RuntimeException('Cart recording could not be prepared. Refresh and review the cart setup.'); }
			return Task_Workspace_UI::response(['prepared'=>true,'reportingSetup'=>self::projection()]);
		} catch(\Throwable $error) {
			return Task_Workspace_UI::failure(new \WP_Error('kiwe_reporting_prepare',$error->getMessage()),409);
		} finally { self::$preparing=false; }
	}
}
