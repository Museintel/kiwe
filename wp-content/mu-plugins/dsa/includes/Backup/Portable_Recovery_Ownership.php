<?php
namespace DSA\Backup;

if ( ! defined( 'ABSPATH' ) ) exit;
if(!class_exists(\DSA\Onboarding\Search_Defaults_Model::class,false))require_once dirname(__DIR__).'/Onboarding/Search_Defaults_Model.php';
if(!class_exists(Portable_Store_Recovery::class,false))require_once __DIR__.'/Portable_Store_Recovery.php';

/** Native destination ownership and conservative copied-state quarantine; no WordPress bootstrap. */
final class Portable_Recovery_Ownership {
	public const SCHEMA = 'kiwe.private-recovery-ownership.v1';
	private const CORE = [ 'comments','commentmeta','links','options','postmeta','posts','term_relationships','term_taxonomy','termmeta','terms','usermeta','users' ];
	private const OPTIONS = [
		'home','siteurl','blogname','blogdescription','blog_public','timezone_string','gmt_offset','date_format','time_format','start_of_week','posts_per_page','posts_per_rss','show_on_front','page_on_front','page_for_posts','site_icon','permalink_structure','category_base','tag_base','blog_charset','WPLANG','template','stylesheet','current_theme','db_version','initial_db_version','upload_path','upload_url_path','uploads_use_yearmonth_folders','thumbnail_size_w','thumbnail_size_h','thumbnail_crop','medium_size_w','medium_size_h','large_size_w','large_size_h','comment_registration','default_comment_status','default_ping_status','comment_moderation','comment_max_links','comment_whitelist','moderation_keys','disallowed_keys','thread_comments','thread_comments_depth','page_comments','comments_per_page','default_comments_page','comment_order','close_comments_for_old_posts','close_comments_days_old','require_name_email','avatar_default','avatar_rating','show_avatars','nav_menu_options',
		'dsa_settings','dsa_install_profile','kiwe_seam_design_context_v1','kiwe_site_logo_id','kiwe_site_logo_inverse_id','kiwe_store_phone','kiwe_store_email','kiwe_store_app_enabled_v1', 'kiwe_seo_workspace_enabled_v1','kiwe_seo_defaults_v1','kiwe_seo_redirect_store_v1','kiwe_user_groups_v1','kiwe_tag_groups_v1','pk_settings_v3','stp_settings',
		'bricks_settings','bricks_global_classes','bricks_global_classes_categories','bricks_global_classes_locked','bricks_global_classes_trash','bricks_global_variables','bricks_global_variables_categories','bricks_color_palette','bricks_theme_styles','bricks_components','bricks_global_elements','bricks_breakpoints','bricks_global_pseudo_classes','bricks_style_manager',
	];
	private const PROFILE = [ 'first_name','last_name','nickname','description','locale','rich_editing','syntax_highlighting','admin_color','show_admin_bar_front','kiwe_avatar_id','kiwe_public_team_member','kiwe_team_title','kiwe_team_order','kiwe_founder_title','kiwe_linkedin_url','facebook','_kiwe_campaign_groups','_stp_public_author_slug' ];
	/** Historical facts have no provider/identity authority. Keep their original source identifiers. */
	public static function reporting_definition( string $suffix ): ?array {
		return match ( $suffix ) {
			'kiwe_search_buckets_v1'=>[ 'columns'=>['bucket','property_hash','source','search_type','dataset','day','aggregation','source_sha','row_count','key_bytes','synced_at'], 'primary'=>['bucket'], 'limit'=>4000 ],
			'kiwe_search_rows_v1'=>[ 'columns'=>['row_key','bucket','property_hash','source','search_type','dataset','day','entity','page_hash','label','secondary_label','clicks','impressions','position'], 'primary'=>['row_key'], 'limit'=>60000 ],
			'stp_report_days'=>[ 'columns'=>['day','scope_hash','views','seconds','timed_views','events','login_failures','protections'], 'primary'=>['scope_hash','day'], 'limit'=>50000 ],
			'stp_report_sessions'=>[ 'columns'=>['day','scope_hash','session_id'], 'primary'=>['scope_hash','day','session_id'], 'limit'=>100000 ],
			'stp_report_activity'=>[ 'columns'=>['user_id','last_activity'], 'primary'=>['user_id'], 'limit'=>50000 ],
			default=>null,
		};
	}
	public static function reporting_table( string $suffix, array $table ): bool {
		$definition=self::reporting_definition($suffix);if(!$definition)return false;
		if(($table['columns']??null)!==$definition['columns']||($table['primary']??null)!==$definition['primary']||!is_int($table['rows']??null)||$table['rows']<0||$table['rows']>$definition['limit'])self::fail();
		return true;
	}
	/** Verify inert rows in bounded packets; no WordPress, provider, permissions or schedules boot. */
	public static function reporting_row( string $suffix, array $row ): bool {
		$definition=self::reporting_definition($suffix);if(!$definition||count($row)!==count($definition['columns']))self::fail();$r=array_combine($definition['columns'],$row);
		foreach($r as$key=>$value){
			if($key==='position'){if($value!==null&&(!is_string($value)||!is_numeric($value)||!is_finite((float)$value)||(float)$value<0))self::fail();continue;}
			if(!is_string($value)||strlen($value)>2048||str_contains($value,"\0")||!preg_match('//u',$value))self::fail();
			if(in_array($key,['bucket','row_key','property_hash','scope_hash','source_sha','entity','page_hash'],true)){if(!preg_match('/^[a-f0-9]{64}$/D',$value))self::fail();}
			elseif($key==='day'){if(!preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D',$value)||!checkdate((int)substr($value,5,2),(int)substr($value,8,2),(int)substr($value,0,4)))self::fail();}
			elseif($key==='last_activity'){ $d=\DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$value,new \DateTimeZone('UTC'));if(!$d||$d->format('Y-m-d H:i:s')!==$value)self::fail(); }
			elseif($key==='source'){if(!in_array($value,['gsc','rank-math'],true))self::fail();}
			elseif($key==='search_type'){if(!in_array($value,['web','image','video','news','discover','googleNews','unknown'],true))self::fail();}
			elseif($key==='dataset'){if(!in_array($value,['site','page','query','legacy'],true))self::fail();}
			elseif($key==='aggregation'){if(!in_array($value,['byProperty','byPage','unknown'],true))self::fail();}
			elseif(!in_array($key,['label','secondary_label'],true)&&(!preg_match('/^(?:0|[1-9][0-9]{0,14})$/D',$value)))self::fail();
		}
		if(isset($r['page_hash'])&&!hash_equals($r['page_hash'],hash('sha256',$r['label'])))self::fail();
		if(isset($r['row_count'])&&(int)$r['row_count']>1000)self::fail();
		if(isset($r['secondary_label'])&&strlen($r['secondary_label'])>1000)self::fail();
		return true;
	}
	private static function fail(): never { throw new Archive_Error( 'Destination ownership or copied-state quarantine could not be verified.' ); }
	private static function json( array $v ): string { return json_encode( $v,JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR ); }
	private static function q( string $v ): string { return "X'".bin2hex( $v )."'"; }
	private static function id( string $v ): string { return Portable_Table_Definition::identifier( $v ); }
	private static function number( string $v ): string { if ( ! preg_match( '/^[1-9][0-9]{0,14}$/D',$v ) ) self::fail(); return $v; }
	private static function delete_ids( callable $query,string $table,string $column,array $ids ): void { foreach ( array_chunk( $ids,256 ) as $chunk ) $query( 'DELETE FROM '.$table.' WHERE '.self::id( $column ).' IN ('.implode( ',',array_map( [ self::class,'number' ],$chunk ) ).')' ); }
	public static function policy( array $spec, string $recovery ): array {
		if ( array_keys( $spec ) !== [ 'schema','recoverySha256','destinationOwnerId','sourceOwnerId','mode' ] || $spec['schema'] !== self::SCHEMA || $spec['recoverySha256'] !== $recovery || ! preg_match( '/^[a-f0-9]{64}$/D',$recovery ) || ! is_int( $spec['destinationOwnerId'] ) || $spec['destinationOwnerId'] < 1 || ( $spec['sourceOwnerId'] !== null && ( ! is_int( $spec['sourceOwnerId'] ) || $spec['sourceOwnerId'] < 1 ) ) || $spec['mode'] !== 'quarantine-copied-authority' ) self::fail(); return $spec;
	}
	/** Only the current native WordPress bcrypt formats, without executing pluggable/plugin code. */
	public static function password( #[\SensitiveParameter] string $plain, #[\SensitiveParameter] string $hash ): bool {
		if ( strlen( $plain ) < 1 || strlen( $plain ) > 4096 || str_contains( $plain,"\0" ) ) return false;
		$wp = str_starts_with( $hash,'$wp$2y$' ); $native = $wp ? substr( $hash,3 ) : $hash;
		if ( ! preg_match( '/^\$2y\$(0[4-9]|1[0-4])\$[.\/A-Za-z0-9]{53}$/D',$native ) ) return false;
		return password_verify( $wp ? base64_encode( hash_hmac( 'sha384',$plain,'wp-sha384',true ) ) : $plain,$native );
	}
	/** Arrays/scalars only. No classes, references or excessive allocation in native configuration. */
	public static function data( string $bytes ): array {
		if ( strlen( $bytes ) > 4194304 || substr( $bytes,0,2 ) !== 'a:' || substr_count( $bytes,';' ) > 100000 || preg_match( '/(?:^|[;{}])(?:O|C|R|r):/',$bytes ) ) self::fail();
		$value = @unserialize( $bytes,[ 'allowed_classes'=>false,'max_depth'=>48 ] ); if ( ! is_array( $value ) || serialize( $value ) !== $bytes ) self::fail();
		$count = 0; $visit = static function( $v,int $depth ) use ( &$visit,&$count ): void { if ( ++$count > 100000 || $depth > 48 || ( ! is_array( $v ) && ! is_scalar( $v ) && $v !== null ) ) self::fail(); if ( is_array( $v ) ) foreach ( $v as $x ) $visit( $x,$depth+1 ); }; $visit( $value,0 ); return $value;
	}
	/** Built-in portable configuration only; unknown options remain in the authenticated private source. */
	public static function option( string $key ): bool { return in_array( $key,self::OPTIONS,true ) || (bool)preg_match( '/^(?:theme_mods_[a-zA-Z0-9_-]+|widget_(?:text|block|custom_html|media_image|nav_menu))$/D',$key ) || $key === 'sidebars_widgets'; }
	public static function profile( string $key ): bool { return in_array( $key,self::PROFILE,true ) || (bool)preg_match( '/^(?:billing|shipping)_(?:first_name|last_name|company|address_1|address_2|city|state|postcode|country|email|phone)$/D',$key ); }
	public static function seo_defaults(string $bytes): string {
		if(strlen($bytes)>65536)self::fail();$value=self::data($bytes);if(\DSA\Onboarding\Search_Defaults_Model::validate($value)===false)self::fail();return serialize($value);
	}
	public static function redirect_columns(): array {return ['id','source_hash','fold_hash','source_path','ignore_case','destination','code','state','source_id','source_sha','imported_sha'];}
	/** Authored exact rules survive URL mapping; copied import/undo ownership does not. */
	public static function redirect_row(array $row): array {
		if(count($row)!==11||array_filter($row,static fn($v)=>!is_string($v)))self::fail();$r=array_combine(self::redirect_columns(),$row);self::number($r['id']);
		foreach(['source_hash','fold_hash','source_sha','imported_sha']as$key)if(!preg_match('/^[a-f0-9]{64}$/D',$r[$key]))self::fail();
		$p=$r['source_path'];if(strlen($p)>2048||$p===''||trim($p,'/')!==$p||!preg_match('//u',$p)||preg_match('/[\x00-\x20\x7f\\\\]/',$p)||preg_match('/%(?![a-f0-9]{2})|%(?:00|0a|0d|2f|5c)/i',$p)||str_contains($p,'?')||str_contains($p,'#')||str_contains($p,'://')||preg_match('~(?:^|/)\.{1,2}(?:/|$)~',rawurldecode($p))||preg_match('~^(?:wp-admin(?:/|$)|wp-json(?:/|$)|wp-login\.php(?:/|$)|wp-cron\.php(?:/|$))~i',$p))self::fail();
		if(!in_array($r['ignore_case'],['0','1'],true)||!in_array($r['state'],['active','pending'],true)||!in_array($r['code'],['301','302','307','308','410','451'],true)||!preg_match('/^(?:0|[1-9][0-9]{0,14})$/D',$r['source_id']))self::fail();
		$ignore=$r['ignore_case']==='1';if($r['source_hash']!==hash('sha256',($ignore?'i:':'c:').($ignore?strtolower($p):$p))||$r['fold_hash']!==hash('sha256',strtolower($p)))self::fail();
		if(in_array($r['code'],['410','451'],true)) {if($r['destination']!=='')self::fail();}
		elseif(\DSA\Onboarding\Search_Defaults_Model::url($r['destination'])===''||!filter_var($r['destination'],FILTER_VALIDATE_URL)||str_contains($r['destination'],'\\')||preg_match('/%(?![a-f0-9]{2})|%(?:00|0a|0d)/i',$r['destination']))self::fail();
		$r['source_id']='0';$r['source_sha']=str_repeat('0',64);$r['imported_sha']=str_repeat('0',64);return array_values($r);
	}
	public static function redirect_settings(string $bytes): string {
		if(strlen($bytes)>1024)self::fail();$r=self::data($bytes);if(array_diff(array_keys($r),['schema','enabled','observe404'])||count($r)!==3||($r['schema']??null)!==1||!is_bool($r['enabled']??null)||!is_bool($r['observe404']??null))self::fail();$r['observe404']=false;return serialize($r);
	}
	/** Strip provider/auth fields from recognized presentation arrays, including nested wrappers. */
	public static function scrub( array $value ): array {
		$out = [];
		foreach ( $value as $key=>$v ) {
			$normalized = strtolower( preg_replace( '/[^a-zA-Z0-9]/','',(string)$key ) );
			if ( preg_match( '/^(?:token|auth)$|secret|password|credential|apikey|licensekey|accesstoken|refreshtoken|authorization|webhook|smtp|trusteddevice|sessiontoken/',$normalized ) || ( is_string( $v ) && preg_match( '/dsa-(?:secret:|sodium:|gcm:)/',$v ) ) ) continue;
			$out[$key] = is_array( $v ) ? self::scrub( $v ) : $v;
		} return $out;
	}
	public static function settings( string $bytes, bool $store_connected = false ): string {
		$source = self::data( $bytes ); $keep = [ 'enabled','style','position','surface_width','surface_bottom','fragment_navigation','service_worker','bricks_first','protected_flow','phonekey','secure','bricks','search','haptic','dock','visual_effects','app','dsa_theme','tokens','theme_screens','schema_geo','metrics','permissions','games','link_hub' ];
		$clean = self::scrub( array_intersect_key( $source,array_fill_keys( $keep,true ) ) );
		// Empty transports never inherit archived credentials. Existing strict identity enforcement stays enabled.
		$clean['email'] = [ 'enabled'=>false ]; $clean['ai'] = [ 'studio_enabled'=>false,'native_provider'=>'none' ];
		$clean['abandoned_cart'] = [ 'enabled'=>false ];
		if ( $store_connected ) $clean = array_replace( $clean, Portable_Store_Recovery::clean_settings( $source ) );
		return serialize( $clean );
	}
	/** Native configuration choices, not enrollment, credentials, delivery or security-event history.
	 * Deliberately independent of WordPress/plugin execution at the inactive-table boundary.
	 * Unknown future fields stay in the authenticated source instead of silently becoming active.
	 */
	public static function configuration( string $key, string $bytes ): string {
		$source = self::data( $bytes ); $clean = []; $integers = []; $enums = []; $texts = [];
		if ( $key === 'pk_settings_v3' ) {
			$enums = [ 'popup_display'=>['disabled','logged_out','new_visitors','every_visit'], 'popup_dismissal'=>['outside_close','close_only','mandatory'], 'mandatory_scope'=>['all_public','patterns'], 'identifier_mode'=>['email','phone','email_or_phone','email_and_phone'], 'app_identifier_mode'=>['email','phone','email_or_phone','email_and_phone'], 'app_verification_timing'=>['verify_now','verify_later','progressive'], 'verification_timing'=>['verify_now','verify_later','progressive'], 'return_action'=>['none','prompt','progressive'], 'email_delivery'=>['otp','magic_link'] ];
			$integers = [ 'low_friction_signup'=>[0,1], 'purchase_verified_contact'=>[0,1], 'progressive_visits'=>[1,20], 'return_seconds'=>[1,60], 'otp_target_limit'=>[1,50], 'otp_target_window_min'=>[1,1440] ];
			$texts = [ 'mandatory_patterns'=>8192, 'return_message'=>1024, 'default_country_code'=>8 ];
			// Copied accounts are suspended; the verified destination owner must enroll afresh.
			$clean = [ 'signup_role'=>'subscriber','protect_wp_admin'=>0,'replace_wp_login'=>0,'phone_delivery'=>'otp','sms_provider'=>'','sms_webhook'=>'','whatsapp_provider'=>'','whatsapp_webhook'=>'','whatsapp_mode'=>'phonekey_gateway','whatsapp_key_id'=>'','whatsapp_secret'=>'','whatsapp_email_fallback'=>0,'trusted_devices'=>0,'ip_trust_roles'=>[],'admin_reauth_minutes'=>0,'role_matrix'=>[] ];
		} elseif ( $key === 'stp_settings' ) {
			$integers = [ 'red_threshold'=>[1,100], 'yellow_threshold'=>[0,100], 'green_trim_days'=>[1,3650], 'yellow_trim_days'=>[1,3650], 'alert_on_red'=>[0,1], 'alert_repeat_window_mins'=>[1,1440], 'alert_hourly_limit'=>[1,100], 'track_visitors'=>[0,1], 'track_pages'=>[0,1], 'track_admin_activity'=>[0,1], 'exclude_admin'=>[0,1], 'harden_xmlrpc'=>[0,1], 'harden_rest_users'=>[0,1], 'harden_author_archives'=>[0,1], 'author_public_slugs'=>[0,1], 'harden_file_editor'=>[0,1], 'harden_wp_generator'=>[0,1], 'harden_security_headers'=>[0,1], 'chain_window_mins'=>[1,1440], 'subnet_alert_at'=>[1,100] ];
			$enums = [ 'alert_delivery_policy'=>['actionable','yellow_and_actionable'] ];
			// Host/network decisions and break-glass routes are destination-specific. No source AI replay.
			$clean = [ 'alert_email'=>'','geo_enabled'=>0,'country_blocklist'=>'','login_country_policy'=>'off','login_allowed_countries'=>'','emergency_safe_mode'=>1,'block_brute_force'=>0,'adaptive_waf'=>0,'honeypot_enabled'=>0,'tarpit_enabled'=>0,'endpoint_rate_limits'=>0,'idle_timeout_roles'=>[],'idle_timeout_mins'=>0,'csp_enabled'=>0,'csp_report_uri'=>'','security_txt_enabled'=>0,'adaptive_learning'=>0,'behavioral_risk'=>0,'attack_graph'=>0,'subnet_intel'=>0,'chain_detection'=>0,'v2_site_brain'=>0,'v2_ai_provider'=>'none','v2_ai_model'=>'','v2_ai_key'=>'','v2_ai_key_enc'=>'','v2_auto_block_local'=>0,'v2_share_patterns'=>0 ];
		} else self::fail();
		foreach ( $enums as $field=>$values ) if ( array_key_exists( $field,$source ) ) { if ( ! is_string( $source[$field] ) || ! in_array( $source[$field],$values,true ) ) self::fail(); $clean[$field] = $source[$field]; }
		foreach ( $integers as $field=>$range ) if ( array_key_exists( $field,$source ) ) { $value = $source[$field]; if ( is_bool( $value ) ) $value = (int)$value; if ( is_string( $value ) && preg_match( '/^(?:0|[1-9][0-9]{0,3})$/D',$value ) ) $value = (int)$value; if ( ! is_int( $value ) || $value < $range[0] || $value > $range[1] ) self::fail(); $clean[$field] = $value; }
		foreach ( $texts as $field=>$limit ) if ( array_key_exists( $field,$source ) ) { $value = $source[$field]; if ( ! is_string( $value ) || strlen( $value ) > $limit || str_contains( $value,"\0" ) || ! preg_match( '//u',$value ) || preg_match( '/dsa-(?:secret:|sodium:|gcm:)/',$value ) ) self::fail(); $clean[$field] = $value; }
		if ( isset( $clean['default_country_code'] ) && ! preg_match( '/^\+[1-9][0-9]{0,3}$/D',$clean['default_country_code'] ) ) self::fail();
		if ( $key === 'stp_settings' && isset( $clean['red_threshold'],$clean['yellow_threshold'] ) && $clean['yellow_threshold'] >= $clean['red_threshold'] ) self::fail();
		return serialize( $clean );
	}
	public static function destination( callable $query,string $prefix,array $spec, #[\SensitiveParameter] ?string $password = null ): array {
		$id = $spec['destinationOwnerId']; $rows = $query( 'SELECT ID,user_login,user_pass,user_nicename,user_email,user_url,user_registered,user_activation_key,user_status,display_name FROM '.self::id( $prefix.'users' ).' WHERE ID='.(string)$id );
		if ( count( $rows ) !== 1 || count( $rows[0] ) !== 10 || $rows[0][0] !== (string)$id || ! is_string( $rows[0][1] ) || $rows[0][1] === '' || ! filter_var( $rows[0][4],FILTER_VALIDATE_EMAIL ) || ( $password !== null && ! self::password( $password,$rows[0][2] ) ) ) self::fail();
		$meta = $query( 'SELECT meta_key,CAST(meta_value AS BINARY) FROM '.self::id( $prefix.'usermeta' ).' WHERE user_id='.(string)$id.' AND meta_key IN ('.self::q( $prefix.'capabilities' ).','.self::q( '_kiwe_account_suspended' ).') ORDER BY BINARY meta_key' );
		$caps = null; foreach ( $meta as $row ) { if ( $row[0] === '_kiwe_account_suspended' && $row[1] === '1' ) self::fail(); if ( $row[0] === $prefix.'capabilities' ) { if ( $caps !== null ) self::fail(); $caps = self::data( $row[1] ); } }
		$options = $query( 'SELECT option_name,CAST(option_value AS BINARY) FROM '.self::id( $prefix.'options' ).' WHERE option_name IN ('.self::q( $prefix.'user_roles' ).','.self::q( 'kiwe_access_policy_v1' ).') ORDER BY BINARY option_name' );
		$roles = null; $policy = [ 'enabled'=>false,'ownerId'=>0 ]; foreach ( $options as $row ) { if ( $row[0] === $prefix.'user_roles' ) { if ( $roles !== null ) self::fail(); $roles = self::data( $row[1] ); } else $policy = self::data( $row[1] ); }
		if ( ! is_array( $caps ) || ! is_array( $roles ) || empty( $roles['administrator']['capabilities']['manage_options'] ) || empty( $roles['administrator']['capabilities']['read'] ) || ! is_array( $roles['subscriber']['capabilities'] ?? null ) || empty( $roles['subscriber']['capabilities']['read'] ) ) self::fail();
		$ownerRole = ! empty( $policy['enabled'] ) ? 'kiwe_super_admin' : 'administrator';
		if ( ! empty( $policy['enabled'] ) && (int)( $policy['ownerId'] ?? 0 ) !== $id ) self::fail();
		if ( ( $caps[$ownerRole] ?? null ) !== true || ( $roles[$ownerRole]['capabilities']['manage_options'] ?? null ) !== true ) self::fail();
		return [ 'user'=>$rows[0],'roles'=>$roles,'ownerRole'=>$ownerRole,'policyEnabled'=>! empty( $policy['enabled'] ),'sha256'=>hash( 'sha256',self::json( [ $rows,$meta,$options ] ) ) ];
	}
	/** Called inside the existing database owner's transaction and lease, on its exact inactive prefix only. */
	public static function apply( callable $query,array $tables,string $prefix,string $live,array $spec,array $destination ): array {
		$by = []; foreach ( $tables as $t ) $by[substr( $t['name'],strlen( $prefix ) )] = $t;
		foreach ( [ 'users'=>[ 'ID','user_login','user_pass','user_nicename','user_email','user_url','user_registered','user_activation_key','user_status','display_name' ],'usermeta'=>[ 'umeta_id','user_id','meta_key','meta_value' ],'options'=>[ 'option_id','option_name','option_value','autoload' ] ] as $suffix=>$cols ) if ( ( $by[$suffix]['columns'] ?? null ) !== $cols || ( $by[$suffix]['primary'] ?? null ) !== [ $cols[0] ] ) self::fail();
		$users = self::id( $prefix.'users' ); $meta = self::id( $prefix.'usermeta' ); $options = self::id( $prefix.'options' );
		// Inspect authenticated native dependencies before copied options or tables are quarantined.
		$store = Portable_Store_Recovery::inspect( $query, $by, $prefix );
		$bound = $spec['sourceOwnerId']; $native = $destination['user'];
		$collisions = $query( 'SELECT ID,user_login,user_email FROM '.$users.' WHERE user_login='.self::q( $native[1] ).' OR user_email='.self::q( $native[4] ) );
		if ( $bound === null ) { if ( $collisions ) self::fail(); $max = $query( 'SELECT COALESCE(MAX(ID),0) FROM '.$users )[0][0] ?? null; if ( ! is_string( $max ) || ! ctype_digit( $max ) || strlen( $max ) > 15 ) self::fail(); $ownerId = (int)$max+1; }
		else { if ( count( $collisions ) !== 1 || $collisions[0] !== [ (string)$bound,$native[1],$native[4] ] ) self::fail(); $ownerId = $bound; }
		$report = [ 'schema'=>self::SCHEMA,'policy'=>$spec,'destinationOwnerSha256'=>$destination['sha256'],'ownerId'=>$ownerId,'ownerBoundToSource'=>$bound !== null,'sourceAccounts'=>(int)$query( 'SELECT COUNT(*) FROM '.$users )[0][0],'removedOptions'=>0,'removedMetadata'=>0,'quarantinedTables'=>[],'preservedReportingTables'=>[],'preservedRedirectTables'=>[] ];
		// Unknown custom tables have no activated rows. Original bytes, histories and schemas stay in the private stage.
		foreach ( $by as $suffix=>$t ) if ( ! in_array( $suffix,self::CORE,true ) ) {
			if ( Portable_Store_Recovery::preserves( $suffix, $store ) ) continue;
			if(self::reporting_table($suffix,$t)){
				$count=0;do{$rows=$query('SELECT * FROM '.self::id($t['name']).' ORDER BY '.implode(',',array_map([self::class,'id'],$t['primary'])).' LIMIT 100 OFFSET '.(string)$count);foreach($rows as$row)self::reporting_row($suffix,$row);$count+=count($rows);}while(count($rows)===100&&$count<$t['rows']);if($count!==$t['rows'])self::fail();
				$report['preservedReportingTables'][]=['suffix'=>$suffix,'rows'=>$count,'mode'=>'inactive-source-history','sourceIdentifiersMapped'=>false];continue;
			}
			if($suffix==='kiwe_seo_redirects_v1'){
				if($t['columns']!==self::redirect_columns()||$t['primary']!==['id']||!is_int($t['rows'])||$t['rows']<0||$t['rows']>20000)self::fail();$count=0;$kept=0;$table=self::id($t['name']);
				do{$rows=$query('SELECT * FROM '.$table.' ORDER BY id LIMIT 100 OFFSET '.(string)$count);foreach($rows as$row){$clean=self::redirect_row($row);if($clean[7]==='active'){++$kept;$query('UPDATE '.$table.' SET source_id=0,source_sha='.self::q($clean[9]).',imported_sha='.self::q($clean[10]).' WHERE id='.$clean[0]);}$count++;}}while(count($rows)===100&&$count<$t['rows']);if($count!==$t['rows'])self::fail();
				$query('DELETE FROM '.$table.' WHERE state='.self::q('pending'));if((int)$query('SELECT COUNT(*) FROM '.$table)[0][0]!==$kept)self::fail();$report['preservedRedirectTables'][]=['suffix'=>$suffix,'activeRules'=>$kept,'pendingRulesRetired'=>$count-$kept,'copiedImportAuthority'=>false];continue;
			}
			$report['quarantinedTables'][] = [ 'suffix'=>$suffix,'rows'=>$t['rows'] ]; $query( 'DELETE FROM '.self::id( $t['name'] ) );
		}
		// Source execution watermarks/readiness and provider jobs are deliberately absent. A fresh
		// destination enrollment must not replay these facts or call them new-destination traffic.
		foreach(['postmeta'=>'post_id','termmeta'=>'term_id']as$suffix=>$object){if(!isset($by[$suffix]))continue;$t=$by[$suffix];if($t['columns']!==['meta_id',$object,'meta_key','meta_value'])self::fail();$query('DELETE FROM '.self::id($t['name']).' WHERE meta_key IN ('.implode(',',array_map([self::class,'q'],['_kiwe_seo_migration_backup_v1','_kiwe_seo_migration_job_v1','_kiwe_schema_import_v1'])).')');}
		$rows = $query( 'SELECT option_id,option_name FROM '.$options.' ORDER BY option_id' ); if ( count( $rows ) > 10000 ) self::fail();
		$remove = [];
		foreach ( $rows as $row ) {
			self::number( $row[0] ); $key = $row[1];
			if ( Portable_Store_Recovery::option( $key, $store ) ) {
				$value = $query( 'SELECT CAST(option_value AS BINARY) FROM '.$options.' WHERE option_id='.$row[0] )[0][0];
				$query( 'UPDATE '.$options.' SET option_value='.self::q( Portable_Store_Recovery::clean_option( $key, $value, $store ) ).' WHERE option_id='.$row[0] ); continue;
			}
			if ( ! self::option( $key ) ) { $remove[] = $row[0]; ++$report['removedOptions']; continue; }
			if($key==='kiwe_seo_redirect_store_v1'){if(!isset($by['kiwe_seo_redirects_v1']))self::fail();$value=$query('SELECT CAST(option_value AS BINARY) FROM '.$options.' WHERE option_id='.$row[0])[0][0];$clean=self::redirect_settings($value);$query('UPDATE '.$options.' SET option_value='.self::q($clean).' WHERE option_id='.$row[0]);continue;}
			if ( $key === 'dsa_settings' || $key === 'bricks_settings' || $key === 'pk_settings_v3' || $key === 'stp_settings' || $key === 'kiwe_seo_defaults_v1' || str_starts_with( $key,'theme_mods_' ) ) { $value = $query( 'SELECT CAST(option_value AS BINARY) FROM '.$options.' WHERE option_id='.$row[0] )[0][0]; $clean = match ( $key ) { 'dsa_settings'=>self::settings( $value, $store['connected'] ), 'pk_settings_v3','stp_settings'=>self::configuration( $key,$value ), 'kiwe_seo_defaults_v1'=>self::seo_defaults($value), default=>serialize( self::scrub( self::data( $value ) ) ) }; $query( 'UPDATE '.$options.' SET option_value='.self::q( $clean ).' WHERE option_id='.$row[0] ); }
		}
		self::delete_ids( $query,$options,'option_id',$remove );
		$rows = $query( 'SELECT umeta_id,meta_key FROM '.$meta.' ORDER BY umeta_id' ); if ( count( $rows ) > 500000 ) self::fail();
		$remove = []; foreach ( $rows as $row ) { self::number( $row[0] ); if ( ! is_string( $row[1] ) || ! self::profile( $row[1] ) ) { $remove[] = $row[0]; ++$report['removedMetadata']; } } self::delete_ids( $query,$meta,'umeta_id',$remove );
		$query( 'UPDATE '.$users.' SET user_pass='.self::q( '!kiwe-quarantined-'.bin2hex( random_bytes( 24 ) ) ).',user_activation_key=\'\',user_status=0' );
		$native[0] = (string)$ownerId; $native[7] = ''; $native[8] = '0';
		if ( $bound !== null ) $query( 'DELETE FROM '.$users.' WHERE ID='.(string)$ownerId );
		$query( 'INSERT INTO '.$users.' (ID,user_login,user_pass,user_nicename,user_email,user_url,user_registered,user_activation_key,user_status,display_name) VALUES ('.implode( ',',array_map( [ self::class,'q' ],$native ) ).')' );
		foreach ( [ $prefix.'capabilities'=>serialize( [ 'subscriber'=>true ] ),$prefix.'user_level'=>'0','_kiwe_account_suspended'=>'1' ] as $key=>$value ) $query( 'INSERT INTO '.$meta.' (user_id,meta_key,meta_value) SELECT ID,'.self::q( $key ).','.self::q( $value ).' FROM '.$users.' WHERE ID<>'.(string)$ownerId );
		$query( 'INSERT INTO '.$meta.' (user_id,meta_key,meta_value) VALUES ('.(string)$ownerId.','.self::q( $prefix.'capabilities' ).','.self::q( serialize( [ $destination['ownerRole']=>true ] ) ).'),('.(string)$ownerId.','.self::q( $prefix.'user_level' ).',\'10\')' );
		$graft = [ $prefix.'user_roles'=>serialize( $destination['roles'] ),'kiwe_access_policy_v1'=>serialize( [ 'enabled'=>$destination['policyEnabled'],'ownerId'=>$ownerId ] ),'admin_email'=>$native[4],'active_plugins'=>serialize( [] ),'cron'=>serialize( [] ),'users_can_register'=>'0','default_role'=>'subscriber','kiwe_ai_opt_in_global_v1'=>'0' ];
		if ( $store['connected'] ) $graft['active_plugins'] = serialize( [ 'woocommerce/woocommerce.php' ] );
		$history = array_values( array_column( array_filter( $report['preservedReportingTables'], static fn( $t ) => $t['rows'] > 0 ), 'suffix' ) );
		if ( $history ) $graft['kiwe_restored_reporting_v1'] = serialize( [ 'schema'=>1,'recoverySha256'=>$spec['recoverySha256'],'tables'=>$history,'identityMapped'=>false ] );
		foreach ( $graft as $key=>$value ) { $query( 'DELETE FROM '.$options.' WHERE option_name='.self::q( $key ) ); $query( 'INSERT INTO '.$options.' (option_name,option_value,autoload) VALUES ('.self::q( $key ).','.self::q( $value ).',\'no\')' ); }
		$report['store'] = Portable_Store_Recovery::apply( $query, $by, $prefix, $store, $spec['recoverySha256'] );
		if ( self::destination( $query,$live,$spec )['sha256'] !== $destination['sha256'] ) self::fail();
		return $report;
	}
}
