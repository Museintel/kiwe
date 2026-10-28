<?php
namespace DSA\Admin;

if ( ! defined( 'ABSPATH' ) ) exit;

/** Admin-only compatibility entrances and the unique technical residue. */
trait Workspace_Settings_Trait {
	public function consolidate_workspace_menus(): void {
		if(!defined('KIWE_REACT_WORKSPACE')||!KIWE_REACT_WORKSPACE||!\DSA\Access\Workspace_Admin_Service::uses_workspace(wp_get_current_user()))return;
		global $menu,$submenu;
		foreach(is_array($menu)?$menu:[] as $entry){$slug=$entry[2]??null;if(is_string($slug)&&str_starts_with($slug,'kiwe-'))remove_menu_page($slug);}
		foreach($submenu['kiwe']??[] as $entry){$slug=$entry[2]??null;if(is_string($slug)&&str_starts_with($slug,'kiwe-')&&!in_array($slug,['kiwe-overview','kiwe-tools'],true))remove_submenu_page('kiwe',$slug);}
		// Registration, capabilities and load hooks remain intact for dashboard links.
		// Native WordPress, WooCommerce and other plugins' menu slugs are untouched.
	}
	public function workspace_settings_destination( string $page,array $query=[] ): string {
		if ( ! defined('KIWE_REACT_WORKSPACE') || ! KIWE_REACT_WORKSPACE ) return '';
		$tool=admin_url('admin.php?page=kiwe-tools');
		if('kiwe-ai'===$page&&array_intersect(['kiwe-ai-key-created','kiwe-ai-key-revoked'],array_keys($query))){
			$args=['page'=>'kiwe-tools','tool'=>'connector'];
			foreach(['kiwe-ai-key-created','kiwe-ai-key-revoked'] as $key)if(is_string($query[$key]??null))$args[$key]=sanitize_key($query[$key]);
			return add_query_arg($args,admin_url('admin.php'));
		}
		if('kiwe-theme'===$page&&array_intersect(['theme-error','theme-imported','theme-deleted'],array_keys($query))){$args=['page'=>'kiwe-tools','tool'=>'themes'];foreach(['theme-error','theme-imported','theme-deleted'] as $key)if(is_string($query[$key]??null))$args[$key]=sanitize_key($query[$key]);return add_query_arg($args,admin_url('admin.php'));}
		$app=static fn($tab='')=>\DSA\Access\App_Workspace_Service::url('settings',$tab);
		$store=\DSA\Apps\Store_App_Service::enabled();
		return match($page){
			'kiwe','kiwe-overview'=>\DSA\Access\Workspace_Admin_Service::url(),
			'kiwe-context'=>admin_url('admin.php?page=kiwe-design-context'),
			'kiwe-build'=>admin_url('admin.php?page=kiwe-templates'),
			'kiwe-appshell'=>$app(),
			'kiwe-commerce','kiwe-woocommerce'=>$store?\DSA\Access\Commerce_App_Service::url('settings'):admin_url('admin.php?page=kiwe-app-library&app=store'),
			'kiwe-system','kiwe-security'=>$tool,
			'kiwe-app'=>match($query['tab']??'settings'){'adoption'=>\DSA\Access\Analytics_Workspace_Service::url('pwa'),'audience'=>\DSA\Access\App_Workspace_Service::url('activity'),'developer'=>admin_url('admin.php?page=kiwe-developer'),default=>$app()},
			'kiwe-haptic'=>$app('feedback'), 'kiwe-dock'=>$app('dock'), 'kiwe-theme','kiwe-style'=>$app('appearance'),
			'kiwe-search'=>$app('dock').'#app-search-settings',
			'kiwe-menu'=>admin_url('admin.php?page=kiwe-navigation'),
			'kiwe-links'=>admin_url('admin.php?page=kiwe-contact'),
			'kiwe-abandoned-cart'=>$store?\DSA\Access\Commerce_App_Service::url('saved-carts'):admin_url('admin.php?page=kiwe-app-library&app=store'),
			default=>'',
		};
	}
	public function redirect_workspace_settings(): void {
		if(!is_admin()||is_network_admin()||is_user_admin()||!is_user_logged_in()||!current_user_can('manage_options')||wp_doing_ajax()||wp_doing_cron()||(defined('REST_REQUEST')&&REST_REQUEST)||!in_array($_SERVER['REQUEST_METHOD']??'GET',['GET','HEAD'],true)||'admin.php'!==($GLOBALS['pagenow']??''))return;
		$page=is_string($_GET['page']??null)?sanitize_key(wp_unslash($_GET['page'])):'';
		$url=$this->workspace_settings_destination($page,is_array($_GET)?$_GET:[]);
		if(''!==$url){nocache_headers();wp_safe_redirect($url);exit;}
	}
	private function technical_tools(): array {
		$tools=[
			'auth'=>['Authentication connection','render_auth_page'],
			'secure'=>['Security and trusted proxies','render_secure_page'],
			'framework'=>['Framework and Bricks token installation','render_framework_page'],
			'bricks'=>['Bricks integration reference','render_bricks_page'],
			'email'=>['Email transport','render_email_page'],
			'database'=>['Database and cache','render_database_page'],
			'developer'=>['Updates, diagnostics and recovery','render_developer_page'],
			'games'=>['Games and rewards','render_games_page'],
			'themes'=>['Theme package management','render_technical_theme_packages'],
			'permissions'=>['Permission invitation rules',''],
			'presentation'=>['Classic-screen compatibility',''],
			'dock'=>['Custom Dock shortcuts',''],
			'menu'=>['Advanced page-guide rendering',''],
			'links'=>['Legacy Links-screen configuration',''],
			'connector'=>['External authoring access keys','render_technical_connector_keys'],
		];
		if(\DSA\Apps\Store_App_Service::enabled()){$tools['store']=['Store background maintenance',''];$tools['reminder-delivery']=['Legacy reminder delivery adapters',''];}
		if(\DSA\Apps\AI_App_Service::enabled()){$tools['ai']=['Advanced AI preferences','render_technical_ai_preferences'];$tools['ai-review']=['Deterministic AI review diagnostics','render_technical_ai_review'];}
		return $tools;
	}
	private function render_technical_ai_preferences(): void {
		$this->render_technical_settings_form('ai','Advanced AI preferences');
		echo '<p><a href="'.esc_url(\DSA\Access\AI_Workspace_Service::url()).'">'.esc_html__('Manage provider, credentials, allowances and assistance in Kiwe → App → AI','dsa').'</a></p>';
		if(function_exists('stp_pg_settings'))echo '<p><a href="'.esc_url(admin_url('admin.php?page=kiwe-secure&tab=settings')).'">'.esc_html__('Open the existing SecureTrack editor for its threat-review automation','dsa').'</a></p>';
	}
	private function render_technical_ai_review(): void {
		$settings=$this->settings->all();$ai_settings=$settings['ai'];
		$graph=new \DSA\AI\Site_Graph_Service($this->settings,$this->modules);
		$args=['focus'=>isset($_GET['advisor-focus'])?sanitize_key((string)wp_unslash($_GET['advisor-focus'])):'all','sampleLimit'=>8,'secureLimit'=>12,'includeSecureTrack'=>!empty($ai_settings['securetrack_brief_enabled'])];
		$advisor=(new \DSA\AI\Internal_AI_Advisor_Service(new \DSA\AI\Internal_AI_Context_Service($graph)))->advise($args);
		$enrichment=(new \DSA\AI\Internal_AI_Enrichment_Service(new \DSA\AI\Internal_AI_Advisor_Service(new \DSA\AI\Internal_AI_Context_Service($graph))))->enrich($args+['style'=>isset($_GET['advisor-style'])?sanitize_key((string)wp_unslash($_GET['advisor-style'])):'executive','limit'=>6]);
		$this->render_internal_ai_advisor($advisor,$enrichment,rest_url('dsa/v1/ai'));
	}
	public function render_technical_tools_page(): void {
		if(!Legacy_Settings::allowed())wp_die(esc_html__('Technical tools are available to the website’s technical owner.','dsa'),esc_html__('Permission denied','dsa'),['response'=>403]);
		$tools=$this->technical_tools();$tool=is_string($_GET['tool']??null)?sanitize_key(wp_unslash($_GET['tool'])):'';
		echo '<div class="wrap kiwe-admin-wrap"><h1>'.esc_html__('Kiwe technical tools','dsa').'</h1><p>'.esc_html__('These tools cover settings that do not yet have a Kiwe dashboard editor. Manage everyday Kiwe choices in their dashboard task.','dsa').'</p><p><a class="button button-primary" href="'.esc_url(\DSA\Access\Workspace_Admin_Service::url()).'">'.esc_html__('Open Kiwe dashboard','dsa').'</a></p>';
		if(isset($tools[$tool])){
			echo '<p><a href="'.esc_url(admin_url('admin.php?page=kiwe-tools')).'">'.esc_html__('All technical tools','dsa').'</a></p>';
			if(''!==$tools[$tool][1]){$callback=$tools[$tool][1];$this->$callback();}
			else $this->render_technical_settings_form($tool,$tools[$tool][0]);
		}else{
			echo '<ul>';foreach($tools as $id=>$entry)echo '<li><a href="'.esc_url(add_query_arg(['page'=>'kiwe-tools','tool'=>$id],admin_url('admin.php'))).'">'.esc_html($entry[0]).'</a></li>';echo '</ul>';
		}
		echo '</div>';
	}
	private function render_technical_settings_form(string $section,string $title): void {
		$fields=Legacy_Settings::fields($section);$all=$this->settings->all();
		echo '<h2>'.esc_html($title).'</h2>';
		if(isset($_GET['settings-updated']))echo '<div class="notice notice-success"><p>'.esc_html__('Technical choices saved.','dsa').'</p></div>';
		echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="dsa_save_technical_settings"><input type="hidden" name="section" value="'.esc_attr($section).'"><input type="hidden" name="expected" value="'.esc_attr(Legacy_Settings::expected($this->settings)).'">';wp_nonce_field('dsa_save_technical_settings');
		echo '<table class="form-table" role="presentation"><tbody>';
		foreach($fields as $path=>$f){
			$value=Legacy_Settings::value($all,$path);$name='values['.$path.']';$type=$f['type'];
			echo '<tr><th scope="row"><label for="kiwe-tech-'.esc_attr($path).'">'.esc_html($f['label']).'</label></th><td>';
			if('bool'===$type)echo '<input type="hidden" name="'.esc_attr($name).'" value="0"><input id="kiwe-tech-'.esc_attr($path).'" type="checkbox" name="'.esc_attr($name).'" value="1" '.checked(!empty($value),true,false).'>';
			elseif('choice'===$type){echo '<select id="kiwe-tech-'.esc_attr($path).'" name="'.esc_attr($name).'">';foreach($f['options'] as $option)echo '<option value="'.esc_attr($option).'" '.selected($value,$option,false).'>'.esc_html($option).'</option>';echo '</select>';}
			elseif(in_array($type,['shortcuts','locations','menu-links','ids','heading-levels','textarea'],true)){
				if('locations'===$type&&!\DSA\Apps\Store_App_Service::enabled()&&is_array($value))unset($value['single_product']);
				if(in_array($type,['shortcuts','locations','menu-links'],true))$value=wp_json_encode(is_array($value)?$value:[],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES);
				elseif(in_array($type,['ids','heading-levels'],true))$value=implode(', ',is_array($value)?$value:[]);
				echo '<textarea id="kiwe-tech-'.esc_attr($path).'" class="large-text" rows="5" name="'.esc_attr($name).'">'.esc_textarea((string)$value).'</textarea>';
				if(in_array($type,['shortcuts','locations','menu-links'],true))echo '<p class="description">'.esc_html__('Uses the existing native JSON structure. Retain stable IDs; shortcut visibility is managed in App settings → Dock.','dsa').'</p>';
			}else{echo '<input id="kiwe-tech-'.esc_attr($path).'" class="regular-text" type="'.(in_array($type,['secret','encrypted-secret'],true)?'password':(in_array($type,['int','optional-int'],true)?'number':(in_array($type,['url','webhook'],true)?'url':'text'))).'" name="'.esc_attr($name).'" value="'.esc_attr(in_array($type,['secret','encrypted-secret'],true)?'':(string)$value).'"'.(isset($f['min'])?' min="'.esc_attr((string)$f['min']).'" max="'.esc_attr((string)$f['max']).'" step="1"':'').(in_array($type,['secret','encrypted-secret'],true)?' autocomplete="new-password"':'').'>';}
			echo '</td></tr>';
		}
		echo '</tbody></table>';submit_button(__('Save technical choices','dsa'));echo '</form>';
	}
	public function save_technical_settings(): void {
		if(!Legacy_Settings::allowed())wp_die(esc_html__('Permission denied.','dsa'),'', ['response'=>403]);
		check_admin_referer('dsa_save_technical_settings');
		$section=is_string($_POST['section']??null)?sanitize_key(wp_unslash($_POST['section'])):'';
		$expected=is_string($_POST['expected']??null)?wp_unslash($_POST['expected']):'';
		$raw=get_option(DSA_OPTION_SETTINGS,null);
		if(!is_array($raw)||!hash_equals(Legacy_Settings::expected($this->settings),$expected))wp_die(esc_html__('Settings changed. Reload and review before saving.','dsa'),'', ['response'=>409]);
		$values=is_array($_POST['values']??null)?wp_unslash($_POST['values']):[];
		$patch=Legacy_Settings::prepare($section,$values,$this->settings);
		if(is_wp_error($patch))wp_die(esc_html($patch->get_error_message()),'', ['response'=>400]);
		if(isset($patch['dock']['custom_items'])){
			$normalized=$this->sanitize_dock_settings(['custom_items'=>$patch['dock']['custom_items']],$this->settings->all()['dock']);
			$patch['dock']=array_replace($patch['dock'],array_intersect_key($normalized,array_flip(['custom_items','enabled_items','item_order','focus_item'])));
		}
		if($patch&&!$this->settings->compare_and_update_selected($raw,$patch))wp_die(esc_html__('Settings changed during saving. Reload and review.','dsa'),'', ['response'=>409]);
		if($patch)do_action('litespeed_purge_all');
		wp_safe_redirect(add_query_arg(['page'=>'kiwe-tools','tool'=>$section,'settings-updated'=>'1'],admin_url('admin.php')));exit;
	}
	private function render_technical_theme_packages(): void {
		$theme_service=new \DSA\Theme\Theme_Package_Service();
		$installed_themes=array_map([$theme_service,'public_record'],$theme_service->all());
		$active_theme=$theme_service->active($this->settings->all());$active_theme_id=(string)($active_theme['id']??'legacy');
		if(isset($_GET['theme-error']))echo '<div class="notice notice-error"><p>'.esc_html($this->theme_error_message(sanitize_key((string)wp_unslash($_GET['theme-error'])))).'</p></div>';
		?>
<section class="dsa-admin__panel">
				<h2><?php esc_html_e( 'Installed themes', 'dsa' ); ?></h2>
				<p><?php esc_html_e( 'A Kiwe theme package contains the visual theme manifest, optional CSS, and the Kiwe theme settings preset in one importable file. Built-in themes stay available; imported themes appear here and can be activated or exported.', 'dsa' ); ?></p>
				<div class="dsa-admin-token-summary">
					<div><strong><?php echo esc_html( (string) count( $installed_themes ) ); ?></strong><span><?php esc_html_e( 'installed', 'dsa' ); ?></span></div>
					<div><strong><?php echo esc_html( $active_theme_id ); ?></strong><span><?php esc_html_e( 'active theme', 'dsa' ); ?></span></div>
					<div><strong><?php esc_html_e( 'CSS + settings', 'dsa' ); ?></strong><span><?php esc_html_e( 'package', 'dsa' ); ?></span></div>
					<div><strong><?php esc_html_e( 'Geometry locked', 'dsa' ); ?></strong><span><?php esc_html_e( 'core-owned', 'dsa' ); ?></span></div>
				</div>
				<table class="widefat striped" style="margin-top: 1rem;">
					<thead><tr><th><?php esc_html_e( 'Theme', 'dsa' ); ?></th><th><?php esc_html_e( 'Profile', 'dsa' ); ?></th><th><?php esc_html_e( 'Package', 'dsa' ); ?></th><th><?php esc_html_e( 'Actions', 'dsa' ); ?></th></tr></thead>
					<tbody>
						<?php foreach ( $installed_themes as $record ) : ?>
							<?php $id = (string) ( $record['id'] ?? '' ); ?>
							<tr>
								<td><strong><?php echo esc_html( (string) ( $record['name'] ?? $id ) ); ?></strong><br><code><?php echo esc_html( $id ); ?></code><?php if ( ! empty( $record['description'] ) ) : ?><p class="description"><?php echo esc_html( (string) $record['description'] ); ?></p><?php endif; ?></td>
								<td><?php echo esc_html( (string) ( $record['profile'] ?? 'marketplace' ) ); ?></td>
								<td><?php echo ! empty( $record['builtIn'] ) ? esc_html__( 'Built in', 'dsa' ) : esc_html__( 'Imported', 'dsa' ); ?> · <?php echo ! empty( $record['hasCss'] ) ? esc_html__( 'CSS', 'dsa' ) : esc_html__( 'No CSS', 'dsa' ); ?> · <?php echo ! empty( $record['hasSettings'] ) ? esc_html__( 'Settings', 'dsa' ) : esc_html__( 'No settings', 'dsa' ); ?></td>
								<td class="dsa-admin-inline-fields">
									<?php if ( $id !== $active_theme_id ) : ?>
										<a class="button" href="<?php echo esc_url( \DSA\Access\App_Workspace_Service::url( 'settings', 'appearance' ) ); ?>"><?php esc_html_e( 'Choose theme in Kiwe', 'dsa' ); ?></a>
									<?php else : ?>
										<span class="button disabled" aria-disabled="true"><?php esc_html_e( 'Active', 'dsa' ); ?></span>
									<?php endif; ?>
									<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="dsa_export_theme"><input type="hidden" name="themeId" value="<?php echo esc_attr( $id ); ?>"><?php wp_nonce_field( 'dsa_export_theme' ); ?><button class="button button-secondary" type="submit"><?php esc_html_e( 'Export', 'dsa' ); ?></button></form>
									<?php if ( empty( $record['builtIn'] ) ) : ?>
										<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="dsa_delete_theme"><input type="hidden" name="themeId" value="<?php echo esc_attr( $id ); ?>"><?php wp_nonce_field( 'dsa_delete_theme_' . $id ); ?><button class="button button-secondary" type="submit"><?php esc_html_e( 'Delete', 'dsa' ); ?></button></form>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				<h3><?php esc_html_e( 'Import theme package', 'dsa' ); ?></h3>
				<form method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="dsa_import_theme">
					<?php wp_nonce_field( 'dsa_import_theme' ); ?>
					<p class="dsa-admin-inline-fields">
						<label><span><?php esc_html_e( 'Theme JSON package', 'dsa' ); ?></span><input type="file" name="dsa_theme_file" accept="application/json,.json" required></label>
						<?php submit_button( __( 'Import Theme', 'dsa' ), 'secondary', 'submit', false ); ?>
					</p>
				</form>
			</section>
		<?php
	}

	private function render_technical_connector_keys(): void {
		if(isset($_GET['kiwe-ai-key-revoked']))echo '<div class="notice notice-info"><p>'.esc_html('1'===$_GET['kiwe-ai-key-revoked']?__('The access key was revoked.','dsa'):__('The access key could not be revoked. Review its current status below.','dsa')).'</p></div>';
		$key_service    = new \DSA\AI\Access_Key_Service();
		$keys           = $key_service->public_records();
		$created_key    = '';
		$created_token  = isset( $_GET['kiwe-ai-key-created'] ) ? sanitize_key( (string) wp_unslash( $_GET['kiwe-ai-key-created'] ) ) : '';
		if ( '' !== $created_token ) {
			$created_key = (string) get_transient( 'dsa_ai_key_once_' . $created_token );
			if ( '' === $created_key ) {
				$created_fallback = get_option( 'dsa_ai_key_once_' . $created_token, [] );
				if ( is_array( $created_fallback ) && time() <= absint( $created_fallback['expiresAt'] ?? 0 ) ) {
					$created_key = (string) ( $created_fallback['key'] ?? '' );
				}
			}
			delete_transient( 'dsa_ai_key_once_' . $created_token );
			delete_option( 'dsa_ai_key_once_' . $created_token );
		}
		
		if(''!==$created_key)echo '<div class="notice notice-success"><p>'.esc_html__('Copy this key now. It is shown only once.','dsa').'</p><code>'.esc_html($created_key).'</code></div>';
		?>
<section class="dsa-admin__panel">
				<h2><?php esc_html_e( 'API access keys', 'dsa' ); ?></h2>
				<p><?php esc_html_e( 'Create a revocable key when an external AI tool needs to inspect this site and use Kiwe’s safe AI connector endpoints. Use Authorization: Bearer or X-Kiwe-AI-Key. Keys are scoped, hashed at rest, and can be deleted from this page.', 'dsa' ); ?></p>
				<div class="dsa-admin-token-summary">
					<div><strong><?php echo esc_html( (string) count( array_filter( $keys, static fn( $record ) => empty( $record['revokedAt'] ) ) ) ); ?></strong><span><?php esc_html_e( 'active keys', 'dsa' ); ?></span></div>
					<div><strong><?php esc_html_e( 'Hashed', 'dsa' ); ?></strong><span><?php esc_html_e( 'storage', 'dsa' ); ?></span></div>
					<div><strong><?php esc_html_e( 'Scoped', 'dsa' ); ?></strong><span><?php esc_html_e( 'access', 'dsa' ); ?></span></div>
					<div><strong><?php esc_html_e( 'Revocable', 'dsa' ); ?></strong><span><?php esc_html_e( 'control', 'dsa' ); ?></span></div>
				</div>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="dsa_create_ai_access_key">
					<?php wp_nonce_field( 'dsa_create_ai_access_key' ); ?>
					<p class="dsa-admin-inline-fields">
						<label>
							<span><?php esc_html_e( 'Label', 'dsa' ); ?></span>
							<input type="text" name="label" value="" placeholder="<?php esc_attr_e( 'Claude / ChatGPT / Codex testing', 'dsa' ); ?>">
						</label>
						<label>
							<input type="checkbox" name="scopes[]" value="all">
							<span><?php esc_html_e( 'All Kiwe AI connector access', 'dsa' ); ?></span>
						</label>
						<?php submit_button( __( 'Create API Key', 'dsa' ), 'primary', 'submit', false ); ?>
					</p>
					<details>
						<summary><?php esc_html_e( 'Advanced scopes', 'dsa' ); ?></summary>
						<p class="description"><?php esc_html_e( '“All” is intentionally unchecked because it includes high-authority staging, execution and mutation routes. Select only the scopes the client needs. If none are selected, Kiwe creates a site_graph-only key.', 'dsa' ); ?></p>
						<p class="dsa-admin-token-chips">
							<?php foreach ( \DSA\AI\Access_Key_Service::SCOPES as $scope ) : ?>
								<?php if ( 'all' === $scope ) { continue; } ?>
								<label><input type="checkbox" name="scopes[]" value="<?php echo esc_attr( $scope ); ?>"> <code><?php echo esc_html( $scope ); ?></code></label>
							<?php endforeach; ?>
						</p>
					</details>
				</form>

				<?php if ( [] !== $keys ) : ?>
					<table class="widefat striped" style="margin-top: 1rem;">
						<thead><tr><th><?php esc_html_e( 'Label', 'dsa' ); ?></th><th><?php esc_html_e( 'Key', 'dsa' ); ?></th><th><?php esc_html_e( 'Scopes', 'dsa' ); ?></th><th><?php esc_html_e( 'Created', 'dsa' ); ?></th><th><?php esc_html_e( 'Last used', 'dsa' ); ?></th><th><?php esc_html_e( 'Status', 'dsa' ); ?></th><th><?php esc_html_e( 'Action', 'dsa' ); ?></th></tr></thead>
						<tbody>
							<?php foreach ( $keys as $record ) : ?>
								<?php
								if ( ! is_array( $record ) ) {
									continue;
								}
								$id      = sanitize_key( (string) ( $record['id'] ?? '' ) );
								$revoked = '' !== (string) ( $record['revokedAt'] ?? '' );
								?>
								<tr>
									<td><?php echo esc_html( (string) ( $record['label'] ?? '' ) ); ?></td>
									<td><code><?php echo esc_html( (string) ( $record['prefix'] ?? '' ) ); ?>...<?php echo esc_html( (string) ( $record['last4'] ?? '' ) ); ?></code></td>
									<td><code><?php echo esc_html( implode( ', ', isset( $record['scopes'] ) && is_array( $record['scopes'] ) ? $record['scopes'] : [] ) ); ?></code></td>
									<td><?php echo esc_html( (string) ( $record['createdAt'] ?? '' ) ); ?></td>
									<td><?php echo esc_html( (string) ( $record['lastUsedAt'] ?? __( 'Never', 'dsa' ) ) ); ?></td>
									<td><?php echo $revoked ? esc_html__( 'Revoked', 'dsa' ) : esc_html__( 'Active', 'dsa' ); ?></td>
									<td>
										<?php if ( ! $revoked && '' !== $id ) : ?>
											<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
												<input type="hidden" name="action" value="dsa_revoke_ai_access_key">
												<input type="hidden" name="keyId" value="<?php echo esc_attr( $id ); ?>">
												<?php wp_nonce_field( 'dsa_revoke_ai_access_key_' . $id ); ?>
												<button class="button button-secondary" type="submit"><?php esc_html_e( 'Revoke', 'dsa' ); ?></button>
											</form>
										<?php else : ?>
											<span aria-hidden="true">—</span>
										<?php endif; ?>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>
			</section>
		<?php
	}

}
