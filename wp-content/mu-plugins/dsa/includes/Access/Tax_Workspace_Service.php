<?php
namespace DSA\Access;
if(!defined('ABSPATH'))exit;

/** Private native tax options and bounded rate review; no public work or parallel store. */
final class Tax_Workspace_Service {
	private const OPTIONS=[
		'enabled'=>['woocommerce_calc_taxes','no',['yes','no']],
		'entered'=>['woocommerce_prices_include_tax','no',['yes','no']],
		'address'=>['woocommerce_tax_based_on','shipping',['shipping','billing','base']],
		'shippingClass'=>['woocommerce_shipping_tax_class','inherit',[]],
		'rounding'=>['woocommerce_tax_round_at_subtotal','no',['yes','no']],
		'shopDisplay'=>['woocommerce_tax_display_shop','excl',['incl','excl']],
		'cartDisplay'=>['woocommerce_tax_display_cart','excl',['incl','excl']],
		'suffix'=>['woocommerce_price_display_suffix','',[]],
		'totals'=>['woocommerce_tax_total_display','itemized',['single','itemized']],
	];
	private function allowed(): bool {return class_exists('WC_Tax')&&(current_user_can('edit_products')||(current_user_can('edit_shop_orders')&&current_user_can('edit_others_shop_orders')));}
	private function editable(): bool {return current_user_can('edit_products')&&current_user_can('kiwe_manage_context')&&$this->provider_disposition()['mode']==='native';}
	/** Read only ownership signals; never instantiate, contact or configure a tax provider. */
	public function provider_disposition(): array {
		if(class_exists('WC_Connect_TaxJar_Integration',false)&&get_option('wc_connect_taxes_enabled','no')==='yes')return ['mode'=>'external','label'=>'WooCommerce Tax automation'];
		if(class_exists('WC_Taxjar',false))return ['mode'=>'external','label'=>'TaxJar integration'];
		global $wp_filter;
		foreach(['woocommerce_calc_tax','woocommerce_matched_tax_rates','woocommerce_find_rates','woocommerce_tax_rate','wc_tax_enabled','woocommerce_tax_enabled','woocommerce_prices_include_tax','pre_option_woocommerce_calc_taxes','pre_option_woocommerce_prices_include_tax']as$hook)if(!empty($wp_filter[$hook]->callbacks))return ['mode'=>'external','label'=>'Custom tax integration'];
		return ['mode'=>'native','label'=>'WooCommerce saved settings and rates'];
	}
	private function error(string $code,string $message,int $status=400){return new \WP_Error('kiwe_tax_'.$code,$message,['status'=>$status]);}
	private function rows(string $sql){global $wpdb;$r=$wpdb->get_results($sql,ARRAY_A);return $wpdb->last_error!==''||!is_array($r)?$this->error('read','Tax setup could not be read. Refresh and review.',409):$r;}
	private function suffix_editable(string $v): bool {return strlen($v)<=240&&sanitize_text_field($v)===$v&&preg_match('/[{}]/',str_replace(['{price_including_tax}','{price_excluding_tax}'],'',$v))===0;}
	public function settings(){
		if(!$this->allowed())return $this->error('forbidden','Tax setup is unavailable for your account.',403);global $wpdb;
		$classes=$this->rows("SELECT tax_rate_class_id, name, slug FROM {$wpdb->prefix}wc_tax_rate_classes ORDER BY tax_rate_class_id ASC LIMIT 101");if(is_wp_error($classes))return $classes;
		$choices=[['value'=>'inherit','label'=>'Based on basket items'],['value'=>'','label'=>'Standard']];foreach(array_slice($classes,0,100)as$c)$choices[]=['value'=>(string)$c['slug'],'label'=>Task_Workspace_UI::text($c['name'],120)];
		$values=[];$editable=[];$raw=[];foreach(self::OPTIONS as$key=>[$option,$default,$allowed]){$raw[$key]=get_option($option,null);$value=$raw[$key]??$default;if(!is_string($value)||strlen($value)>1000)return $this->error('settings','Custom tax values need your site designer.',409);$values[$key]=$value;$editable[$key]=$key==='suffix'?$this->suffix_editable($value):in_array($value,$key==='shippingClass'?array_column($choices,'value'):$allowed,true);}
		$provider=$this->provider_disposition();$native=$provider['mode']==='native';return ['values'=>$values,'editable'=>$editable,'classes'=>$choices,'large'=>count($classes)>100,'provider'=>$provider,'canEdit'=>$this->editable(),'effectiveKnown'=>$native,'effectiveEnabled'=>$native?(bool)wc_tax_enabled():$values['enabled']==='yes','expected'=>hash_hmac('sha256',serialize([$raw,$classes,$provider]),wp_salt('nonce'))];
	}
	public function save(array $input){
		if(!$this->allowed()||!$this->editable())return $this->error('forbidden','Your account cannot change store-wide tax setup.',403);
		if(array_diff(array_keys($input),['key','value','expected','acceptEffect'])||!is_string($input['key']??null)||!isset(self::OPTIONS[$input['key']])||!is_string($input['value']??null)||!is_string($input['expected']??null)||($input['acceptEffect']??false)!==true)return $this->error('input','Review and confirm one tax setting.');
		global $wpdb;$lock='kiwe-tax-'.substr(hash('sha256',$wpdb->prefix),0,32);if('1'!==(string)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 0)',$lock)))return $this->error('busy','Another tax change is running. Refresh and review.',409);
		try{
			$before=$this->settings();if(is_wp_error($before))return $before;if(!hash_equals($before['expected'],$input['expected']))return $this->error('stale','Tax settings or classes changed. Refresh and review.',409);
			$key=$input['key'];$value=$input['value'];[$option,$default,$allowed]=self::OPTIONS[$key];if($key==='shippingClass')$allowed=array_column($before['classes'],'value');
			if(!$before['editable'][$key]||($key==='shippingClass'&&$before['large'])||($key==='suffix'?!$this->suffix_editable($value):!in_array($value,$allowed,true)))return $this->error('input','Choose a displayed value. Existing custom settings need your designer.');
			if($value===$before['values'][$key])return $before;
			if(!class_exists('WC_Admin_Settings'))require_once WC_ABSPATH.'includes/admin/class-wc-admin-settings.php';
			$field=['id'=>$option,'default'=>$default,'type'=>$key==='suffix'?'text':'select','options'=>array_fill_keys($allowed,'' )];if($key==='totals')$field['autoload']=false;
			\WC_Admin_Settings::save_fields([$field],[$option=>wp_slash($value)]);
			\WC_Cache_Helper::invalidate_cache_group('taxes');\WC_Cache_Helper::get_transient_version('shipping',true);
			$after=$this->settings();if(is_wp_error($after))return $after;$wanted=array_replace($before['values'],[$key=>$value]);if($after['values']!==$wanted||$after['classes']!==$before['classes'])return $this->error('readback','Saved tax settings differ. Refresh and review before retrying.',409);return $after;
		}catch(\Throwable $e){return $this->error('write','Tax saving could not be confirmed. Refresh and review.',409);}finally{$wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock));}
	}
	/** Bounded native tax-class inventory with deletion dependencies. */
	public function classes(){
		if(!$this->allowed())return $this->error('forbidden','Tax classes are unavailable for your account.',403);global $wpdb;
		$classes=$this->rows("SELECT tax_rate_class_id, name, slug FROM {$wpdb->prefix}wc_tax_rate_classes ORDER BY tax_rate_class_id ASC LIMIT 101");if(is_wp_error($classes))return $classes;
		$rateUse=$this->rows("SELECT c.slug, COUNT(r.tax_rate_id) AS used FROM {$wpdb->prefix}wc_tax_rate_classes c LEFT JOIN {$wpdb->prefix}woocommerce_tax_rates r ON r.tax_rate_class = c.slug GROUP BY c.slug ORDER BY c.slug ASC LIMIT 101");if(is_wp_error($rateUse))return $rateUse;
		$productUse=$this->rows("SELECT c.slug, COUNT(DISTINCT p.ID) AS used FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id AND p.post_type IN ('product','product_variation') INNER JOIN {$wpdb->prefix}wc_tax_rate_classes c ON c.slug = pm.meta_value WHERE pm.meta_key = '_tax_class' GROUP BY c.slug ORDER BY c.slug ASC LIMIT 101");if(is_wp_error($productUse))return $productUse;
		$rates=[];foreach($rateUse as$row)$rates[(string)$row['slug']]=(int)$row['used'];$products=[];foreach($productUse as$row)$products[(string)$row['slug']]=(int)$row['used'];$shipping=get_option('woocommerce_shipping_tax_class','inherit');if(!is_string($shipping)||strlen($shipping)>200)return $this->error('classes','A custom shipping-tax class needs your designer.',409);
		$large=count($classes)>100||count($rateUse)>100||count($productUse)>100;$canEdit=$this->editable()&&!$large;$items=[];
		foreach(array_slice($classes,0,100)as$row){$slug=(string)$row['slug'];$rateCount=$rates[$slug]??0;$productCount=$products[$slug]??0;$usedForShipping=$shipping===$slug;$items[]=['id'=>(int)$row['tax_rate_class_id'],'name'=>Task_Workspace_UI::text($row['name'],120),'slug'=>Task_Workspace_UI::text($slug,200),'rateCount'=>$rateCount,'productCount'=>$productCount,'usedForShipping'=>$usedForShipping,'canDelete'=>$canEdit&&$rateCount===0&&$productCount===0&&!$usedForShipping];}
		return ['items'=>$items,'large'=>$large,'canEdit'=>$canEdit,'expected'=>hash_hmac('sha256',serialize([$classes,$rateUse,$productUse,$shipping]),wp_salt('nonce'))];
	}
	private function begin_class_change(): bool {
		global $wpdb;$names=[$wpdb->prefix.'wc_tax_rate_classes',$wpdb->prefix.'woocommerce_tax_rates',$wpdb->prefix.'woocommerce_tax_rate_locations',$wpdb->postmeta,$wpdb->posts];$engines=$this->rows($wpdb->prepare('SELECT TABLE_NAME, ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN (%s,%s,%s,%s,%s)',...$names));if(is_wp_error($engines)||count($engines)!==5)return false;foreach($engines as$row)if(strtoupper((string)$row['ENGINE'])!=='INNODB')return false;
		return $wpdb->query('SET TRANSACTION ISOLATION LEVEL READ COMMITTED')!==false&&$wpdb->query('START TRANSACTION')!==false;
	}
	public function create_class(array $input){
		if(!$this->allowed()||!$this->editable())return $this->error('forbidden','Your account cannot create store-wide tax classes.',403);
		if(array_diff(array_keys($input),['name','expected','acceptEffect'])||!is_string($input['name']??null)||!is_string($input['expected']??null)||($input['acceptEffect']??false)!==true)return $this->error('class_input','Review and confirm the new tax class.');
		$name=trim($input['name']);$slug=sanitize_title($name);if($name===''||$name==='0'||strlen($name)>120||sanitize_text_field($name)!==$name||$slug===''||in_array($slug,['standard','inherit'],true))return $this->error('class_input','Enter a plain tax-class name of up to 120 characters.');
		global $wpdb;$lock='kiwe-tax-'.substr(hash('sha256',$wpdb->prefix),0,32);if('1'!==(string)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 0)',$lock)))return $this->error('busy','Another tax change is running. Refresh and review.',409);$transaction=false;$touched=false;
		try{
			$before=$this->classes();if(is_wp_error($before))return $before;if(!hash_equals($before['expected'],$input['expected']))return $this->error('stale','Tax classes or their use changed. Refresh and review.',409);if(!$before['canEdit'])return $this->error('protected','Your designer manages this large tax-class catalog.',409);
			foreach($before['items']as$item)if(strcasecmp($item['name'],$name)===0||$item['slug']===$slug)return $this->error('duplicate','A tax class already uses that name or address.',409);
			$transaction=$this->begin_class_change();if(!$transaction)return $this->error('transaction','Safe tax-class changes need native InnoDB tables and an available transaction.',409);$touched=true;
			$created=\WC_Tax::create_tax_class($name,$slug);if(is_wp_error($created)||!is_array($created)||($created['slug']??'')!==$slug||$wpdb->last_error!=='')throw new \RuntimeException('create');
			$after=$this->classes();if(is_wp_error($after))throw new \RuntimeException('readback');$new=array_values(array_filter($after['items'],static fn($item)=>$item['slug']===$slug));$old=array_column($before['items'],null,'slug');$current=array_column($after['items'],null,'slug');unset($current[$slug]);
			if(count($new)!==1||$new[0]['name']!==$name||$new[0]['rateCount']!==0||$new[0]['productCount']!==0||$current!==$old)throw new \RuntimeException('preservation');
			if($wpdb->query('COMMIT')===false)throw new \RuntimeException('commit');$transaction=false;return $after;
		}catch(\Throwable $e){return $this->error('class_write','Tax-class creation could not be confirmed. Refresh before retrying.',409);}finally{if($transaction)$wpdb->query('ROLLBACK');if($touched){wp_cache_delete('tax-rate-classes','taxes');\WC_Cache_Helper::invalidate_cache_group('taxes');\WC_Cache_Helper::get_transient_version('shipping',true);}$wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock));}
	}
	public function delete_class(string $slug,array $input){
		if(!$this->allowed()||!$this->editable())return $this->error('forbidden','Your account cannot delete store-wide tax classes.',403);
		if($slug===''||sanitize_title($slug)!==$slug||array_diff(array_keys($input),['confirmName','expected','acceptEffect'])||!is_string($input['confirmName']??null)||!is_string($input['expected']??null)||($input['acceptEffect']??false)!==true)return $this->error('class_input','Review and confirm the tax class to delete.');
		global $wpdb;$lock='kiwe-tax-'.substr(hash('sha256',$wpdb->prefix),0,32);if('1'!==(string)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 0)',$lock)))return $this->error('busy','Another tax change is running. Refresh and review.',409);$transaction=false;$touched=false;
		try{
			$before=$this->classes();if(is_wp_error($before))return $before;if(!hash_equals($before['expected'],$input['expected']))return $this->error('stale','Tax classes or their use changed. Refresh and review.',409);$matches=array_values(array_filter($before['items'],static fn($item)=>$item['slug']===$slug));if(count($matches)!==1)return $this->error('missing','This tax class no longer exists.',404);$class=$matches[0];if(!hash_equals($class['name'],$input['confirmName']))return $this->error('confirm','Confirm the displayed tax-class name.');if(!$class['canDelete'])return $this->error('in_use','Remove its product assignments, saved rates and shipping-tax use before deleting this class.',409);
			$transaction=$this->begin_class_change();if(!$transaction)return $this->error('transaction','Safe tax-class changes need native InnoDB tables and an available transaction.',409);$touched=true;
			// Woo's native class deletion also removes orphaned location rows globally.
			$orphans=$this->rows("SELECT l.location_id FROM {$wpdb->prefix}woocommerce_tax_rate_locations l LEFT JOIN {$wpdb->prefix}woocommerce_tax_rates r ON r.tax_rate_id = l.tax_rate_id WHERE r.tax_rate_id IS NULL LIMIT 1");
			if(is_wp_error($orphans)||$orphans)return $this->error('orphan_locations','An unrelated orphan tax location needs designer review before class deletion.',409);
			$deleted=\WC_Tax::delete_tax_class_by('slug',$slug);if(is_wp_error($deleted)||$deleted!==true||$wpdb->last_error!=='')throw new \RuntimeException('delete');
			$after=$this->classes();if(is_wp_error($after))throw new \RuntimeException('readback');$old=array_column($before['items'],null,'slug');unset($old[$slug]);$current=array_column($after['items'],null,'slug');if($current!==$old)throw new \RuntimeException('preservation');
			$remainingRates=$this->rows($wpdb->prepare("SELECT tax_rate_id FROM {$wpdb->prefix}woocommerce_tax_rates WHERE tax_rate_class = %s LIMIT 1",$slug));$remainingProducts=$this->rows($wpdb->prepare("SELECT pm.post_id FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE pm.meta_key = '_tax_class' AND pm.meta_value = %s AND p.post_type IN ('product','product_variation') LIMIT 1",$slug));if(is_wp_error($remainingRates)||is_wp_error($remainingProducts)||$remainingRates||$remainingProducts)throw new \RuntimeException('dependency');
			if($wpdb->query('COMMIT')===false)throw new \RuntimeException('commit');$transaction=false;return $after;
		}catch(\Throwable $e){return $this->error('class_delete','Tax-class deletion could not be confirmed. Refresh before retrying.',409);}finally{if($transaction)$wpdb->query('ROLLBACK');if($touched){wp_cache_delete('tax-rate-classes','taxes');\WC_Cache_Helper::invalidate_cache_group('taxes');\WC_Cache_Helper::get_transient_version('shipping',true);}$wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock));}
	}
	public function rates(array $input=[]){
		if(!$this->allowed())return $this->error('forbidden','Tax rates are unavailable for your account.',403);$settings=$this->settings();if(is_wp_error($settings))return $settings;
		$class=$input['class']??'';if(!is_string($class)||$class==='inherit'||!in_array($class,array_column($settings['classes'],'value'),true))return $this->error('class','Choose a listed tax class.');$page=max(1,min(1000,(int)($input['page']??1)));global $wpdb;
		$rows=$this->rows($wpdb->prepare("SELECT tax_rate_id, tax_rate_country, tax_rate_state, tax_rate, tax_rate_name, tax_rate_priority, tax_rate_compound, tax_rate_shipping, tax_rate_order FROM {$wpdb->prefix}woocommerce_tax_rates WHERE tax_rate_class = %s ORDER BY tax_rate_order ASC, tax_rate_id ASC LIMIT 21 OFFSET %d",$class,($page-1)*20));if(is_wp_error($rows))return $rows;
		$items=[];foreach(array_slice($rows,0,20)as$r)$items[]=['id'=>(int)$r['tax_rate_id'],'name'=>Task_Workspace_UI::text($r['tax_rate_name'],240),'rate'=>Task_Workspace_UI::text($r['tax_rate'],30),'country'=>Task_Workspace_UI::text($r['tax_rate_country'],10),'state'=>Task_Workspace_UI::text($r['tax_rate_state'],200),'priority'=>(int)$r['tax_rate_priority'],'compound'=>(bool)$r['tax_rate_compound'],'shipping'=>(bool)$r['tax_rate_shipping'],'order'=>(int)$r['tax_rate_order']];
		return ['items'=>$items,'page'=>$page,'hasNext'=>count($rows)>20,'class'=>$class,'classes'=>array_values(array_filter($settings['classes'],static fn($v)=>$v['value']!=='inherit')),'large'=>$settings['large']];
	}
	/** One native rate; bounded rule snapshot prevents silently changing a stale scope. */
	public function rate(int $id){
		if(!$this->allowed())return $this->error('forbidden','Tax rates are unavailable for your account.',403);global $wpdb;
		$settings=$this->settings();if(is_wp_error($settings))return $settings;
		$rows=$this->rows($wpdb->prepare("SELECT tax_rate_id, tax_rate_country, tax_rate_state, tax_rate, tax_rate_name, tax_rate_priority, tax_rate_compound, tax_rate_shipping, tax_rate_order, tax_rate_class FROM {$wpdb->prefix}woocommerce_tax_rates WHERE tax_rate_id = %d LIMIT 1",$id));if(is_wp_error($rows))return $rows;if(!$rows)return $this->error('missing','This tax rate no longer exists.',404);$row=$rows[0];
		$locations=$this->rows($wpdb->prepare("SELECT location_id, location_code, location_type FROM {$wpdb->prefix}woocommerce_tax_rate_locations WHERE tax_rate_id = %d ORDER BY location_id ASC LIMIT 201",$id));if(is_wp_error($locations))return $locations;
		$values=['name'=>(string)$row['tax_rate_name'],'rate'=>(string)$row['tax_rate'],'priority'=>(int)$row['tax_rate_priority'],'order'=>(int)$row['tax_rate_order'],'compound'=>(bool)$row['tax_rate_compound'],'shipping'=>(bool)$row['tax_rate_shipping']];
		$custom=false;foreach($values as$key=>$value)if(is_wp_error($this->rate_value($key,$value)))$custom=true;
		if(!in_array((string)$row['tax_rate_compound'],['0','1'],true)||!in_array((string)$row['tax_rate_shipping'],['0','1'],true))$custom=true;
		return ['id'=>$id,'values'=>$values,'scope'=>['country'=>Task_Workspace_UI::text($row['tax_rate_country'],10),'state'=>Task_Workspace_UI::text($row['tax_rate_state'],200),'class'=>Task_Workspace_UI::text($row['tax_rate_class'],200),'locationCount'=>min(200,count($locations))],'large'=>count($locations)>200,'custom'=>$custom,'canEdit'=>$this->editable()&&!$custom&&count($locations)<=200,'scopeExpected'=>hash_hmac('sha256',serialize([$row['tax_rate_country'],$row['tax_rate_state'],$row['tax_rate_class'],$locations,$settings['expected']]),wp_salt('nonce')),'expected'=>hash_hmac('sha256',serialize([$row,$locations,$settings['expected']]),wp_salt('nonce'))];
	}
	private function rate_value(string $key,$value){
		$valid=match($key){
			'name'=>is_string($value)&&$value!==''&&$value!=='0'&&strlen($value)<=200&&sanitize_text_field($value)===$value,
			'rate'=>is_string($value)&&preg_match('/^(?:0|[1-9][0-9]{0,2})(?:\.[0-9]{1,4})?$/D',$value)&&((float)$value)<=100,
			'priority'=>is_int($value)&&$value>=1&&$value<=10000,
			'order'=>is_int($value)&&$value>=0&&$value<=1000000,
			'compound','shipping'=>is_bool($value),
			default=>false,
		};
		if(!$valid)return $this->error('rate_input','Choose a valid rate value. Use up to four decimal places for percentages.');
		return $key==='rate'?number_format((float)$value,4,'.',''):$value;
	}
	public function save_rate(int $id,array $input){
		if(!$this->allowed()||!$this->editable())return $this->error('forbidden','Your account cannot change store-wide tax rates.',403);
		if(array_diff(array_keys($input),['key','value','expected','acceptEffect'])||!is_string($input['key']??null)||!array_key_exists('value',$input)||!is_string($input['expected']??null)||($input['acceptEffect']??false)!==true)return $this->error('rate_input','Review and confirm one rate change.');
		$key=$input['key'];$value=$this->rate_value($key,$input['value']);if(is_wp_error($value))return $value;
		global $wpdb;$lock='kiwe-tax-'.substr(hash('sha256',$wpdb->prefix),0,32);if('1'!==(string)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 0)',$lock)))return $this->error('busy','Another tax change is running. Refresh and review.',409);
		try{
			$before=$this->rate($id);if(is_wp_error($before))return $before;if(!hash_equals($before['expected'],$input['expected']))return $this->error('stale','The tax rate, its rules or policy changed. Refresh and review.',409);if(!$before['canEdit'])return $this->error('protected','This rate needs your designer to review its custom values or large rule set.',409);
			if($before['values'][$key]===$value)return $before;
			$columns=['name'=>'tax_rate_name','rate'=>'tax_rate','priority'=>'tax_rate_priority','order'=>'tax_rate_order','compound'=>'tax_rate_compound','shipping'=>'tax_rate_shipping'];
			// Woo's native writer updates only supplied columns and invokes its rate hooks.
			\WC_Tax::_update_tax_rate($id,[$columns[$key]=>is_bool($value)?(int)$value:$value]);
			$writeError=$wpdb->last_error!=='';\WC_Cache_Helper::get_transient_version('shipping',true);
			$after=$this->rate($id);if(is_wp_error($after))return $after;
			if($writeError||$after['scopeExpected']!==$before['scopeExpected']||$after['values']!==array_replace($before['values'],[$key=>$value])||$after['scope']!==$before['scope']||$after['large']!==$before['large'])return $this->error('readback','Saved rate differs. Refresh and review before retrying.',409);
			return $after;
		}catch(\Throwable $e){return $this->error('write','Tax rate saving could not be confirmed. Refresh and review.',409);}finally{$wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock));}
	}
	/** Delete exactly one native rate and its own city/postcode rows. */
	public function delete_rate(int $id,array $input){
		if(!$this->allowed()||!$this->editable())return $this->error('forbidden','Your account cannot delete store-wide tax rates.',403);
		if($id<1||array_diff(array_keys($input),['confirmName','expected','acceptEffect'])||!is_string($input['confirmName']??null)||!is_string($input['expected']??null)||($input['acceptEffect']??false)!==true)return $this->error('rate_input','Review and confirm the saved rate to delete.');
		global $wpdb;$lock='kiwe-tax-'.substr(hash('sha256',$wpdb->prefix),0,32);if('1'!==(string)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 0)',$lock)))return $this->error('busy','Another tax change is running. Refresh and review.',409);$transaction=false;$touched=false;
		try{
			$before=$this->rate($id);if(is_wp_error($before))return $before;if(!hash_equals($before['expected'],$input['expected']))return $this->error('stale','The rate, its rules or policy changed. Refresh and review.',409);
			if(!$before['canEdit'])return $this->error('protected','Your designer manages this custom or large tax rate.',409);
			if(!hash_equals($before['values']['name'],$input['confirmName']))return $this->error('confirm','Confirm the displayed tax-rate name.');
			$settings=$this->settings();if(is_wp_error($settings))return $settings;
			$transaction=$this->begin_rate_creation();if(!$transaction)return $this->error('transaction','Safe rate deletion needs native InnoDB tables and an available transaction.',409);$touched=true;
			\WC_Tax::_delete_tax_rate($id);if($wpdb->last_error!=='')throw new \RuntimeException('delete');
			$remainingRate=$this->rows($wpdb->prepare("SELECT tax_rate_id FROM {$wpdb->prefix}woocommerce_tax_rates WHERE tax_rate_id = %d LIMIT 1",$id));
			$remainingRules=$this->rows($wpdb->prepare("SELECT location_id FROM {$wpdb->prefix}woocommerce_tax_rate_locations WHERE tax_rate_id = %d LIMIT 1",$id));$current=$this->settings();
			if(is_wp_error($remainingRate)||is_wp_error($remainingRules)||is_wp_error($current)||$remainingRate||$remainingRules||$current['expected']!==$settings['expected'])throw new \RuntimeException('readback');
			if($wpdb->query('COMMIT')===false)throw new \RuntimeException('commit');$transaction=false;return ['deleted'=>true,'id'=>$id,'class'=>$before['scope']['class']];
		}catch(\Throwable $e){return $this->error('rate_delete','Tax-rate deletion could not be confirmed. Refresh before retrying.',409);}finally{if($transaction)$wpdb->query('ROLLBACK');if($touched){\WC_Cache_Helper::invalidate_cache_group('taxes');\WC_Cache_Helper::get_transient_version('shipping',true);}$wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock));}
	}
	/** Native country/state edits preserve rate values and every saved location row. */
	public function geography(int $id,array $input=[]){
		$rate=$this->rate($id);if(is_wp_error($rate))return $rate;global $wpdb;
		$rows=$this->rows($wpdb->prepare("SELECT tax_rate_id, tax_rate_country, tax_rate_state FROM {$wpdb->prefix}woocommerce_tax_rates WHERE tax_rate_id = %d LIMIT 1",$id));if(is_wp_error($rows))return $rows;if(!$rows)return $this->error('missing','This tax rate no longer exists.',404);$saved=$rows[0];
		$countries=new \WC_Countries();$known=$countries->get_countries();$country=$input['kind']??$saved['tax_rate_country'];if(!is_string($country)||(isset($input['kind'])&&$country!==''&&!isset($known[$country])))return $this->error('country','Choose a listed country.');
		$states=$country!==''?$countries->get_states($country):[];$states=is_array($states)?$states:[];
		$oldCountry=(string)$saved['tax_rate_country'];$oldState=(string)$saved['tax_rate_state'];$oldStates=$oldCountry!==''&&isset($known[$oldCountry])?$countries->get_states($oldCountry):[];$oldStates=is_array($oldStates)?$oldStates:[];
		$custom=($oldCountry!==''&&!isset($known[$oldCountry]))||($oldState!==''&&!array_key_exists($oldState,$oldStates))||$rate['scope']['country']!==$oldCountry||$rate['scope']['state']!==$oldState;
		$countryChoices=[];foreach(array_slice($known,0,300,true)as$code=>$name)$countryChoices[]=['code'=>(string)$code,'name'=>Task_Workspace_UI::text($name,120)];$stateChoices=[];foreach(array_slice($states,0,300,true)as$code=>$name)$stateChoices[]=['code'=>(string)$code,'name'=>Task_Workspace_UI::text($name,120)];
		$large=count($known)>300||count($states)>300||count($oldStates)>300||$rate['large'];
		return ['rate'=>$rate,'countries'=>$countryChoices,'states'=>$stateChoices,'country'=>$country,'custom'=>$custom,'large'=>$large,'canEdit'=>$rate['canEdit']&&!$custom&&!$large,'expected'=>$rate['expected']];
	}
	public function save_geography(int $id,array $input){
		if(!$this->allowed()||!$this->editable())return $this->error('forbidden','Your account cannot change store-wide tax regions.',403);
		if(array_diff(array_keys($input),['country','state','expected','acceptEffect'])||!is_string($input['country']??null)||!is_string($input['state']??null)||!is_string($input['expected']??null)||($input['acceptEffect']??false)!==true)return $this->error('geography_input','Review and confirm the country and state together.');
		global $wpdb;$lock='kiwe-tax-'.substr(hash('sha256',$wpdb->prefix),0,32);if('1'!==(string)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 0)',$lock)))return $this->error('busy','Another tax change is running. Refresh and review.',409);$transaction=false;$touched=false;
		try{
			$before=$this->geography($id,['kind'=>$input['country']]);if(is_wp_error($before))return $before;if(!hash_equals($before['expected'],$input['expected']))return $this->error('stale','The rate, its rules or tax policy changed. Refresh and review.',409);if(!$before['canEdit'])return $this->error('protected','Your designer manages this custom or large tax setup.',409);
			if(($input['country']!==''&&!in_array($input['country'],array_column($before['countries'],'code'),true))||($input['state']!==''&&!in_array($input['state'],array_column($before['states'],'code'),true)))return $this->error('geography_input','Choose a listed country and one of its states.');
			$wanted=array_replace($before['rate']['scope'],['country'=>$input['country'],'state'=>$input['state']]);if($wanted===$before['rate']['scope'])return $before;
			$sql=$wpdb->prepare("SELECT location_id, location_code, location_type FROM {$wpdb->prefix}woocommerce_tax_rate_locations WHERE tax_rate_id = %d ORDER BY location_id ASC LIMIT 201",$id);$locations=$this->rows($sql);$settings=$this->settings();if(is_wp_error($locations))return $locations;if(is_wp_error($settings))return $settings;
			$transaction=$this->begin_rate_creation();if(!$transaction)return $this->error('transaction','Safe region changes need native InnoDB tables and an available transaction.',409);$touched=true;
			\WC_Tax::_update_tax_rate($id,['tax_rate_country'=>$input['country'],'tax_rate_state'=>$input['state']]);if($wpdb->last_error!=='')throw new \RuntimeException('write');
			$after=$this->geography($id);$current=$this->settings();$afterLocations=$this->rows($wpdb->prepare("SELECT location_id, location_code, location_type FROM {$wpdb->prefix}woocommerce_tax_rate_locations WHERE tax_rate_id = %d ORDER BY location_id ASC LIMIT 201",$id));
			if(is_wp_error($after)||is_wp_error($current)||is_wp_error($afterLocations)||$after['rate']['scope']!==$wanted||$after['rate']['values']!==$before['rate']['values']||$afterLocations!==$locations||$current['expected']!==$settings['expected'])throw new \RuntimeException('preservation');
			if($wpdb->query('COMMIT')===false)throw new \RuntimeException('commit');$transaction=false;return $after;
		}catch(\Throwable $e){return $this->error('geography_write','Region changes could not be confirmed. Refresh the saved rate before retrying.',409);}finally{if($transaction)$wpdb->query('ROLLBACK');if($touched){\WC_Cache_Helper::invalidate_cache_group('taxes');\WC_Cache_Helper::get_transient_version('shipping',true);}$wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock));}
	}
	private function creation_state(){
		$settings=$this->settings();if(is_wp_error($settings))return $settings;global $wpdb;
		$latest=$this->rows("SELECT tax_rate_id FROM {$wpdb->prefix}woocommerce_tax_rates ORDER BY tax_rate_id DESC LIMIT 1");if(is_wp_error($latest))return $latest;
		return ['settings'=>$settings,'latest'=>$latest,'expected'=>hash_hmac('sha256',serialize([$settings['expected'],$latest]),wp_salt('nonce'))];
	}
	public function creation(array $input=[]){
		$state=$this->creation_state();if(is_wp_error($state))return $state;$country=$input['kind']??'';if(!is_string($country))return $this->error('country','Choose a known country.');
		$countries=new \WC_Countries();$known=$countries->get_countries();if($country!==''&&!isset($known[$country]))return $this->error('country','Choose a known country.');$states=$country!==''?$countries->get_states($country):[];$states=is_array($states)?$states:[];
		$countryChoices=[];foreach(array_slice($known,0,300,true)as$code=>$name)$countryChoices[]=['code'=>$code,'name'=>Task_Workspace_UI::text($name,120)];$stateChoices=[];foreach(array_slice($states,0,300,true)as$code=>$name)$stateChoices[]=['code'=>(string)$code,'name'=>Task_Workspace_UI::text($name,120)];
		return ['classes'=>array_values(array_filter($state['settings']['classes'],static fn($v)=>$v['value']!=='inherit')),'countries'=>$countryChoices,'states'=>$stateChoices,'country'=>$country,'canCreate'=>$this->editable()&&!$state['settings']['large']&&count($known)<=300&&count($states)<=300,'large'=>$state['settings']['large']||count($known)>300||count($states)>300,'expected'=>$state['expected']];
	}
	private function creation_rules($input,string $kind){
		if(!is_array($input)||!array_is_list($input)||count($input)>50)return $this->error('rules','Use up to 50 city rules and 50 postcode rules.');$out=[];
		foreach($input as$raw){if(!is_string($raw)||strlen($raw)>100||sanitize_text_field($raw)!==$raw)return $this->error('rules','Use one plain city or postcode per line.');$v=strtoupper(trim($raw));if($v===''||$v==='0'||$v==='*'||str_contains($v,';')||($kind==='postcode'&&preg_match('/^[A-Z0-9][A-Z0-9 -]*\*?$/D',$v)!==1))return $this->error('rules','Use a city name or postcode, optionally ending in *. Blank lists match all locations of that kind.');if(!in_array($v,$out,true))$out[]=$v;}
		return $out;
	}
	private function begin_rate_creation(): bool {
		global $wpdb;$names=array_map(static fn($v)=>$wpdb->prefix.$v,['woocommerce_tax_rates','woocommerce_tax_rate_locations','options']);$engines=$this->rows($wpdb->prepare('SELECT TABLE_NAME, ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN (%s,%s,%s)',...$names));if(is_wp_error($engines)||count($engines)!==3)return false;foreach($engines as$row)if(strtoupper((string)$row['ENGINE'])!=='INNODB')return false;
		// Refuse an existing transaction rather than implicitly committing it.
		return $wpdb->query('SET TRANSACTION ISOLATION LEVEL READ COMMITTED')!==false&&$wpdb->query('START TRANSACTION')!==false;
	}
	public function create_rate(array $input){
		if(!$this->allowed()||!$this->editable())return $this->error('forbidden','Your account cannot create store-wide tax rates.',403);
		$keys=['name','rate','priority','order','compound','shipping','class','country','state','cities','postcodes','expected','acceptEffect'];if(array_diff(array_keys($input),$keys)||($input['acceptEffect']??false)!==true||!is_string($input['expected']??null))return $this->error('create_input','Review the new tax rate and confirm its effect.');
		$values=[];foreach(['name','rate','priority','order','compound','shipping']as$key){$values[$key]=$this->rate_value($key,$input[$key]??null);if(is_wp_error($values[$key]))return $values[$key];}foreach(['country','state','class']as$key)if(!is_string($input[$key]??null))return $this->error('create_input','Choose a country, state and tax class.');
		$cities=$this->creation_rules($input['cities']??null,'city');$postcodes=$this->creation_rules($input['postcodes']??null,'postcode');if(is_wp_error($cities))return $cities;if(is_wp_error($postcodes))return $postcodes;
		global $wpdb;$lock='kiwe-tax-'.substr(hash('sha256',$wpdb->prefix),0,32);if('1'!==(string)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 0)',$lock)))return $this->error('busy','Another tax change is running. Refresh and review.',409);$transaction=false;$touched=false;
		try{
			$before=$this->creation_state();if(is_wp_error($before))return $before;if(!hash_equals($before['expected'],$input['expected']))return $this->error('stale','Tax setup changed. Refresh the rate list before creating another rate.',409);$choices=$this->creation(['kind'=>$input['country']]);if(is_wp_error($choices))return $choices;
			if(!$choices['canCreate'])return $this->error('protected','Your designer manages creation for this custom or large setup.',409);
			if(!in_array($input['class'],array_column($choices['classes'],'value'),true)||$input['country']===''||!in_array($input['country'],array_column($choices['countries'],'code'),true)||($input['state']!==''&&!in_array($input['state'],array_column($choices['states'],'code'),true)))return $this->error('create_input','Choose a listed tax class, country and state.');
			$duplicate=$this->rows($wpdb->prepare("SELECT tax_rate_id FROM {$wpdb->prefix}woocommerce_tax_rates WHERE tax_rate_class = %s AND tax_rate_name = %s LIMIT 1",$input['class'],$values['name']));if(is_wp_error($duplicate))return $duplicate;if($duplicate)return $this->error('duplicate','A rate in this class already uses that name. Open it or choose a distinct name.',409);
			$transaction=$this->begin_rate_creation();if(!$transaction)return $this->error('transaction','Safe rate creation needs native InnoDB tables and an available transaction. Ask your designer to review hosting.',409);
			$touched=true;$row=['tax_rate_name'=>$values['name'],'tax_rate'=>$values['rate'],'tax_rate_priority'=>$values['priority'],'tax_rate_order'=>$values['order'],'tax_rate_compound'=>(int)$values['compound'],'tax_rate_shipping'=>(int)$values['shipping'],'tax_rate_class'=>$input['class'],'tax_rate_country'=>$input['country'],'tax_rate_state'=>$input['state']];
			$id=(int)\WC_Tax::_insert_tax_rate($row);if(!$id||$wpdb->last_error!=='')throw new \RuntimeException('insert');
			\WC_Tax::_update_tax_rate_cities($id,$cities);if($wpdb->last_error!=='')throw new \RuntimeException('cities');\WC_Tax::_update_tax_rate_postcodes($id,$postcodes);if($wpdb->last_error!=='')throw new \RuntimeException('postcodes');
			$after=$this->rate($id);if(is_wp_error($after))throw new \RuntimeException('readback');$rules=$this->rows($wpdb->prepare("SELECT location_id, location_code, location_type FROM {$wpdb->prefix}woocommerce_tax_rate_locations WHERE tax_rate_id = %d ORDER BY location_id ASC LIMIT 201",$id));if(is_wp_error($rules))throw new \RuntimeException('rules');
			$actual=array_map(static fn($r)=>[$r['location_type'],$r['location_code']],$rules);$wanted=[];foreach($cities as$v)$wanted[]=['city',$v];foreach($postcodes as$v)$wanted[]=['postcode',$v];sort($actual);sort($wanted);$current=$this->settings();
			if($actual!==$wanted||$after['values']!==$values||$after['scope']['country']!==$input['country']||$after['scope']['state']!==$input['state']||$after['scope']['class']!==$input['class']||is_wp_error($current)||$current['expected']!==$before['settings']['expected'])throw new \RuntimeException('preservation');
			if($wpdb->query('COMMIT')===false)throw new \RuntimeException('commit');$transaction=false;return $after;
		}catch(\Throwable $e){return $this->error('creation','Rate creation could not be confirmed. Refresh the saved rate list before trying again.',409);}finally{if($transaction)$wpdb->query('ROLLBACK');if($touched){\WC_Cache_Helper::invalidate_cache_group('taxes');\WC_Cache_Helper::get_transient_version('shipping',true);}$wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock));}
	}
	private function rule_state(int $id){
		$rate=$this->rate($id);if(is_wp_error($rate))return $rate;global $wpdb;
		$rows=$this->rows($wpdb->prepare("SELECT location_id, location_code, location_type FROM {$wpdb->prefix}woocommerce_tax_rate_locations WHERE tax_rate_id = %d ORDER BY location_id ASC LIMIT 201",$id));if(is_wp_error($rows))return $rows;
		$seen=[];$protected=false;foreach($rows as$row){$kind=(string)$row['location_type'];$code=(string)$row['location_code'];$pair=$kind.':'.$code;if(!in_array($kind,['city','postcode'],true)||$code===''||$code==='0'||$code==='*'||strlen($code)>200||strtoupper(trim($code))!==$code||sanitize_text_field($code)!==$code||isset($seen[$pair]))$protected=true;$seen[$pair]=true;}
		return ['rate'=>$rate,'rows'=>$rows,'protected'=>$protected,'canEdit'=>$rate['canEdit']&&!$protected];
	}
	public function rules(int $id,array $input=[]){
		$state=$this->rule_state($id);if(is_wp_error($state))return $state;$kind=$input['kind']??'postcode';if(!in_array($kind,['city','postcode'],true))return $this->error('rules_input','Choose cities or postcodes.');
		$page=max(1,min(1000,(int)($input['page']??1)));$items=array_values(array_filter($state['rows'],static fn($r)=>$r['location_type']===$kind));
		return ['id'=>$id,'name'=>$state['rate']['values']['name'],'scope'=>$state['rate']['scope'],'kind'=>$kind,'items'=>array_map(static fn($r)=>['id'=>(int)$r['location_id'],'code'=>Task_Workspace_UI::text($r['location_code'],200)],array_slice($items,($page-1)*20,20)),'page'=>$page,'hasNext'=>count($items)>$page*20,'total'=>count($items),'large'=>$state['rate']['large'],'protected'=>$state['protected'],'canEdit'=>$state['canEdit'],'expected'=>$state['rate']['expected']];
	}
	public function save_rules(int $id,array $input){
		if(!$this->allowed()||!$this->editable())return $this->error('forbidden','Your account cannot change store-wide tax rules.',403);
		if(array_diff(array_keys($input),['kind','op','codes','ids','expected','acceptEffect'])||!in_array($input['kind']??null,['city','postcode'],true)||!in_array($input['op']??null,['add','remove'],true)||!is_string($input['expected']??null)||($input['acceptEffect']??false)!==true)return $this->error('rules_input','Review and confirm one location-rule change.');
		$kind=$input['kind'];$op=$input['op'];$codes=[];$ids=[];
		if($op==='add'){$codes=$this->creation_rules($input['codes']??null,$kind);if(is_wp_error($codes))return $codes;if(!$codes||isset($input['ids']))return $this->error('rules_input','Add at least one city or postcode.');}
		else{$ids=$input['ids']??null;if(isset($input['codes'])||!is_array($ids)||!array_is_list($ids)||!$ids||count($ids)>20)return $this->error('rules_input','Select up to twenty saved rules.');foreach($ids as$v)if(!is_int($v)||$v<1)return $this->error('rules_input','Select saved rule identifiers.');$ids=array_values(array_unique($ids));}
		global $wpdb;$lock='kiwe-tax-'.substr(hash('sha256',$wpdb->prefix),0,32);if('1'!==(string)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 0)',$lock)))return $this->error('busy','Another tax change is running. Refresh and review.',409);$transaction=false;$touched=false;
		try{
			$before=$this->rule_state($id);if(is_wp_error($before))return $before;if(!hash_equals($before['rate']['expected'],$input['expected']))return $this->error('stale','The rate or its rules changed. Refresh and review.',409);if(!$before['canEdit'])return $this->error('protected','Your designer manages this custom or large rule set.',409);
			$selected=array_values(array_filter($before['rows'],static fn($r)=>$r['location_type']===$kind));$other=array_values(array_filter($before['rows'],static fn($r)=>$r['location_type']!==$kind));$wanted=array_column($selected,'location_code');
			if($op==='add')$wanted=array_values(array_unique(array_merge($wanted,$codes)));else{if(array_diff($ids,array_map('intval',array_column($selected,'location_id'))))return $this->error('stale','A selected rule is no longer in this list.',409);$wanted=array_column(array_filter($selected,static fn($r)=>!in_array((int)$r['location_id'],$ids,true)),'location_code');}
			if(count($wanted)+count($other)>200)return $this->error('large','This editor supports up to 200 total city and postcode rules.',409);if($wanted===array_column($selected,'location_code'))return $this->rules($id,['kind'=>$kind]);
			$settings=$this->settings();if(is_wp_error($settings))return $settings;$transaction=$this->begin_rate_creation();if(!$transaction)return $this->error('transaction','Safe rule changes need native InnoDB tables and an available transaction.',409);$touched=true;
			if($kind==='city')\WC_Tax::_update_tax_rate_cities($id,$wanted);else \WC_Tax::_update_tax_rate_postcodes($id,$wanted);if($wpdb->last_error!=='')throw new \RuntimeException('write');
			$after=$this->rule_state($id);$current=$this->settings();if(is_wp_error($after)||is_wp_error($current))throw new \RuntimeException('readback');$afterOther=array_values(array_filter($after['rows'],static fn($r)=>$r['location_type']!==$kind));$actual=array_column(array_filter($after['rows'],static fn($r)=>$r['location_type']===$kind),'location_code');sort($actual);sort($wanted);
			$oldScope=$before['rate']['scope'];$newScope=$after['rate']['scope'];unset($oldScope['locationCount'],$newScope['locationCount']);if($actual!==$wanted||$afterOther!==$other||$after['rate']['values']!==$before['rate']['values']||$oldScope!==$newScope||$settings['expected']!==$current['expected'])throw new \RuntimeException('preservation');
			if($wpdb->query('COMMIT')===false)throw new \RuntimeException('commit');$transaction=false;return $this->rules($id,['kind'=>$kind]);
		}catch(\Throwable $e){return $this->error('rules_write','Rule changes could not be confirmed. Refresh the saved rules before retrying.',409);}finally{if($transaction)$wpdb->query('ROLLBACK');if($touched){\WC_Cache_Helper::invalidate_cache_group('taxes');\WC_Cache_Helper::get_transient_version('shipping',true);}$wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock));}
	}
	public function locations(int $id,array $input=[]){
		if(!$this->allowed())return $this->error('forbidden','Tax rate regions are unavailable.',403);global $wpdb;
		$rate=$this->rows($wpdb->prepare("SELECT tax_rate_id FROM {$wpdb->prefix}woocommerce_tax_rates WHERE tax_rate_id = %d LIMIT 1",$id));if(is_wp_error($rate))return $rate;if(!$rate)return $this->error('missing','This tax rate no longer exists.',404);
		$page=max(1,min(1000,(int)($input['page']??1)));$rows=$this->rows($wpdb->prepare("SELECT location_id, location_code, location_type FROM {$wpdb->prefix}woocommerce_tax_rate_locations WHERE tax_rate_id = %d ORDER BY location_id ASC LIMIT 21 OFFSET %d",$id,($page-1)*20));if(is_wp_error($rows))return $rows;
		return ['id'=>$id,'items'=>array_map(static fn($r)=>['id'=>(int)$r['location_id'],'code'=>Task_Workspace_UI::text($r['location_code'],240),'kind'=>Task_Workspace_UI::text($r['location_type'],30)],array_slice($rows,0,20)),'page'=>$page,'hasNext'=>count($rows)>20];
	}
}
