<?php

namespace DSA\Access;

use DSA\AI\AI_Allowance_Service;
use DSA\AI\AI_Feature_Policy as Policy;
use DSA\AI\AI_Provider_Service;
use DSA\Settings;
use DSA\Utilities\Origin_Checker;

if ( ! defined( 'ABSPATH' ) ) exit;

/** The single client editing home for site AI preferences. Opening it never invokes AI. */
final class AI_Workspace_Service {
	public const PAGE = 'kiwe-ai-features';
	public const ACTION = 'kiwe_ai_preference';
	private const PROVIDER_CHECK_OPTION = 'kiwe_ai_provider_check_v1';
	private string $screen = '';

	public function register(): void {
		add_action( 'admin_menu', [ $this, 'menu' ], 34 );
		add_action( 'admin_enqueue_scripts', [ $this, 'assets' ] );
		add_action( 'admin_post_' . self::ACTION, [ $this, 'handle' ] );
		if ( $this->app_enabled() ) {
			add_action( 'rest_api_init', [ $this, 'routes' ] );
			if ( is_admin() ) add_action( 'load-toplevel_page_' . self::PAGE, [ $this, 'document' ], 1 );
		}
	}
	private function app_enabled(): bool { return defined( 'KIWE_REACT_WORKSPACE' ) && true === KIWE_REACT_WORKSPACE; }
	public function routes(): void {
		if ( ! $this->app_enabled() ) return;
		register_rest_route( 'dsa/v1', '/workspace/ai', [ 'methods'=>'GET', 'callback'=>[ $this, 'read_app' ], 'permission_callback'=>[ $this, 'app_permission' ] ] );
		register_rest_route( 'dsa/v1', '/workspace/ai/preference', [ 'methods'=>'POST', 'callback'=>[ $this, 'save_app' ], 'permission_callback'=>[ $this, 'app_permission' ] ] );
		register_rest_route( 'dsa/v1', '/workspace/ai/owner', [ 'methods'=>'POST', 'callback'=>[ $this, 'save_owner_app' ], 'permission_callback'=>[ $this, 'app_permission' ] ] );
		register_rest_route( 'dsa/v1', '/workspace/ai/provider-check', [ 'methods'=>'POST', 'callback'=>[ $this, 'check_provider_app' ], 'permission_callback'=>[ $this, 'app_permission' ] ] );
	}
	public function app_permission( \WP_REST_Request $request ): bool {
		if ( ! $this->app_enabled() || ! is_user_logged_in() || ! current_user_can( Policy::CAPABILITY ) || ! in_array( $request->get_method(), [ 'GET', 'POST' ], true ) ) return false;
		if ( ! wp_verify_nonce( (string) $request->get_header( 'X-WP-Nonce' ), 'wp_rest' ) || ! Origin_Checker::is_same_site_request() ) return false;
		return 'POST' !== $request->get_method() || true === Origin_Checker::mutation_allowed( $request );
	}
	private function app_snapshot(): array {
		$settings = new Settings();
		$ai = $settings->get( 'ai', [] );
		$ai = is_array( $ai ) ? $ai : [];
		return [
			'schema'=>'kiwe.ai-workspace.v1',
			'globalPaused'=>empty( $ai['allow_native_generation'] ),
			'choices'=>Policy::choices( $ai ),
			'siteChoices'=>Policy::site_choices(),
			'siteExpected'=>hash( 'sha256',wp_json_encode( Policy::site_choices() ) ),
			'goal'=>\DSA\Analytics\Analytics_Report_Service::goal(),
			'goalUrl'=>current_user_can( 'kiwe_manage_context' ) ? \DSA\Onboarding\SEO_Context_Service::goal_url() : '',
			'allowance'=>( new AI_Allowance_Service( $settings ) )->status(),
			'ownerSetupUrl'=>current_user_can( 'manage_options' ) ? admin_url( 'admin.php?page=kiwe-ai' ) : '',
			'owner'=>current_user_can( 'manage_options' ) ? $this->owner_snapshot( $ai, $settings ) : null,
		];
	}
	private function owner_snapshot( array $ai, Settings $settings ): array {
		$allowance = new AI_Allowance_Service( $settings );
		$provider = ( new AI_Provider_Service( $settings ) )->status();
		$controls = [
			'allowNativeGeneration'=>! empty( $ai['allow_native_generation'] ),
			'seoAllowed'=>! empty( $ai['seo_enabled'] ),
			'securetrackAllowed'=>! empty( $ai['securetrack_brief_enabled'] ),
			'requestLimits'=>$allowance->limits(),
			'tokenLimits'=>$allowance->token_limits(),
		];
		$last_check = get_option( self::PROVIDER_CHECK_OPTION, [] );
		if ( ! is_array( $last_check ) || 'kiwe.ai-provider-check.v1' !== ( $last_check['schema'] ?? '' ) ) $last_check = null;
		$key_stored = '' !== (string) ( $ai['native_api_key'] ?? '' );
		$provider_snapshot = [
			'id'=>(string) ( $provider['provider'] ?? 'none' ), 'model'=>(string) ( $provider['model'] ?? '' ),
			'enabled'=>! empty( $provider['enabled'] ), 'configured'=>! empty( $provider['configured'] ),
			'keyStatus'=>sanitize_key( (string) ( $provider['keyStatus']['status'] ?? 'empty' ) ),
		];
		$fingerprint = $controls + [ 'keyStored'=>$key_stored, 'provider'=>$provider_snapshot ];
		return $controls + [
			'keyStored'=>$key_stored,
			'provider'=>$provider_snapshot + [ 'lastCheck'=>$last_check ],
			'expected'=>hash( 'sha256', wp_json_encode( $fingerprint ) ),
		];
	}
	public function read_app( \WP_REST_Request $request ): \WP_REST_Response {
		if ( ! $this->app_permission( $request ) ) return Task_Workspace_UI::failure( new \WP_Error( 'kiwe_ai_forbidden', __( 'AI assistance is unavailable for your account.', 'dsa' ) ), 403 );
		return Task_Workspace_UI::response( $this->app_snapshot() );
	}
	public function save_app( \WP_REST_Request $request ): \WP_REST_Response {
		if ( ! $this->app_permission( $request ) ) return Task_Workspace_UI::failure( new \WP_Error( 'kiwe_ai_forbidden', __( 'AI assistance is unavailable for your account.', 'dsa' ) ), 403 );
		$body = $request->get_json_params();
		$feature = is_array( $body ) ? ( $body['feature'] ?? null ) : null;
		$enabled = is_array( $body ) ? ( $body['enabled'] ?? null ) : null;
		if ( in_array( $feature,[ 'site_seo','site_ai' ],true ) && is_bool( $enabled ) && is_string( $body['expected'] ?? null ) ) {
			$result = ( new Policy() )->set_site_choice( substr( $feature,5 ),$enabled,$body['expected'] );
			if ( is_wp_error( $result ) ) return Task_Workspace_UI::failure( $result,409 );
			return Task_Workspace_UI::response( $this->app_snapshot() );
		}
		if ( ! is_string( $feature ) || ! Policy::known( $feature ) || ! is_bool( $enabled ) ) return Task_Workspace_UI::failure( new \WP_Error( 'kiwe_ai_preference_value', __( 'Choose a valid AI preference.', 'dsa' ) ), 400 );
		$result = ( new Policy() )->set_preference( $feature, $enabled );
		if ( is_wp_error( $result ) ) return Task_Workspace_UI::failure( $result, 403 );
		return Task_Workspace_UI::response( $this->app_snapshot() );
	}
	public function save_owner_app( \WP_REST_Request $request ): \WP_REST_Response {
		if ( ! $this->app_permission( $request ) || ! current_user_can( 'manage_options' ) ) return Task_Workspace_UI::failure( new \WP_Error( 'kiwe_ai_owner_forbidden', __( 'Website AI setup is unavailable for your account.', 'dsa' ) ), 403 );
		$body = $request->get_json_params();
		$key = is_array( $body ) ? ( $body['key'] ?? null ) : null;
		$value = is_array( $body ) ? ( $body['value'] ?? null ) : null;
		$expected = is_array( $body ) ? ( $body['expected'] ?? null ) : null;
		if ( ! is_string( $key ) || ! in_array( $key, [ 'allowNativeGeneration', 'seoAllowed', 'securetrackAllowed', 'requestLimits', 'tokenLimits' ], true ) || ! is_string( $expected ) || ! preg_match( '/^[a-f0-9]{64}$/D', $expected ) ) return Task_Workspace_UI::failure( new \WP_Error( 'kiwe_ai_owner_value', __( 'Choose a valid website AI control.', 'dsa' ) ), 400 );
		if ( in_array( $key, [ 'requestLimits', 'tokenLimits' ], true ) ) {
			if ( ! is_array( $value ) || array_keys( $value ) !== [ 'minute', 'day', 'month' ] || count( array_filter( $value, static fn( $n ) => is_int( $n ) && $n >= 0 && $n <= 1000000000 ) ) !== 3 ) return Task_Workspace_UI::failure( new \WP_Error( 'kiwe_ai_owner_value', __( 'Enter whole-number allowance limits.', 'dsa' ) ), 400 );
		} elseif ( ! is_bool( $value ) ) return Task_Workspace_UI::failure( new \WP_Error( 'kiwe_ai_owner_value', __( 'Choose on or off.', 'dsa' ) ), 400 );
		$raw = get_option( DSA_OPTION_SETTINGS, null );
		if ( ! is_array( $raw ) ) return Task_Workspace_UI::failure( new \WP_Error( 'kiwe_ai_owner_settings', __( 'Website settings are unavailable. Refresh and review.', 'dsa' ) ), 409 );
		$ai = is_array( $raw['ai'] ?? null ) ? $raw['ai'] : [];
		$current = $this->owner_snapshot( $ai, new Settings() );
		if ( ! hash_equals( $current['expected'], $expected ) ) return Task_Workspace_UI::failure( new \WP_Error( 'kiwe_ai_owner_changed', __( 'Website AI settings changed. Refresh before saving.', 'dsa' ) ), 409 );
		switch ( $key ) {
			case 'allowNativeGeneration': $ai['allow_native_generation'] = $value; break;
			case 'seoAllowed': $ai['seo_enabled'] = $value; break;
			case 'securetrackAllowed': $ai['securetrack_brief_enabled'] = $value; break;
			case 'requestLimits': $ai['request_allowance'] = AI_Allowance_Service::sanitize_limits( $value ); break;
			case 'tokenLimits': $ai['token_allowance'] = AI_Allowance_Service::sanitize_token_limits( $value ); break;
		}
		$raw['ai'] = $ai;
		update_option( DSA_OPTION_SETTINGS, $raw, false );
		$stored = get_option( DSA_OPTION_SETTINGS, null );
		if ( ! is_array( $stored ) || ( $stored['ai'] ?? null ) !== $ai ) return Task_Workspace_UI::failure( new \WP_Error( 'kiwe_ai_owner_save', __( 'The setting could not be confirmed. Refresh and review.', 'dsa' ) ), 409 );
		return Task_Workspace_UI::response( $this->app_snapshot() );
	}

	public function check_provider_app( \WP_REST_Request $request ): \WP_REST_Response {
		if ( ! $this->app_permission( $request ) || ! current_user_can( 'manage_options' ) ) return Task_Workspace_UI::failure( new \WP_Error( 'kiwe_ai_owner_forbidden', __( 'Website AI setup is unavailable for your account.', 'dsa' ) ), 403 );
		$body = $request->get_json_params();
		$expected = is_array( $body ) && is_string( $body['expected'] ?? null ) ? (string) $body['expected'] : '';
		if ( ! is_array( $body ) || true !== ( $body['confirmed'] ?? null ) || ! preg_match( '/^[a-f0-9]{64}$/D', $expected ) ) return Task_Workspace_UI::failure( new \WP_Error( 'kiwe_ai_provider_check', __( 'Confirm the single provider check before continuing.', 'dsa' ) ), 400 );
		$settings = new Settings(); $ai = $settings->get( 'ai', [] ); $ai = is_array( $ai ) ? $ai : [];
		$current = $this->owner_snapshot( $ai, $settings );
		if ( ! hash_equals( $current['expected'], $expected ) ) return Task_Workspace_UI::failure( new \WP_Error( 'kiwe_ai_owner_changed', __( 'Website AI settings changed. Refresh before testing the provider.', 'dsa' ) ), 409 );
		$result = ( new AI_Provider_Service( $settings ) )->generate( [
			'system'=>'Return only JSON matching the requested schema. This is a one-request Kiwe provider readiness check.',
			'user'=>'Return {"ready":true}. Do not include any other text.',
			'responseMimeType'=>'application/json',
			'responseSchema'=>[ 'type'=>'object', 'properties'=>[ 'ready'=>[ 'type'=>'boolean' ] ], 'required'=>[ 'ready' ], 'additionalProperties'=>false ],
			'thinkingLevel'=>'low',
		] );
		$decoded = ! empty( $result['ok'] ) && ! empty( $result['called'] ) ? json_decode( (string) ( $result['output'] ?? '' ), true ) : null;
		$passed = is_array( $decoded ) && true === ( $decoded['ready'] ?? null );
		$check = [
			'schema'=>'kiwe.ai-provider-check.v1', 'status'=>$passed ? 'passed' : ( ! empty( $result['called'] ) ? 'failed' : 'not_called' ),
			'provider'=>sanitize_key( (string) ( $result['provider'] ?? 'none' ) ), 'model'=>sanitize_text_field( (string) ( $result['model'] ?? '' ) ),
			'called'=>! empty( $result['called'] ), 'attempts'=>absint( $result['responseMeta']['attempts'] ?? 0 ),
			'reason'=>sanitize_key( (string) ( $result['reason'] ?? $result['error']['code'] ?? ( $passed ? '' : 'invalid_provider_response' ) ) ),
			'checkedAt'=>time(),
		];
		update_option( self::PROVIDER_CHECK_OPTION, $check, false );
		if ( get_option( self::PROVIDER_CHECK_OPTION, [] ) !== $check ) return Task_Workspace_UI::failure( new \WP_Error( 'kiwe_ai_provider_check_store', __( 'The provider result could not be confirmed. Refresh and review the shared allowance.', 'dsa' ) ), 409 );
		return Task_Workspace_UI::response( $this->app_snapshot() );
	}
	public function document(): void {
		if ( ! $this->app_enabled() || ! is_admin() || is_network_admin() || is_user_admin() || wp_doing_ajax() || wp_doing_cron() || ! current_user_can( Policy::CAPABILITY ) || ! in_array( $_SERVER['REQUEST_METHOD'] ?? '', [ 'GET', 'HEAD' ], true ) ) return;
		nocache_headers(); header( 'Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0', true ); header( 'X-Robots-Tag: noindex, nofollow', true ); header( 'X-Content-Type-Options: nosniff', true );
		if ( 'HEAD' === $_SERVER['REQUEST_METHOD'] ) exit;
		wp_enqueue_style( 'kiwe-ai-app', DSA_URL . 'assets/css/ai-app.css', \DSA\Access\Workspace_Design_Service::app_dependencies( [] ), DSA_VERSION );
		wp_enqueue_script( 'kiwe-ai-app', DSA_URL . 'assets/js/ai-app.js', [ 'wp-element' ], DSA_VERSION, true );
		wp_localize_script( 'kiwe-ai-app', 'KIWE_AI_APP', [ 'endpoint'=>rest_url( 'dsa/v1/workspace/ai' ), 'nonce'=>wp_create_nonce( 'wp_rest' ), 'home'=>Workspace_Admin_Service::url(), ...Workspace_Chrome_Service::projection() ] );
		?><!doctype html><html <?php language_attributes(); ?>><head><meta charset="<?php bloginfo( 'charset' ); ?>"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?php esc_html_e( 'AI assistance · Kiwe', 'dsa' ); ?></title><?php wp_print_styles( [ 'kiwe-ai-app' ] ); ?></head><body class="kiwe-ai-app"><div id="kiwe-ai-app"><p><?php esc_html_e( 'Opening AI assistance…', 'dsa' ); ?></p><noscript><?php esc_html_e( 'This workspace needs JavaScript. Switch off the experiment to restore the existing AI choices.', 'dsa' ); ?></noscript></div><?php wp_print_scripts( [ 'wp-element', 'kiwe-ai-app' ] ); ?></body></html><?php exit;
	}
	public static function url(): string { return admin_url( 'admin.php?page=' . self::PAGE ); }
	public function menu(): void {
		if ( ! current_user_can( Policy::CAPABILITY ) ) return;
		$this->screen = (string) add_menu_page( __( 'AI assistance', 'dsa' ), __( 'AI assistance', 'dsa' ), Policy::CAPABILITY, self::PAGE, [ $this, 'render' ], 'dashicons-lightbulb', 34 );
	}
	public function assets( string $hook = '' ): void {
		if ( $this->screen && $hook === $this->screen && current_user_can( Policy::CAPABILITY ) ) wp_enqueue_style( 'kiwe-task-workspaces', DSA_URL . 'assets/css/task-workspaces.css', [], DSA_VERSION );
	}
	public function handle(): void {
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || ! current_user_can( Policy::CAPABILITY ) ) wp_die( esc_html__( 'AI choice unavailable.', 'dsa' ), '', [ 'response'=>403 ] );
		$feature = $_POST['feature'] ?? null;
		$value = $_POST['enabled'] ?? null;
		if ( ! is_string( $feature ) || ! Policy::known( $feature ) || ! in_array( $value, [ '0','1' ], true ) ) { Task_Workspace_UI::error( new \WP_Error( 'kiwe_ai_preference_value', __( 'Refresh and choose an AI action.', 'dsa' ) ) ); return; }
		check_admin_referer( self::ACTION . '_' . $feature );
		$result = ( new Policy() )->set_preference( $feature, '1' === $value );
		if ( is_wp_error( $result ) ) { Task_Workspace_UI::error( $result ); return; }
		Task_Workspace_UI::finish( self::url(), __( 'Your AI preference has been saved.', 'dsa' ) );
	}
	public function render(): void {
		if ( ! current_user_can( Policy::CAPABILITY ) ) wp_die( esc_html__( 'AI assistance unavailable.', 'dsa' ), '', [ 'response'=>403 ] );
		$settings = new Settings();
		$ai = $settings->get( 'ai', [] );
		$choices = Policy::choices( is_array( $ai ) ? $ai : [] );
		$allowance = ( new AI_Allowance_Service( $settings ) )->status();
		echo '<div class="wrap kiwe-tasks"><p class="kiwe-tasks__eyebrow">' . esc_html__( 'Your website', 'dsa' ) . '</p><h1>' . esc_html__( 'AI assistance', 'dsa' ) . '</h1><p>' . esc_html__( 'Choose where AI may help. Your website manager controls availability and the shared request allowance. Your choices apply to this website.', 'dsa' ) . '</p>';
		Task_Workspace_UI::receipt();
		if ( empty( $ai['allow_native_generation'] ) ) echo '<p class="kiwe-tasks__status">' . esc_html__( 'AI requests are paused in website setup. You can still save your preferences.', 'dsa' ) . '</p>';
		echo '<div class="kiwe-tasks__grid">';
		foreach ( [
			'seo'=>[ __( 'SEO assistance', 'dsa' ), __( 'Allow AI suggestions for public content in SEO reviews. Turning this off keeps existing SEO data and ordinary SEO features working.', 'dsa' ) ],
			'securetrack'=>[ __( 'SecureTrack assistance', 'dsa' ), __( 'Allow AI to review privacy-filtered security signals when SecureTrack requests a review. Turning this off keeps ordinary security protections working.', 'dsa' ) ],
		] as $feature=>$text ) {
			$choice = $choices[ $feature ];
			$state = ! $choice['ownerAllowed'] ? __( 'Unavailable in website setup', 'dsa' ) : ( $choice['clientEnabled'] ? __( 'Your preference: on', 'dsa' ) : __( 'Your preference: off', 'dsa' ) );
			echo '<section class="kiwe-tasks__card"><h2>' . esc_html( $text[0] ) . '</h2><p>' . esc_html( $text[1] ) . '</p><p class="kiwe-tasks__status">' . esc_html( $state ) . '</p>';
			if ( ! $choice['ownerAllowed'] ) echo '<p>' . esc_html__( 'Your website manager needs to allow this feature. A saved on preference will apply again if permission is restored; you can switch it off below.', 'dsa' ) . '</p>';
			if ( $choice['ownerAllowed'] || $choice['clientEnabled'] ) {
				Task_Workspace_UI::form( self::ACTION, self::ACTION . '_' . $feature, [ 'feature'=>$feature, 'enabled'=>$choice['clientEnabled'] ? '0' : '1' ] );
				echo '<button class="button ' . ( $choice['clientEnabled'] ? '' : 'button-primary' ) . '">' . esc_html( sprintf( $choice['clientEnabled'] ? __( 'Turn off %s', 'dsa' ) : __( 'Turn on %s', 'dsa' ), $text[0] ) ) . '</button></form>';
			}
			echo '</section>';
		}
		echo '</div><section class="kiwe-tasks__card"><h2>' . esc_html__( 'Shared AI allowance', 'dsa' ) . '</h2><p>' . esc_html__( 'All website AI features share these request limits. A request can include a retry. These counts are not a bill or a money limit. Switching a feature on does not start a review or change an existing review schedule.', 'dsa' ) . '</p>';
		if ( empty( $allowance['usageAvailable'] ) ) echo '<p class="kiwe-tasks__status">' . esc_html__( 'Usage is temporarily unavailable. AI requests remain paused until it can be checked.', 'dsa' ) . '</p>';
		else {
			echo '<ul class="kiwe-tasks__list">';
			foreach ( [ 'minute'=>__( 'This minute', 'dsa' ), 'day'=>__( 'Today (UTC)', 'dsa' ), 'month'=>__( 'This month (UTC)', 'dsa' ) ] as $window=>$label ) echo '<li>' . esc_html( sprintf( __( '%1$s: %2$d of %3$d requests used; %4$d remaining.', 'dsa' ), $label, $allowance['used'][ $window ], $allowance['limits'][ $window ], $allowance['remaining'][ $window ] ) ) . '</li>';
			echo '</ul>';
			if ( ! empty( $allowance['retryAt'] ) ) echo '<p class="kiwe-tasks__status">' . esc_html( sprintf( __( 'AI allowance pause until %s UTC. Reviews resume according to their own controls.', 'dsa' ), gmdate( 'Y-m-d H:i', $allowance['retryAt'] ) ) ) . '</p>';
			$tokens = $allowance['tokenBudget'];
			echo '<h3>' . esc_html__( 'Token allowance', 'dsa' ) . '</h3><p>' . esc_html__( 'Tokens measure AI work. These figures include estimates held for unfinished, failed or unreported requests. Valid usage reports release unused units. Request counts still include every attempt.', 'dsa' ) . '</p><ul class="kiwe-tasks__list">';
			foreach ( [ 'minute'=>__( 'This minute', 'dsa' ), 'day'=>__( 'Today (UTC)', 'dsa' ), 'month'=>__( 'This month (UTC)', 'dsa' ) ] as $window=>$label ) echo '<li>' . esc_html( sprintf( __( '%1$s: %2$d of %3$d token units used or reserved; %4$d remaining.', 'dsa' ), $label, $tokens['used'][ $window ], $tokens['limits'][ $window ], $tokens['remaining'][ $window ] ) ) . '</li>';
			echo '</ul><p>' . esc_html( $tokens['startedAt'] ? sprintf( __( 'Token tracking began %s UTC. Earlier requests are not included in these token figures.', 'dsa' ), gmdate( 'Y-m-d H:i', $tokens['startedAt'] ) ) : __( 'Token tracking starts with the next AI request. Earlier requests are not included.', 'dsa' ) ) . '</p>';
		}
		if ( current_user_can( 'manage_options' ) ) echo '<p><a href="' . esc_url( admin_url( 'admin.php?page=kiwe-ai' ) ) . '">' . esc_html__( 'Website manager: open AI setup', 'dsa' ) . '</a></p>';
		echo '</section><p>' . esc_html__( 'An on preference allows assistance when a feature requests it. Provider setup, feature controls and the shared allowance still apply. A request already sent may finish after you switch a feature off.', 'dsa' ) . '</p></div>';
	}
}
