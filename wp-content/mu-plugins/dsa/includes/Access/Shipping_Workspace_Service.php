<?php
namespace DSA\Access;
if(!defined('ABSPATH'))exit;

/** Private bounded projections of Woo tables; no rates calculation on reads. */
final class Shipping_Workspace_Service {
	private const CORE=['flat_rate'=>'Flat rate','free_shipping'=>'Free shipping','local_pickup'=>'Local pickup'];
	private function allowed(): bool {return class_exists('WC_Shipping_Zones')&&(current_user_can('edit_products')||(current_user_can('edit_shop_orders')&&current_user_can('edit_others_shop_orders')));}
	private function editable(): bool {return current_user_can('edit_products')&&current_user_can('kiwe_manage_context');}
	private function error(string $code,string $message,int $status=400){return new \WP_Error('kiwe_shipping_'.$code,$message,['status'=>$status]);}
	private function rows(string $sql){global $wpdb;$rows=$wpdb->get_results($sql,ARRAY_A);return $wpdb->last_error!==''||!is_array($rows)?$this->error('read','Delivery settings could not be read. Refresh and review.',409):$rows;}
	private function zone(int $id){global $wpdb;if($id===0)return ['zone_id'=>0,'zone_name'=>'Rest of the world','zone_order'=>0];$rows=$this->rows($wpdb->prepare("SELECT zone_id, zone_name, zone_order FROM {$wpdb->prefix}woocommerce_shipping_zones WHERE zone_id = %d LIMIT 1",$id));if(is_wp_error($rows))return $rows;return $rows[0]??$this->error('missing','This shipping zone is unavailable.',404);}
	public function zones(array $input=[]){
		if(!$this->allowed())return $this->error('forbidden','Delivery setup is unavailable for your account.',403);global $wpdb;
		$page=max(1,min(1000,(int)($input['page']??1)));$rows=$this->rows($wpdb->prepare("SELECT zone_id, zone_name, zone_order FROM {$wpdb->prefix}woocommerce_shipping_zones ORDER BY zone_order ASC, zone_id ASC LIMIT 21 OFFSET %d",($page-1)*20));if(is_wp_error($rows))return $rows;
		return ['items'=>array_map(static fn($z)=>['id'=>(int)$z['zone_id'],'name'=>Task_Workspace_UI::text($z['zone_name'],240),'order'=>(int)$z['zone_order']],array_slice($rows,0,20)),'page'=>$page,'hasNext'=>count($rows)>20,'canEdit'=>$this->editable(),'shippingDisabled'=>get_option('woocommerce_ship_to_countries','')==='disabled'];
	}
	private function method_summary(array $row): array {
		$core=isset(self::CORE[$row['method_id']]);$settings=$core?get_option('woocommerce_'.$row['method_id'].'_'.$row['instance_id'].'_settings',[]):[];
		return ['id'=>(int)$row['instance_id'],'kind'=>$row['method_id'],'name'=>Task_Workspace_UI::text($core&&is_array($settings)?($settings['title']??self::CORE[$row['method_id']]):'Extension method: '.$row['method_id'],240),'enabled'=>(bool)$row['is_enabled'],'order'=>(int)$row['method_order'],'core'=>$core,'canEdit'=>$core&&$this->editable()];
	}
	public function detail(int $id,array $input=[]){
		if(!$this->allowed())return $this->error('forbidden','Delivery setup is unavailable for your account.',403);$zone=$this->zone($id);if(is_wp_error($zone))return $zone;global $wpdb;
		$page=max(1,min(1000,(int)($input['page']??1)));$view=($input['view']??'methods')==='locations'?'locations':'methods';
		$sql=$view==='locations'?$wpdb->prepare("SELECT location_id, location_type, location_code FROM {$wpdb->prefix}woocommerce_shipping_zone_locations WHERE zone_id = %d ORDER BY location_id ASC LIMIT 21 OFFSET %d",$id,($page-1)*20):$wpdb->prepare("SELECT instance_id, method_id, method_order, is_enabled FROM {$wpdb->prefix}woocommerce_shipping_zone_methods WHERE zone_id = %d ORDER BY method_order ASC, instance_id ASC LIMIT 21 OFFSET %d",$id,($page-1)*20);
		$rows=$this->rows($sql);if(is_wp_error($rows))return $rows;
		$items=$view==='locations'?array_map(static fn($v)=>['id'=>(int)$v['location_id'],'kind'=>Task_Workspace_UI::text($v['location_type'],30),'name'=>Task_Workspace_UI::text($v['location_code'],240)],array_slice($rows,0,20)):array_map([$this,'method_summary'],array_slice($rows,0,20));
		return ['id'=>$id,'name'=>Task_Workspace_UI::text($zone['zone_name'],240),'order'=>(int)$zone['zone_order'],'view'=>$view,'items'=>$items,'page'=>$page,'hasNext'=>count($rows)>20,'canEdit'=>$this->editable()];
	}
	public function method(int $zoneId,int $instance){
		if(!$this->allowed())return $this->error('forbidden','Delivery setup is unavailable for your account.',403);$zone=$this->zone($zoneId);if(is_wp_error($zone))return $zone;global $wpdb;
		$rows=$this->rows($wpdb->prepare("SELECT zone_id, instance_id, method_id, method_order, is_enabled FROM {$wpdb->prefix}woocommerce_shipping_zone_methods WHERE zone_id = %d AND instance_id = %d LIMIT 1",$zoneId,$instance));if(is_wp_error($rows))return $rows;if(!$rows)return $this->error('missing','This delivery method is unavailable in this zone.',404);
		$row=$rows[0];if(!isset(self::CORE[$row['method_id']]))return $this->error('extension','Your site designer manages this extension method.',403);
		$raw=get_option('woocommerce_'.$row['method_id'].'_'.$instance.'_settings',[]);if(!is_array($raw))return $this->error('settings','These settings need your site designer to review them.',409);
		$out=$this->method_summary($row)+['zoneId'=>$zoneId,'zoneName'=>Task_Workspace_UI::text($zone['zone_name'],240)];
		$out['expected']=hash_hmac('sha256',serialize([$zone,$row,$raw]),wp_salt('nonce'));return $out;
	}
	public function save(int $zoneId,int $instance,array $input){
		if(!$this->allowed()||!$this->editable())return $this->error('forbidden','Your account can review delivery but cannot change store-wide availability.',403);
		if(array_diff(array_keys($input),['enabled','expected','acceptEffect'])||!is_bool($input['enabled']??null)||($input['acceptEffect']??false)!==true||!is_string($input['expected']??null))return $this->error('input','Review and confirm the delivery availability change.');
		global $wpdb;$lock='kiwe-shipping-'.substr(hash('sha256',$wpdb->prefix.':'.$instance),0,32);if('1'!==(string)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 0)',$lock)))return $this->error('busy','Another delivery change is running. Refresh and review.',409);
		try{
			$before=$this->method($zoneId,$instance);if(is_wp_error($before))return $before;if(!hash_equals($before['expected'],$input['expected']))return $this->error('stale','This delivery method or zone changed. Refresh and review.',409);
			$method=\WC_Shipping_Zones::get_shipping_method($instance);$classes=['flat_rate'=>'WC_Shipping_Flat_Rate','free_shipping'=>'WC_Shipping_Free_Shipping','local_pickup'=>'WC_Shipping_Local_Pickup'];if(!$method||get_class($method)!==$classes[$before['kind']])return $this->error('extension','This delivery handler is customized. Ask your site designer to manage it.',403);
			if($before['enabled']===$input['enabled'])return $before;
			$key=$method->get_instance_option_key();$settings=get_option($key,[]);$request=new \WP_REST_Request('POST');$request->set_param('zone_id',$zoneId);$request->set_param('enabled',$input['enabled']);
			// Reuse the native writer, not its broad REST permission or response/settings form.
			$result=(new \WC_REST_Shipping_Zone_Methods_V2_Controller())->update_fields($instance,$method,$request);if(is_wp_error($result))return $this->error('write','Delivery change could not be confirmed. Refresh and review.',409);
			\WC_Cache_Helper::invalidate_cache_group('shipping_zones');\WC_Cache_Helper::get_transient_version('shipping',true);
			$after=$this->method($zoneId,$instance);if(is_wp_error($after)||$after['enabled']!==$input['enabled']||$settings!==get_option($key,[])||array_diff_assoc(array_diff_key($before,array_flip(['expected','enabled'])),array_diff_key($after,array_flip(['expected','enabled']))))return $this->error('readback','Saved delivery settings differ. Refresh and review before retrying.',409);
			return $after;
		}catch(\Throwable $e){return $this->error('write','Delivery change could not be confirmed. Refresh and review before retrying.',409);}finally{$wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock));}
	}

	private function plain_amount($value): bool {return is_string($value)&&preg_match('/^(?:[0-9]{1,9}(?:\.[0-9]{1,6})?)?$/D',$value)===1;}
	public function rates(int $zoneId,int $instance){
		$base=$this->method($zoneId,$instance);if(is_wp_error($base))return $base;
		$raw=get_option('woocommerce_'.$base['kind'].'_'.$instance.'_settings',[]);
		$defaults=$base['kind']==='free_shipping'?['title'=>self::CORE[$base['kind']],'requires'=>'','min_amount'=>'0','ignore_discounts'=>'no']:['title'=>self::CORE[$base['kind']],'cost'=>$base['kind']==='flat_rate'?'0':'','tax_status'=>'taxable'];
		$values=[];foreach($defaults as$key=>$default){$value=$raw[$key]??$default;if(!is_scalar($value)||strlen((string)$value)>500)return $this->error('settings','These delivery settings need your site designer to review them.',409);$values[$key]=(string)$value;}
		// The native optional weight rule is visible in the same rates editor as its base charge.
		if($base['kind']==='flat_rate'){
			$increment=$raw['increment_weight']??'';
			if(!is_scalar($increment)||strlen((string)$increment)>500)return $this->error('settings','This weight rule needs your site designer to review it.',409);
			$values['increment_weight']=(string)$increment;
			$base['weightUnit']=Task_Workspace_UI::text(get_option('woocommerce_weight_unit','kg'),12);
		}
		$base['values']=$values;$base['currency']=Task_Workspace_UI::text(get_option('woocommerce_currency',''),12);
		$base['amountEditable']=$this->plain_amount($values[$base['kind']==='free_shipping'?'min_amount':'cost']);
		$base['classCharges']=false;foreach($raw as$key=>$value)if((str_starts_with((string)$key,'class_cost_')||$key==='no_class_cost')&&is_scalar($value)&&(string)$value!==''){$base['classCharges']=true;break;}
		return $base;
	}
	public function save_rates(int $zoneId,int $instance,array $input){
		if(!$this->allowed()||!$this->editable())return $this->error('forbidden','Your account cannot change store-wide delivery rates.',403);
		if(array_diff(array_keys($input),['changes','expected','acceptEffect'])||($input['acceptEffect']??false)!==true||!is_string($input['expected']??null)||!is_array($input['changes']??null)||!$input['changes']||count($input['changes'])>5)return $this->error('input','Review and confirm the changed delivery settings.');
		global $wpdb;$lock='kiwe-shipping-'.substr(hash('sha256',$wpdb->prefix.':'.$instance),0,32);if('1'!==(string)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 0)',$lock)))return $this->error('busy','Another delivery change is running. Refresh and review.',409);
		try{
			$before=$this->rates($zoneId,$instance);if(is_wp_error($before))return $before;if(!hash_equals($before['expected'],$input['expected']))return $this->error('stale','These delivery settings changed. Refresh and review.',409);
			$changes=$input['changes'];if(array_diff(array_keys($changes),array_keys($before['values'])))return $this->error('input','Only the displayed delivery settings can be changed.');
			foreach($changes as$key=>$value){
				if(!is_string($value))return $this->error('input','Delivery values must use the displayed format.');
				if($key==='title'&&($value===''||strlen($value)>240||sanitize_text_field($value)!==$value))return $this->error('input','Enter a plain delivery name up to 240 bytes.');
				if(in_array($key,['cost','min_amount'],true)&&(!$before['amountEditable']||!$this->plain_amount($value)||($key==='min_amount'&&$value==='')))return $this->error('input','Use a nonnegative amount with a decimal point. Existing formulas need your site designer.');
				if($key==='requires'&&!in_array($value,['','coupon','min_amount','either','both'],true))return $this->error('input','Choose an existing free-shipping requirement.');
				if($key==='tax_status'&&!in_array($value,['taxable','none'],true))return $this->error('input','Choose taxable or none.');
				if($key==='ignore_discounts'&&!in_array($value,['yes','no'],true))return $this->error('input','Choose when the minimum amount applies.');
				if($key==='increment_weight'&&($value!==''&&(!$this->plain_amount($value)||(float)$value<=0)))return $this->error('input','Use a positive weight increment, or leave empty for normal flat-rate delivery.');
			}
			$method=\WC_Shipping_Zones::get_shipping_method($instance);$classes=['flat_rate'=>'WC_Shipping_Flat_Rate','free_shipping'=>'WC_Shipping_Free_Shipping','local_pickup'=>'WC_Shipping_Local_Pickup'];if(!$method||get_class($method)!==$classes[$before['kind']])return $this->error('extension','This delivery handler is customized. Ask your site designer to manage it.',403);
			$delta=array_diff_assoc($changes,$before['values']);if(!$delta)return $before;
			$method->init_instance_settings();$baseline=$method->instance_settings;if(!is_array($baseline))return $this->error('settings','Native delivery settings could not be read.',409);
			$fields=$method->get_instance_form_fields();foreach($delta as$key=>$value)if(!isset($fields[$key]))return $this->error('extension','This delivery field is customized. Ask your site designer.',409);
			$wanted=array_replace($baseline,$delta);$request=new \WP_REST_Request('POST');$request->set_param('zone_id',$zoneId);$request->set_param('settings',wp_slash($delta));
			$result=(new \WC_REST_Shipping_Zone_Methods_V2_Controller())->update_fields($instance,$method,$request);
			\WC_Cache_Helper::invalidate_cache_group('shipping_zones');\WC_Cache_Helper::get_transient_version('shipping',true);
			if(is_wp_error($result)||get_option($method->get_instance_option_key(),null)!==$wanted)return $this->error('readback','Saved delivery rates differ. Refresh and review before retrying.',409);
			$after=$this->rates($zoneId,$instance);$protected=['id','zoneId','zoneName','kind','enabled','order'];foreach($protected as$key)if(is_wp_error($after)||$before[$key]!==$after[$key])return $this->error('readback','Delivery identity or availability changed. Refresh and review.',409);
			return $after;
		}catch(\Throwable $e){return $this->error('write','Delivery rate saving could not be confirmed. Refresh and review.',409);}finally{$wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock));}
	}

	/** Class catalog reads stop at 201; only a selected method ever instantiates Woo's forms. */
	public function class_rates(int $zoneId,int $instance,array $input=[]){
		$base=$this->method($zoneId,$instance);if(is_wp_error($base))return $base;if($base['kind']!=='flat_rate')return $this->error('type','Shipping-class charges belong to flat rate delivery.',403);
		global $wpdb;$terms=$this->rows("SELECT t.term_id, t.name, t.slug FROM {$wpdb->prefix}terms t INNER JOIN {$wpdb->prefix}term_taxonomy tt ON tt.term_id = t.term_id WHERE tt.taxonomy = 'product_shipping_class' ORDER BY t.term_id ASC LIMIT 201");if(is_wp_error($terms))return $terms;
		$raw=get_option('woocommerce_flat_rate_'.$instance.'_settings',[]);$legacy=get_option('woocommerce_flat_rate_settings',[]);if(!is_array($raw)||!is_array($legacy))return $this->error('settings','Class settings need your site designer to review them.',409);
		$page=max(1,min(10,(int)($input['page']??1)));$values=['type'=>$raw['type']??'class','no_class_cost'=>$raw['no_class_cost']??''];$editable=[];$items=[];
		foreach(array_slice($terms,($page-1)*20,20)as$t){$key='class_cost_'.(int)$t['term_id'];$old=$legacy['class_cost_'.$t['slug']]??'';$value=$raw[$key]??$old;if($value==='')$value=$old;$values[$key]=$value;$items[]=['id'=>(int)$t['term_id'],'name'=>Task_Workspace_UI::text($t['name'],240),'key'=>$key,'legacy'=>$old!==''];$editable[$key]=$old===''&&is_string($value)&&$this->plain_amount($value);}
		foreach($values as$key=>$v){if(!is_scalar($v)||strlen((string)$v)>500)return $this->error('settings','Class settings need your site designer to review them.',409);$values[$key]=(string)$v;}
		$large=count($terms)>200;$base['canEdit']=$base['canEdit']&&!$large&&!empty($terms);$editable['type']=in_array($values['type'],['class','order'],true);$editable['no_class_cost']=$this->plain_amount($values['no_class_cost']);
		return array_replace($base,['items'=>$items,'values'=>$values,'editable'=>$editable,'page'=>$page,'hasNext'=>count($terms)>$page*20&&$page<10,'large'=>$large,'empty'=>!$terms,'currency'=>Task_Workspace_UI::text(get_option('woocommerce_currency',''),12),'expected'=>hash_hmac('sha256',serialize([$base['expected'],$terms,$legacy]),wp_salt('nonce'))]);
	}
	public function save_class_rates(int $zoneId,int $instance,array $input){
		if(!$this->allowed()||!$this->editable())return $this->error('forbidden','Your account cannot change store-wide delivery charges.',403);
		if(array_diff(array_keys($input),['page','changes','expected','acceptEffect'])||!is_int($input['page']??null)||$input['page']<1||$input['page']>10||($input['acceptEffect']??false)!==true||!is_string($input['expected']??null)||!is_array($input['changes']??null)||!$input['changes']||count($input['changes'])>22)return $this->error('input','Review and confirm this page of class charges.');
		global $wpdb;$lock='kiwe-shipping-'.substr(hash('sha256',$wpdb->prefix.':'.$instance),0,32);if('1'!==(string)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 0)',$lock)))return $this->error('busy','Another delivery change is running. Refresh and review.',409);
		try{
			$before=$this->class_rates($zoneId,$instance,$input);if(is_wp_error($before))return $before;if(!$before['canEdit'])return $this->error('catalog','This class catalog needs your site designer to review it.',409);if(!hash_equals($before['expected'],$input['expected']))return $this->error('stale','The classes or delivery charges changed. Refresh and review.',409);
			$changes=$input['changes'];if(array_diff(array_keys($changes),array_keys($before['values'])))return $this->error('input','Only charges on the displayed page can be changed.');
			foreach($changes as$key=>$value)if(!is_string($value)||!($before['editable'][$key]??false)||($key==='type'?!in_array($value,['class','order'],true):!$this->plain_amount($value)))return $this->error('input','Use nonnegative decimal charges. Formulas and legacy rules need your designer.');
			$delta=array_diff_assoc($changes,$before['values']);if(!$delta)return $before;
			$method=\WC_Shipping_Zones::get_shipping_method($instance);if(!$method||get_class($method)!=='WC_Shipping_Flat_Rate')return $this->error('extension','This delivery provider is customized. Ask your designer.',403);
			$method->init_instance_settings();$baseline=$method->instance_settings;if(!is_array($baseline))return $this->error('settings','Native class settings could not be read.',409);$fields=$method->get_instance_form_fields();foreach($delta as$key=>$value)if(!isset($fields[$key]))return $this->error('extension','This delivery field is customized. Ask your designer.',409);
			$wanted=array_replace($baseline,$delta);$request=new \WP_REST_Request('POST');$request->set_param('zone_id',$zoneId);$request->set_param('settings',wp_slash($delta));$result=(new \WC_REST_Shipping_Zone_Methods_V2_Controller())->update_fields($instance,$method,$request);
			\WC_Cache_Helper::invalidate_cache_group('shipping_zones');\WC_Cache_Helper::get_transient_version('shipping',true);
			if(is_wp_error($result)||get_option($method->get_instance_option_key(),null)!==$wanted)return $this->error('readback','Saved class charges differ. Refresh and review before retrying.',409);
			$after=$this->class_rates($zoneId,$instance,$input);if(is_wp_error($after))return $after;foreach(['id','zoneId','zoneName','kind','enabled','order','items']as$key)if($after[$key]!==$before[$key])return $this->error('readback','Delivery setup changed while saving. Refresh and review.',409);foreach($delta as$key=>$value)if($after['values'][$key]!==$value)return $this->error('readback','The effective class charge differs. Refresh and review.',409);return $after;
		}catch(\Throwable $e){return $this->error('write','Class charge saving could not be confirmed. Refresh and review.',409);}finally{$wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock));}
	}

	private function zone_state(int $id){
		if(!$this->allowed())return $this->error('forbidden','Delivery zones are unavailable.',403);if($id<1)return $this->error('fallback','The fallback zone has fixed matching rules.',403);
		$zone=$this->zone($id);if(is_wp_error($zone))return $zone;global $wpdb;
		$locations=$this->rows($wpdb->prepare("SELECT location_id, location_type, location_code FROM {$wpdb->prefix}woocommerce_shipping_zone_locations WHERE zone_id = %d ORDER BY location_id ASC LIMIT 201",$id));if(is_wp_error($locations))return $locations;
		$methods=$this->rows($wpdb->prepare("SELECT instance_id, method_id, method_order, is_enabled FROM {$wpdb->prefix}woocommerce_shipping_zone_methods WHERE zone_id = %d ORDER BY instance_id ASC LIMIT 101",$id));if(is_wp_error($methods))return $methods;
		if(count($locations)>200||count($methods)>100)return $this->error('large','This zone needs your site designer: editing supports up to 200 region rules and 100 methods. Paged review remains available.',409);
		$pairs=array_map(static fn($r)=>['type'=>(string)$r['location_type'],'code'=>(string)$r['location_code']],$locations);
		return ['zone'=>$zone,'locations'=>$pairs,'methods'=>$methods,'expected'=>hash_hmac('sha256',serialize([$zone,$pairs,$methods]),wp_salt('nonce'))];
	}
	public function zone_settings(int $id,array $input=[]){
		$state=$this->zone_state($id);if(is_wp_error($state))return $state;
		$page=max(1,min(10,(int)($input['page']??1)));$countries=new \WC_Countries();$countryNames=$countries->get_countries();$country=(string)($input['kind']??'');$states=isset($countryNames[$country])?$countries->get_states($country):[];
		$choices=[];foreach(array_slice($countryNames,0,300,true)as$code=>$name)$choices[]=['code'=>$code,'name'=>Task_Workspace_UI::text($name,120)];
		$stateChoices=[];foreach(array_slice(is_array($states)?$states:[],0,300,true)as$code=>$name)$stateChoices[]=['code'=>$country.':'.$code,'name'=>Task_Workspace_UI::text($name,120)];
		$items=[];foreach(array_slice($state['locations'],($page-1)*20,20)as$pair)$items[]=$pair+['name'=>Task_Workspace_UI::text($pair['type']==='country'?($countryNames[$pair['code']]??$pair['code']):$pair['code'],240)];
		return ['id'=>$id,'name'=>Task_Workspace_UI::text($state['zone']['zone_name'],240),'order'=>(int)$state['zone']['zone_order'],'locations'=>$items,'total'=>count($state['locations']),'page'=>$page,'hasNext'=>count($state['locations'])>$page*20,'countries'=>$choices,'states'=>$stateChoices,'canEdit'=>$this->editable(),'expected'=>$state['expected']];
	}
	public function save_zone(int $id,array $input){
		if(!$this->allowed()||!$this->editable())return $this->error('forbidden','Your account cannot change delivery zones.',403);
		if(array_diff(array_keys($input),['operation','name','order','locations','expected','acceptEffect'])||($input['acceptEffect']??false)!==true||!is_string($input['expected']??null)||!in_array($input['operation']??null,['identity','add','remove'],true))return $this->error('input','Review and confirm the zone change.');
		global $wpdb;$lock='kiwe-zone-'.substr(hash('sha256',$wpdb->prefix.':'.$id),0,32);if('1'!==(string)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 0)',$lock)))return $this->error('busy','Another zone edit is running. Refresh and review.',409);
		try{
			$before=$this->zone_state($id);if(is_wp_error($before))return $before;if(!hash_equals($before['expected'],$input['expected']))return $this->error('stale','Zone rules or methods changed. Refresh and review.',409);
			$name=(string)$before['zone']['zone_name'];$order=(int)$before['zone']['zone_order'];$locations=$before['locations'];$op=$input['operation'];
			if($op==='identity'){
				if(isset($input['locations'])||!is_string($input['name']??null)||$input['name']===''||strlen($input['name'])>240||sanitize_text_field($input['name'])!==$input['name']||!is_int($input['order']??null)||$input['order']<0||$input['order']>1000000)return $this->error('input','Use a plain zone name and a whole priority between 0 and 1000000.');$name=$input['name'];$order=$input['order'];
			}else{
				if(isset($input['name'])||isset($input['order'])||!is_array($input['locations']??null)||!$input['locations']||count($input['locations'])>20)return $this->error('input','Choose one to twenty region rules.');
				$countries=new \WC_Countries();$known=$countries->get_countries();
				foreach($input['locations']as$pair){
					if(!is_array($pair)||count($pair)!==2||!is_string($pair['type']??null)||!is_string($pair['code']??null))return $this->error('input','Choose a saved region or a supported new rule.');
					$pair=['type'=>$pair['type'],'code'=>$pair['code']];
					if($op==='remove'){if(!in_array($pair,$locations,true))return $this->error('stale','A selected rule is no longer saved.',409);$locations=array_values(array_filter($locations,static fn($v)=>$v!==$pair));continue;}
					$valid=false;if($pair['type']==='country')$valid=isset($known[$pair['code']]);
					if($pair['type']==='state'){ $bits=explode(':',$pair['code'],2);$states=isset($known[$bits[0]])?$countries->get_states($bits[0]):[];$valid=count($bits)===2&&is_array($states)&&isset($states[$bits[1]]); }
					if($pair['type']==='postcode')$valid=strlen($pair['code'])<=40&&preg_match('/^[A-Z0-9][A-Z0-9 -]*\*?$/D',$pair['code'])===1;
					if(!$valid)return $this->error('input','Choose a known country/state or an uppercase postcode, optionally ending in *. Advanced ranges remain designer tasks.');
					if(!in_array($pair,$locations,true))$locations[]=$pair;
				}
				if(count($locations)>200)return $this->error('large','This zone already has the supported 200 rules.',409);
			}
			if($name===$before['zone']['zone_name']&&$order===(int)$before['zone']['zone_order']&&$locations===$before['locations'])return $this->zone_settings($id);
			$zone=new \WC_Shipping_Zone($id);$zone->set_zone_name($name);$zone->set_zone_order($order);if($locations!==$before['locations'])$zone->set_zone_locations(array_map(static fn($v)=>(object)$v,$locations));$saved=$zone->save();
			$after=$this->zone_state($id);if((int)$saved!==$id||is_wp_error($after)||$after['zone']['zone_name']!==$name||(int)$after['zone']['zone_order']!==$order||$after['locations']!==$locations||$after['methods']!==$before['methods'])return $this->error('readback','Saved zone rules differ. Refresh and review before retrying.',409);
			return $this->zone_settings($id);
		}catch(\Throwable $e){return $this->error('write','Zone saving could not be confirmed. Refresh and review.',409);}finally{$wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock));}
	}

	private function creation_state(){
		if(!$this->allowed())return $this->error('forbidden','Delivery creation is unavailable.',403);global $wpdb;
		$zones=$this->rows("SELECT zone_id, zone_name, zone_order FROM {$wpdb->prefix}woocommerce_shipping_zones ORDER BY zone_id ASC LIMIT 201");if(is_wp_error($zones))return $zones;
		if(count($zones)>=200)return $this->error('large','Your designer manages creation when the store has 200 or more zones.',409);
		return ['zones'=>$zones,'expected'=>hash_hmac('sha256',serialize($zones),wp_salt('nonce'))];
	}
	public function creation(){
		$state=$this->creation_state();if(is_wp_error($state))return $state;$countries=[];foreach(array_slice((new \WC_Countries())->get_countries(),0,300,true)as$code=>$name)$countries[]=['code'=>$code,'name'=>Task_Workspace_UI::text($name,120)];
		return ['countries'=>$countries,'canEdit'=>$this->editable(),'expected'=>$state['expected']];
	}
	private function start_creation_transaction(){
		global $wpdb;$names=array_map(static fn($v)=>$wpdb->prefix.$v,['woocommerce_shipping_zones','woocommerce_shipping_zone_locations','woocommerce_shipping_zone_methods','options']);
		$rows=$this->rows($wpdb->prepare('SELECT TABLE_NAME, ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN (%s,%s,%s,%s)',...$names));
		if(is_wp_error($rows)||count($rows)!==4)return false;foreach($rows as$r)if(strtoupper((string)$r['ENGINE'])!=='INNODB')return false;
		// SET TRANSACTION fails inside an active transaction instead of committing it.
		if($wpdb->query('SET TRANSACTION ISOLATION LEVEL READ COMMITTED')===false)return false;
		return $wpdb->query('START TRANSACTION')!==false;
	}
	private function clear_creation_cache(int $instance,string $kind): void {
		if($instance>0)wp_cache_delete('woocommerce_'.$kind.'_'.$instance.'_settings','options');wp_cache_delete('alloptions','options');wp_cache_delete('notoptions','options');
		\WC_Cache_Helper::invalidate_cache_group('shipping_zones');\WC_Cache_Helper::get_transient_version('shipping',true);
	}
	public function create_delivery(array $input,int $existingZone=0){
		if(!$this->allowed()||!$this->editable())return $this->error('forbidden','Your account cannot create delivery zones or methods.',403);
		$keys=$existingZone>0?['kind','title','cost','expected','acceptEffect']:['name','order','country','postcode','kind','title','cost','expected','acceptEffect'];
		if(array_diff(array_keys($input),$keys)||($input['acceptEffect']??false)!==true||!is_string($input['expected']??null)||!is_string($input['title']??null)||$input['title']===''||strlen($input['title'])>240||sanitize_text_field($input['title'])!==$input['title']||!is_string($input['kind']??null)||!isset(self::CORE[$input['kind']]))return $this->error('input','Review the new delivery option and confirm its effect.');
		$kind=$input['kind'];if(!is_string($input['cost']??null)||($kind!=='free_shipping'&&(!$this->plain_amount($input['cost'])||$input['cost']===''))||($kind==='free_shipping'&&$input['cost']!==''))return $this->error('input','Enter an explicit nonnegative charge, including zero. Free shipping has no charge field.');
		$locations=[];if(!$existingZone){$countries=(new \WC_Countries())->get_countries();if(!is_string($input['name']??null)||$input['name']===''||strlen($input['name'])>240||sanitize_text_field($input['name'])!==$input['name']||!is_int($input['order']??null)||$input['order']<0||$input['order']>1000000||!is_string($input['country']??null)||!isset($countries[$input['country']])||!is_string($input['postcode']??null))return $this->error('input','Choose a zone name, integer priority and known country.');
			$locations[]=['type'=>'country','code'=>$input['country']];if($input['postcode']!==''){if(strlen($input['postcode'])>40||preg_match('/^[A-Z0-9][A-Z0-9 -]*\*?$/D',$input['postcode'])!==1)return $this->error('input','Use an uppercase postcode or trailing-star prefix.');$locations[]=['type'=>'postcode','code'=>$input['postcode']];}}
		global $wpdb;$lock='kiwe-zone-'.substr(hash('sha256',$wpdb->prefix.':'.($existingZone?:'create')),0,32);if('1'!==(string)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 0)',$lock)))return $this->error('busy','Another delivery creation is running. Refresh and review.',409);
		$transaction=false;$instance=0;$zoneId=$existingZone;
		try{
			$before=$existingZone?$this->zone_state($existingZone):$this->creation_state();if(is_wp_error($before))return $before;if(!hash_equals($before['expected'],$input['expected']))return $this->error('stale','Delivery setup changed. Refresh before creating another option.',409);
			if($existingZone&&count($before['methods'])>=100)return $this->error('large','Your designer manages zones with 100 methods.',409);
			if(!$existingZone)foreach($before['zones']as$z)if(strcasecmp($z['zone_name'],$input['name'])===0)return $this->error('duplicate','A zone already uses this name. Open it from Delivery zones.',409);
			$classes=\WC_Shipping::instance()->get_shipping_method_class_names();$native=['flat_rate'=>'WC_Shipping_Flat_Rate','free_shipping'=>'WC_Shipping_Free_Shipping','local_pickup'=>'WC_Shipping_Local_Pickup'];if(($classes[$kind]??null)!==$native[$kind])return $this->error('extension','This delivery provider is customized. Ask your designer to create it.',403);
			$transaction=$this->start_creation_transaction();if(!$transaction)return $this->error('transaction','Safe delivery creation needs native InnoDB tables and an available transaction. Ask your designer to review this hosting setup.',409);
			$zone=new \WC_Shipping_Zone($existingZone?:null);if(!$existingZone){$zone->set_zone_name($input['name']);$zone->set_zone_order($input['order']);$zone->set_zone_locations(array_map(static fn($v)=>(object)$v,$locations));$zoneId=(int)$zone->save();if(!$zoneId)throw new \RuntimeException('zone');}
			$instance=(int)$zone->add_shipping_method($kind);if(!$instance)throw new \RuntimeException('method');
			$rate=$this->rates($zoneId,$instance);if(is_wp_error($rate))throw new \RuntimeException('read');
			$changes=$kind==='free_shipping'?['title'=>$input['title'],'requires'=>'coupon','min_amount'=>'0','ignore_discounts'=>'no']:['title'=>$input['title'],'cost'=>$input['cost'],'tax_status'=>'taxable'];
			$rate=$this->save_rates($zoneId,$instance,['changes'=>$changes,'expected'=>$rate['expected'],'acceptEffect'=>true]);if(is_wp_error($rate))throw new \RuntimeException('rates');
			if($existingZone){$rate=$this->save($zoneId,$instance,['enabled'=>false,'expected'=>$rate['expected'],'acceptEffect'=>true]);if(is_wp_error($rate))throw new \RuntimeException('disable');}
			$after=$this->zone_state($zoneId);if(is_wp_error($after))throw new \RuntimeException('readback');$new=array_values(array_filter($after['methods'],static fn($m)=>(int)$m['instance_id']===$instance));$other=array_values(array_filter($after['methods'],static fn($m)=>(int)$m['instance_id']!==$instance));
			if(count($new)!==1||$new[0]['method_id']!==$kind||(bool)$new[0]['is_enabled']!==!$existingZone||($existingZone&&($other!==$before['methods']||$after['zone']!==$before['zone']||$after['locations']!==$before['locations']))||(!$existingZone&&($other!==[]||$after['locations']!==$locations||$after['zone']['zone_name']!==$input['name']||(int)$after['zone']['zone_order']!==$input['order'])))throw new \RuntimeException('preservation');
			if($wpdb->query('COMMIT')===false)throw new \RuntimeException('commit');$transaction=false;$this->clear_creation_cache($instance,$kind);
			$confirmed=$this->rates($zoneId,$instance);if(is_wp_error($confirmed)||$confirmed['enabled']!==!$existingZone)return $this->error('uncertain','Creation could not be confirmed. Refresh the zones before trying again.',409);
			return ['id'=>$zoneId,'name'=>Task_Workspace_UI::text($after['zone']['zone_name'],240),'instanceId'=>$instance,'enabled'=>$confirmed['enabled'],'expected'=>$after['expected']];
		}catch(\Throwable $e){return $this->error('creation','Delivery creation could not be confirmed. Refresh the zone list before trying again.',409);}finally{if($transaction){$wpdb->query('ROLLBACK');$this->clear_creation_cache($instance,$kind);}$wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock));}
	}
}
