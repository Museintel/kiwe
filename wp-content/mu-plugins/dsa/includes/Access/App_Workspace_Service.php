<?php
namespace DSA\Access;

use DSA\Settings;
use DSA\Utilities\Origin_Checker;

if ( ! defined( 'ABSPATH' ) ) exit;

/** Client App editing home over the existing native Settings owner. */
final class App_Workspace_Service {

	public const PAGE = 'kiwe-app-settings';
	public const CAPABILITY = 'kiwe_manage_context';
	public static function settings_allowed(): bool { return current_user_can( self::CAPABILITY ); }
	public static function dock_allowed(): bool { return class_exists( Dock_Workspace_Service::class ) && Dock_Workspace_Service::allowed(); }
	public static function allowed(): bool { return self::settings_allowed() || self::dock_allowed(); }
	public static function audience_allowed(): bool { return self::settings_allowed() && current_user_can( 'list_users' ) && current_user_can( 'edit_users' ); }
	public static function enabled(): bool { return defined( 'KIWE_REACT_WORKSPACE' ) && true === KIWE_REACT_WORKSPACE; }
	public static function url( string $view = 'settings', string $tab = '' ): string { $args = [ 'page'=>self::PAGE, 'app_view'=>'activity' === $view ? 'activity' : 'settings' ]; if ( in_array( $tab, [ 'home','dock','appearance','copy','transitions','feedback','install','dashboard' ], true ) ) $args['app_tab'] = $tab; return add_query_arg( $args, admin_url( 'admin.php' ) ); }
	public function register(): void {
		add_action( 'admin_menu', [ $this, 'menu' ], 34 );
		if ( ! self::enabled() ) return;
		add_action( 'rest_api_init', [ $this, 'routes' ] );
		if ( is_admin() ) add_action( 'load-toplevel_page_' . self::PAGE, [ $this, 'document' ], 1 );
	}
	public function menu(): void {
		if ( self::allowed() ) add_menu_page( __( 'App', 'dsa' ), __( 'App', 'dsa' ), self::settings_allowed() ? self::CAPABILITY : Dock_Workspace_Service::CAPABILITY, self::PAGE, [ $this, 'fallback' ], 'dashicons-smartphone', 34 );
	}
	public function fallback(): void { echo '<div class="wrap"><h1>' . esc_html__( 'App', 'dsa' ) . '</h1><p>' . esc_html__( 'Open this task from the Kiwe dashboard.', 'dsa' ) . '</p></div>'; }
	public function routes(): void {
		register_rest_route( 'dsa/v1', '/workspace/app', [
			[ 'methods'=>'GET', 'callback'=>[ $this, 'read' ], 'permission_callback'=>[ $this, 'permission' ] ],
			[ 'methods'=>'POST', 'callback'=>[ $this, 'save' ], 'permission_callback'=>[ $this, 'permission' ] ],
		] );
		register_rest_route( 'dsa/v1', '/workspace/app/activity', [ 'methods'=>'GET', 'callback'=>[ $this, 'audience' ], 'permission_callback'=>[ $this, 'audience_permission' ] ] );
		register_rest_route( 'dsa/v1', '/workspace/app/dashboard', [ 'methods'=>'POST', 'callback'=>[ $this, 'save_dashboard' ], 'permission_callback'=>fn( $request ): bool => $this->permission( $request ) && Site_Owner_Service::can_manage_dashboard() ] );
	}
	public function save_dashboard( \WP_REST_Request $request ): \WP_REST_Response {
		if ( ! $this->permission( $request ) || ! Site_Owner_Service::can_manage_dashboard() ) return Task_Workspace_UI::failure( new \WP_Error( 'kiwe_dashboard_owner', __( 'Only the Super Admin can change dashboard access.', 'dsa' ) ), 403 );
		$body = $request->get_json_params(); $value = ( new Site_Owner_Service() )->update_dashboard( is_array( $body ) ? $body : [] );
		return is_wp_error( $value ) ? Task_Workspace_UI::failure( $value, (int) ( $value->get_error_data()['status'] ?? 400 ) ) : Task_Workspace_UI::response( $value );
	}
	public function permission( \WP_REST_Request $request ): bool {
		return self::enabled() && is_user_logged_in() && self::allowed()
			&& in_array( $request->get_method(), [ 'GET','POST' ], true )
			&& wp_verify_nonce( (string) $request->get_header( 'X-WP-Nonce' ), 'wp_rest' )
			&& Origin_Checker::is_same_site_request()
			&& ( 'POST' !== $request->get_method() || true === Origin_Checker::mutation_allowed( $request ) );
	}
	public function audience_permission( \WP_REST_Request $request ): bool { return 'GET' === $request->get_method() && $this->permission( $request ) && self::audience_allowed(); }

	/** Public choices only; never expose providers, credentials or the whole option row. */
	private static function fields(): array {
		return [
			'app'=>[ 'welcome_message'=>'text','pwa_pitch'=>'text','ios_url'=>'url','playstore_url'=>'url','android_url'=>'url','pc_url'=>'url','home_enabled'=>'bool','home_material'=>['glass','solid'],'idle_delay_ms'=>[10000,1800000],'show_install_actions'=>'bool' ],
			'visual_effects'=>[ 'initial_preloader_enabled'=>'bool','show_on_navigation'=>'bool','transition_material'=>['glass','solid'],'transition_content'=>['animation','messages'],'transition_title'=>'text','loader_type'=>['none','orb-chase','pulse'],'transition_message_mode'=>['random','fixed'],'transition_message_index'=>[0,19],'transition_title_position'=>['above','below'],'transition_messages'=>'messages' ],
			'haptic'=>[ 'enabled'=>'bool','vibration_enabled'=>'bool','sound_enabled'=>'bool','sound_profile'=>['soft','bright','pop','bell'],'context'=>['website','appsite','both'],'events'=>'events' ],
			'permissions'=>[ 'offline_editorial_enabled'=>'bool' ],
			'search'=>[ 'families'=>'search-families','custom_taxonomies'=>'taxonomies','context_aware'=>'bool','alphabet_enabled'=>'bool','result_limit'=>[1,12], ...(\DSA\Apps\Store_App_Service::enabled()?['product_add_enabled'=>'bool']:[]) ],
			'theme_screens'=>'screen-copy',
		];
	}
	private static function screen_copy_schema(): array {
		$schema=\DSA\Theme\Screen_Copy_Schema::screens();
		if(!\DSA\Apps\Store_App_Service::enabled()){
			unset($schema['cart'],$schema['checkout']);
			foreach(array_keys($schema['profile']['fields']??[]) as $key)if(preg_match('/^(orders|recentOrders|downloads|addresses|payment)/',$key))unset($schema['profile']['fields'][$key]);
			foreach(['wishlistLabel','summaryWishlistLabel'] as $key)unset($schema['saved']['fields'][$key]);
			foreach(['shopLabel','shopMeta','cartLabel','cartMeta'] as $key)unset($schema['links']['fields'][$key]);
		}
		return $schema;
	}
	private static function screen_copy_patch(array $values) {
		$schema=self::screen_copy_schema();if(count($values)>20||array_diff(array_keys($values),array_keys($schema)))return new \WP_Error('kiwe_app_copy','Choose an available DSA screen.');
		$current=(new Settings())->get('theme_screens',[]);
		$out=[];foreach($values as $screen=>$fields){if(!is_array($fields)||count($fields)>64||array_diff(array_keys($fields),array_keys($schema[$screen]['fields'])))return new \WP_Error('kiwe_app_copy','Choose a supported screen label.');foreach($fields as $key=>$value){if(!is_string($value)||strlen($value)>$schema[$screen]['fields'][$key]['max'])return new \WP_Error('kiwe_app_copy','A screen label is too long.');$value=sanitize_text_field($value);if($value!==(string)($current[$screen][$key]??''))$out[$screen][$key]=$value;}}
		return $out;
	}
	public function snapshot(): array {
		$all = ( new Settings() )->all(); $choices = [];
		if ( self::settings_allowed() ) foreach ( self::fields() as $group=>$fields ) {
			if('theme_screens'===$group){$choices[$group]=[];foreach(self::screen_copy_schema() as $screen=>$definition)foreach($definition['fields'] as $key=>$field)$choices[$group][$screen][$key]=(string)($all[$group][$screen][$key]??'');}
			else $choices[ $group ] = array_intersect_key( $all[ $group ] ?? [], $fields );
		}
		if(isset($choices['search']['families'])&&!\DSA\Apps\Store_App_Service::enabled())unset($choices['search']['families']['products']);
		$dock = self::dock_allowed() ? ( new Dock_Workspace_Service() )->snapshot() : null;
		if ( $dock ) $choices = array_replace( $choices, array_diff_key( $dock['choices'], [ 'enabled'=>true ] ) );
		$pwa = new \DSA\PWA\PWA_Service( new Settings() );
		return [ 'schema'=>'kiwe.app-workspace.v1','choices'=>$choices,'expected'=>hash( 'sha256', wp_json_encode( [ $choices, $dock['expected'] ?? '' ] ) ),'canActivity'=>self::audience_allowed(),'canSettings'=>self::settings_allowed(),'canDock'=>self::dock_allowed(),
			'dock'=>$dock ? array_intersect_key( $dock, array_flip( [ 'labels','themes','woo','phonekey','navigationUrl' ] ) ) : null,
			'health'=>[ 'siteIconReady'=>(bool) get_site_icon_url( 512 ),'installEnabled'=>$pwa->public_config()['enabled'],'https'=>is_ssl() ],
			'identityUrl'=>admin_url('admin.php?page=kiwe-identity'), 'activityUrl'=>self::url('activity'),'settingsUrl'=>self::url(),'siteUrl'=>home_url('/'),
			'screenCopySchema'=>self::settings_allowed()?self::screen_copy_schema():[], 'dashboardPolicy'=>Site_Owner_Service::dashboard_policy(),
			'searchTaxonomies'=>self::settings_allowed()?array_values(array_map(static fn($tax)=>['id'=>$tax->name,'label'=>$tax->label],array_filter(get_taxonomies(['public'=>true],'objects'),static fn($tax)=>\DSA\Apps\Store_App_Service::enabled()||!in_array('product',(array)$tax->object_type,true)))):[],
		];
	}
	private function failure( string $message, int $status = 400 ) { return new \WP_Error( 'kiwe_app_settings', $message, [ 'status'=>$status ] ); }
	public function update( array $body ) {
		if ( ! self::allowed() ) return $this->failure( 'App settings are unavailable for your account.', 403 );
		if ( array_diff( array_keys( $body ), [ 'expected','changes' ] ) || ! is_string( $body['expected'] ?? null ) ) return $this->failure( 'Choose an App setting to save.' );
		$snapshot = $this->snapshot();
		if ( ! hash_equals( $snapshot['expected'], $body['expected'] ) ) return $this->failure( 'App settings changed. Reload and review them before saving.', 409 );
		$changes = $body['changes'] ?? null;
		if ( ! is_array( $changes ) || ! $changes || count( $changes ) > 12 ) return $this->failure( 'Choose an App setting to save.' );
		$settings = new Settings(); $raw = get_option( DSA_OPTION_SETTINGS, null );
		if ( ! is_array( $raw ) || $this->snapshot()['expected'] !== $snapshot['expected'] ) return $this->failure( 'Website settings changed. Reload before saving.', 409 );
		$patch = []; $catalog = self::settings_allowed() ? self::fields() : [];
		$dock_groups = [ 'dock','style','dsa_theme','commerce' ];
		$dock_changes = array_intersect_key( $changes, array_flip( $dock_groups ) );
		if ( $dock_changes ) {
			if ( ! self::dock_allowed() ) return $this->failure( 'Dock settings are unavailable for your account.', 403 );
			$dock_service = new Dock_Workspace_Service();
			$patch = $dock_service->prepare_patch( $dock_changes, $settings->all(), $dock_service->snapshot() );
			if ( is_wp_error( $patch ) ) return $patch;
		}
		$changes = array_diff_key( $changes, array_flip( $dock_groups ) );
		foreach ( $changes as $group=>$values ) {
			if ( ! isset( $catalog[ $group ] ) ) return $this->failure( 'This App setting is unavailable.' );
			if('theme_screens'===$group){if(!is_array($values))return $this->failure('Choose screen labels to save.');$copy=self::screen_copy_patch($values);if(is_wp_error($copy))return $copy;if($copy)$patch[$group]=$copy;continue;}
			if ( 'bool' === $catalog[ $group ] ) { if ( ! is_bool( $values ) ) return $this->failure( 'Choose on or off.' ); $patch[$group]=$values; continue; }
			if ( ! is_array( $values ) || ! $values || count( $values ) > 16 ) return $this->failure( 'Choose valid App settings.' );
			foreach ( $values as $key=>$value ) {
				$type = $catalog[$group][$key] ?? null; $valid = false;
				if ( is_array( $type ) ) $valid = is_int($type[0]) ? is_int($value) && $value >= $type[0] && $value <= $type[1] : is_string($value) && in_array($value,$type,true);
				elseif ( 'bool' === $type ) $valid = is_bool($value);
				elseif ( 'text' === $type ) { $valid=is_string($value)&&strlen($value)<=640; if($valid)$value=sanitize_text_field($value); }
				elseif ( 'url' === $type ) { $parts=is_string($value)?wp_parse_url($value):false;$valid=is_string($value)&&strlen($value)<=1200&&(''===$value||(is_array($parts)&&!empty($parts['host'])&&!isset($parts['user'])&&!isset($parts['pass'])&&in_array(strtolower($parts['scheme']??''),['https','http'],true)));if($valid)$value=esc_url_raw($value); }
				elseif ( 'events' === $type ) { $valid=is_array($value)&&!array_diff(array_keys($value),['buttons','quantity','swipe_back','notifications']);if($valid)foreach($value as$on)if(!is_bool($on))$valid=false; }
				elseif('search-families'===$type){$allowed=['posts','authors',...(\DSA\Apps\Store_App_Service::enabled()?['products']:[])];$valid=is_array($value)&&!array_diff(array_keys($value),$allowed);if($valid)foreach($value as $on)if(!is_bool($on))$valid=false;}
				elseif('taxonomies'===$type){$valid=is_array($value)&&array_is_list($value)&&count($value)<=20&&count(array_unique($value,SORT_REGULAR))===count($value);if($valid)foreach($value as $taxonomy){$tax=is_string($taxonomy)?get_taxonomy($taxonomy):null;if(!$tax||empty($tax->public)||(!\DSA\Apps\Store_App_Service::enabled()&&in_array('product',(array)$tax->object_type,true)))$valid=false;}}
				elseif ( 'messages' === $type ) {
					$valid=is_array($value)&&array_is_list($value)&&count($value)<=20;
					if($valid)foreach($value as&$entry){if(!is_array($entry)||array_diff(array_keys($entry),['title','message'])||!is_string($entry['title']??null)||!is_string($entry['message']??null)||strlen($entry['title'])>160||strlen($entry['message'])>1600){$valid=false;break;}$entry=['title'=>sanitize_text_field($entry['title']),'message'=>sanitize_textarea_field($entry['message'])];}unset($entry);
				}
				if ( 'app' === $group && 'idle_delay_ms' === $key && $valid ) $valid = 0 === $value % 1000;
				if ( ! $valid ) return $this->failure( 'An App choice is invalid. Review it before saving.' );
				$patch[$group][$key]=$value;
			}
		}
		// Nested feedback choices are patches, never an instruction to erase other events.
		if ( isset($patch['haptic']['events']) ) $patch['haptic']['events']=array_replace($snapshot['choices']['haptic']['events'],$patch['haptic']['events']);
		if(isset($patch['search'])){$patch['search']['configuration_version']=2;$patch['search']['bricks_bridge_enabled']=true;}
		if ( isset( $patch['visual_effects']['show_on_navigation'] ) && ! isset( $patch['visual_effects']['transition_content'] ) ) $patch['visual_effects']['transition_content'] = $snapshot['choices']['visual_effects']['transition_content'];
		if(!$patch)return $this->snapshot();
		if ( ! $settings->compare_and_update_selected( $raw,$patch ) ) return $this->failure( 'Website settings changed during saving. Reload and review.',409 );
		if(isset($patch['search']))\DSA\Search\Search_Service::invalidate_settings();
		// Home markup and install visibility are server-rendered public presentation.
		do_action( 'litespeed_purge_all' );
		return $this->snapshot();
	}
	public function read( \WP_REST_Request $request ): \WP_REST_Response { return $this->permission($request) ? Task_Workspace_UI::response($this->snapshot()) : Task_Workspace_UI::failure($this->failure('App settings are unavailable.',403),403); }
	public function save( \WP_REST_Request $request ): \WP_REST_Response {
		if(!$this->permission($request))return Task_Workspace_UI::failure($this->failure('App settings are unavailable.',403),403);
		$body=$request->get_json_params();$result=$this->update(is_array($body)?$body:[]);
		return is_wp_error($result)?Task_Workspace_UI::failure($result,(int)($result->get_error_data()['status']??400)):Task_Workspace_UI::response($result);
	}
	public function audience( \WP_REST_Request $request ): \WP_REST_Response {
		if(!$this->audience_permission($request))return Task_Workspace_UI::failure($this->failure('App activity is unavailable.',403),403);
		return Task_Workspace_UI::response( \DSA\PWA\App_Activity_Service::listing($request->get_query_params()) );
	}
	public function document(): void {
		if(!self::enabled()||!is_admin()||is_network_admin()||is_user_admin()||wp_doing_ajax()||wp_doing_cron()||!is_user_logged_in()||!self::allowed()||!in_array($_SERVER['REQUEST_METHOD']??'',['GET','HEAD'],true))return;
		if('HEAD'===$_SERVER['REQUEST_METHOD']){nocache_headers();header('X-Robots-Tag: noindex, nofollow',true);exit;}
		Task_Workspace_UI::app_document('app','App',['endpoint'=>rest_url('dsa/v1/workspace/app'),'initialView'=>'activity'===($_GET['app_view']??'')&&self::audience_allowed()?'activity':'settings','initialTab'=>in_array($_GET['app_tab']??'',['home','dock','appearance','copy','transitions','feedback','install','dashboard'],true)&&('dashboard'!==$_GET['app_tab']||Site_Owner_Service::can_manage_dashboard())?$_GET['app_tab']:'home']);
	}
}
