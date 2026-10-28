<?php
namespace DSA\Admin;

use DSA\Access\Workspace_Admin_Service;
use DSA\Settings;

if ( ! defined( 'ABSPATH' ) ) exit;

/** Unique compatibility controls only. Client controls have one dashboard home. */
final class Legacy_Settings {
	public static function allowed(): bool {
		$user=wp_get_current_user();
		return current_user_can( 'manage_options' ) && Workspace_Admin_Service::uses_workspace($user) && Workspace_Admin_Service::uses_native_admin($user);
	}
	public static function fields( string $section ): array {
		$bool = static fn( $label ) => [ 'label'=>$label,'type'=>'bool' ];
		$number = static fn( $label,$min,$max ) => [ 'label'=>$label,'type'=>'int','min'=>$min,'max'=>$max ];
		$text = static fn( $label,$max=640 ) => [ 'label'=>$label,'type'=>'text','max'=>$max ];
		$choice = static fn( $label,$options ) => [ 'label'=>$label,'type'=>'choice','options'=>$options ];
		$fields = match( $section ) {
			'ai' => \DSA\Apps\AI_App_Service::enabled() ? [
				'ai.memory_enabled'=>$bool('Keep optional AI conversation memory'),
				'ai.memory_retention_days'=>$number('Conversation memory retention (days)',7,365),
			] : [],
			'permissions' => [
				'permissions.cooldown_hours'=>$number('Permission invitation cooldown (hours)',1,720),
				'permissions.retention_days'=>$number('Permission history retention (days)',1,90),
				'permissions.max_asks_per_session'=>$number('Maximum invitations per session',1,5),
				'permissions.pwa_title'=>$text('Install explanation title',160),
				'permissions.pwa_message'=>$text('Install explanation message'),
				'permissions.pwa_min_home_views'=>$number('Automatic install invitation: Home views',0,20),
				'permissions.pwa_min_dock_opens'=>$number('Automatic install invitation: Dock opens',0,20),
				'permissions.pwa_min_transition_completes'=>$number('Automatic install invitation: transitions',0,50),
				'permissions.pwa_min_game_completes'=>$number('Automatic install invitation: completed games',0,20),
				'permissions.notification_order_prompt_enabled'=>$bool('Offer order-status notifications after checkout'),
				'permissions.notifications_title'=>$text('Browser notification explanation title',160),
				'permissions.notifications_message'=>$text('Browser notification explanation message'),
				'permissions.notification_cta_label'=>$text('Unavailable-product notification button',160),
				'permissions.notification_cta_color'=>$choice('Notification button colour',['active','hover']),
				'permissions.ai_popup_duration_ms'=>$number('Notification popup duration (milliseconds)',2000,15000),
			],
			'dock' => [
				'dock.custom_items'=>['label'=>'Custom Dock shortcuts','type'=>'shortcuts'],
				'dock.context_rail_enabled'=>$bool('Show the contextual action rail'),
			],
			'presentation' => [
				'enabled'=>$bool('Render the public AppShell'),
				'style.screen_heading_tag'=>$choice('DSA heading element',['h1','h2','h3','h4','p','span']),
				'visual_effects.blur_type'=>$choice('Classic-screen background effect',['none','gaussian','frosted','dim']),
				'visual_effects.blur_strength'=>$number('Classic-screen blur strength',0,24),
				'visual_effects.glass_intensity'=>$choice('Classic-screen glass intensity',['low','medium','high']),
				'visual_effects.screen_material'=>$choice('Classic-screen background',['glass','solid']),
				'visual_effects.screen_animation'=>$choice('Classic-screen entry direction',['bottom','top','left','right']),
			],
			'links' => [
				'link_hub.site_score'=>['label'=>'Legacy public site score (blank uses the default)','type'=>'optional-int','min'=>0,'max'=>100],
				'link_hub.shop_label'=>$text('Links screen shop label',160),
				'link_hub.shop_url'=>['label'=>'Links screen shop URL','type'=>'url'],
				'link_hub.posts_title'=>$text('Links screen article-rail title',160),
				'link_hub.posts_category'=>$number('Links screen article category ID (0 selects automatically)',0,2147483647),
				'link_hub.ssl_provider'=>$text('Legacy certificate-provider label',160),
				'link_hub.payment_provider'=>$text('Legacy payment-provider label',160),
				'link_hub.review_source'=>$choice('Links screen review source',['manual','google']),
				'link_hub.google_place_id'=>$text('Existing Google Places ID',240),
				'link_hub.google_api_key'=>['label'=>'Existing Google Places key (blank retains it)','type'=>'secret','max'=>640],
				'link_hub.testimonials'=>['label'=>'Legacy Links-screen testimonial text','type'=>'textarea','max'=>12000],
			],
			'menu' => [
				'dock.menu_label'=>$text('Legacy fallback menu title (without native menu groups)',160),
				'dock.menu_items'=>['label'=>'Legacy fallback menu links','type'=>'menu-links'],
				'dock.menu_heading_tag'=>$choice('Menu heading element',['h1','h2','h3','h4','p','span']),
				'dock.menu_scale'=>$choice('Menu type scale',['compact','balanced','expressive']),
				'dock.menu_context_heading_levels'=>['label'=>'Page-guide heading levels','type'=>'heading-levels'],
				'dock.menu_context_page_ids'=>['label'=>'Legacy page-guide page IDs','type'=>'ids'],
				'dock.menu_context_locations'=>['label'=>'Legacy page-guide locations','type'=>'locations'],
			],
			'store' => \DSA\Apps\Store_App_Service::enabled() ? [
				'commerce.co_purchase_daily_sync_enabled'=>$bool('Daily co-purchase maintenance'),
				'commerce.co_purchase_daily_sync_depth'=>$number('Co-purchase maintenance depth',1,50),
				'commerce.co_purchase_daily_sync_mode'=>$choice('Co-purchase maintenance mode',['merge','replace']),
				'commerce.bestseller_enabled'=>$bool('Maintain bestseller collections'),
				'commerce.bestseller_limit'=>$number('Maximum bestseller membership',3,100),
				'commerce.bestseller_parent_label'=>$text('Bestseller parent category label',160),
				'commerce.bestseller_parent_slug'=>['label'=>'Bestseller parent category slug','type'=>'slug','max'=>160],
				'commerce.bestseller_sync_on_order'=>$bool('Schedule bestseller maintenance after an order'),
				'protected_flow.rail_enabled'=>$bool('Protected-flow action rail'),
			] : [],
			'reminder-delivery' => \DSA\Apps\Store_App_Service::enabled() ? self::delivery_fields($bool,$number,$text) : [],
			default => [],
		};
		if(!\DSA\Apps\Store_App_Service::enabled())foreach(['permissions.notification_order_prompt_enabled','permissions.notification_cta_label','permissions.notification_cta_color','link_hub.shop_label','link_hub.shop_url','link_hub.payment_provider'] as $path)unset($fields[$path]);
		return $fields;
	}
	private static function delivery_fields($bool,$number,$text): array {
		$out=['abandoned_cart.heartbeat_minutes'=>$number('Unchanged-cart heartbeat (minutes)',1,60),'abandoned_cart.automation_batch_limit'=>$number('Maximum reminders per worker batch',1,250),'abandoned_cart.sms_message'=>['label'=>'Legacy SMS reminder template','type'=>'textarea','max'=>4000]];
		foreach(['sms'=>'SMS','whatsapp'=>'WhatsApp'] as $id=>$label){$prefix='abandoned_cart.channels.'.$id.'.';$out[$prefix.'enabled']=$bool($label.' legacy webhook adapter');$out[$prefix.'webhook_url']=['label'=>$label.' legacy webhook URL','type'=>'webhook'];$out[$prefix.'api_token']=['label'=>$label.' legacy token (blank retains it)','type'=>'encrypted-secret','max'=>4096];$out[$prefix.'sender']=$text($label.' sender',160);}
		return $out;
	}
	public static function value( array $all,string $path ) {
		foreach ( explode('.',$path) as $part ) { if ( ! is_array($all) || ! array_key_exists($part,$all) ) return null; $all=$all[$part]; }
		return $all;
	}
	public static function expected( Settings $settings ): string {
		return hash_hmac('sha256',serialize(get_option(DSA_OPTION_SETTINGS,null)),wp_salt('nonce'));
	}
	public static function prepare( string $section,array $values,Settings $settings ) {
		$catalog=self::fields($section); if(!$catalog||array_diff(array_keys($values),array_keys($catalog)))return new \WP_Error('kiwe_technical_fields','Choose an available technical control.');
		$patch=[];$all=$settings->all();
		foreach($values as $path=>$value){
			$f=$catalog[$path];$type=$f['type'];$valid=false;
			if('bool'===$type){$valid=in_array($value,['0','1'],true);$value='1'===$value;}
			elseif(in_array($type,['int','optional-int'],true)){$valid='optional-int'===$type&&''===$value||is_string($value)&&preg_match('/^\d{1,10}$/D',$value)&&($value+0)>=$f['min']&&($value+0)<=$f['max'];if($valid&&''!==$value)$value=(int)$value;}
			elseif('choice'===$type)$valid=is_string($value)&&in_array($value,$f['options'],true);
			elseif(in_array($type,['text','textarea','secret','encrypted-secret','slug'],true)){$valid=is_string($value)&&strlen($value)<=$f['max'];if($valid){if(in_array($type,['secret','encrypted-secret'],true)&&''===trim($value))continue;$value='textarea'===$type?sanitize_textarea_field($value):('slug'===$type?sanitize_title($value):sanitize_text_field($value));if('encrypted-secret'===$type){$value=\DSA\Security\Secret_Store::encrypt($value);if(''===$value)return new \WP_Error('kiwe_technical_secret','The token could not be protected. No changes were saved.');}}}
			elseif('url'===$type){$parts=is_string($value)?wp_parse_url($value):false;$valid=is_string($value)&&strlen($value)<=1200&&(''===$value||is_array($parts)&&!empty($parts['host'])&&!isset($parts['user'])&&!isset($parts['pass'])&&in_array($parts['scheme']??'',['https','http'],true));if($valid)$value=esc_url_raw($value);}
			elseif('webhook'===$type){$valid=is_string($value)&&strlen($value)<=1200&&(''===$value||wp_http_validate_url($value));if($valid)$value=esc_url_raw($value);}
			elseif(in_array($type,['ids','heading-levels'],true)){$valid=is_string($value)&&strlen($value)<=2000;$list=$valid?preg_split('/[\s,]+/',trim($value),-1,PREG_SPLIT_NO_EMPTY):[];$valid=$valid&&count($list)<=100;foreach($list as $n)if('ids'===$type?!ctype_digit($n)||($n+0)<1||($n+0)>2147483647:!in_array($n,['h1','h2','h3','h4','h5','h6'],true))$valid=false;if($valid)$value=array_values(array_unique('ids'===$type?array_map('intval',$list):$list));}
			elseif(in_array($type,['shortcuts','locations','menu-links'],true)){
				$decoded=is_string($value)&&strlen($value)<=16000?json_decode($value,true):null;$valid=is_array($decoded);
				if($valid&&'menu-links'===$type){$valid=array_is_list($decoded)&&count($decoded)<=20;foreach($decoded as $item){if(!is_array($item)||array_diff(array_keys($item),['title','url','type','image','object_id','object_type'])||!is_string($item['title']??null)||strlen($item['title'])>160||!is_string($item['url']??null)||strlen($item['url'])>1200||!is_string($item['type']??'')||!is_string($item['image']??'')||!is_int($item['object_id']??0)||($item['object_id']??0)<0||!is_string($item['object_type']??'')){$valid=false;break;}}if($valid){$normalized=(new \DSA\Dock\Dock_Settings_Service([]))->sanitize_menu_items($decoded);if(count($normalized)!==count($decoded))$valid=false;else foreach($normalized as $i=>$item)if($item['url']!==$decoded[$i]['url'])$valid=false;$decoded=$normalized;}}
				elseif($valid&&'shortcuts'===$type){$valid=array_is_list($decoded)&&count($decoded)<=12;foreach($decoded as $item){if(!is_array($item)||array_diff(array_keys($item),['id','enabled','label','url','icon'])||!is_string($item['id']??null)||!preg_match('/^link-[a-z0-9_-]{1,80}$/D',$item['id'])||!is_bool($item['enabled']??null)||!is_string($item['label']??null)||strlen($item['label'])>160||sanitize_text_field($item['label'])!==$item['label']||!is_string($item['url']??null)||strlen($item['url'])>1200||!is_string($item['icon']??null)||strlen($item['icon'])>64||sanitize_key($item['icon'])!==$item['icon']){$valid=false;break;}$url=$item['url'];$parts=wp_parse_url($url);if(''===$url||!is_array($parts)||isset($parts['user'])||isset($parts['pass'])||!in_array($parts['scheme']??'',['https','http','mailto','tel'],true))$valid=false;}if(count(array_unique(array_column($decoded,'id')))!==count($decoded))$valid=false;}
				elseif($valid){$allowed=['everywhere','single_post','front_page','selected_pages'];if(\DSA\Apps\Store_App_Service::enabled())$allowed[]='single_product';$valid=!array_diff(array_keys($decoded),$allowed);foreach($decoded as $on)if(!is_bool($on))$valid=false;}
				if($valid)$value=$decoded;
			}
			if(!$valid)return new \WP_Error('kiwe_technical_value','A technical choice is invalid. Review it before saving.');
			if($value===self::value($all,$path))continue;
			$parts=explode('.',$path);$target=&$patch;foreach($parts as $i=>$part){if($i===count($parts)-1)$target[$part]=$value;else{if(!isset($target[$part]))$target[$part]=[];$target=&$target[$part];}}unset($target);
		}
		return $patch;
	}
}
