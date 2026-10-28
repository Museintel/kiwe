<?php

namespace DSA\Access;

use DSA\Utilities\Origin_Checker;

if ( ! defined( 'ABSPATH' ) ) exit;

/** Small native WooCommerce task set; no storefront hooks or background work. */
final class Commerce_App_Service {
	public const PAGE = 'kiwe-store';
	public const PAGE_SIZE = 20;
	public static function enabled(): bool { return defined( 'KIWE_REACT_WORKSPACE' ) && true === KIWE_REACT_WORKSPACE && \DSA\Apps\Store_App_Service::enabled(); }
	public static function url( string $area = 'products' ): string {
		return add_query_arg( [ 'page'=>self::PAGE, 'area'=>in_array( $area, [ 'products', 'orders', 'settings', 'saved-carts' ], true ) ? $area : 'products' ], admin_url( 'admin.php' ) );
	}
	public function register(): void {
		if ( ! self::enabled() ) return;
		add_action( 'rest_api_init', [ $this, 'routes' ] );
		if ( is_admin() ) {
			add_action( 'admin_menu', [ $this, 'menu' ] );
			add_action( 'load-toplevel_page_' . self::PAGE, [ $this, 'document' ], 1 );
		}
	}
	public function menu(): void {
		if ( $this->can_use() ) add_menu_page( __( 'Store', 'dsa' ), __( 'Store', 'dsa' ), 'read', self::PAGE, [ $this, 'render_fallback' ], 'dashicons-store', 33 );
	}
	public function render_fallback(): void {
		if ( ! $this->can_use() ) return;
		echo '<div class="wrap"><h1>' . esc_html__( 'Store', 'dsa' ) . '</h1><p>' . esc_html__( 'The store workspace could not open. Reload this page or contact your site manager.', 'dsa' ) . '</p></div>';
	}
	private function can_use(): bool { return is_user_logged_in() && ( current_user_can( 'edit_products' ) || $this->can_order() ); }
	private function can_coupon(): bool { return current_user_can( 'edit_shop_coupons' ) && current_user_can( 'edit_others_shop_coupons' ); }

	private function coupon_customer_state(int $id) {
		$record=$this->coupon_record($id);if(is_wp_error($record))return $record;
		if(!$record['editable'])return $this->error('coupon_managed','This coupon is read-only in Kiwe.',403);
		$coupon=new \WC_Coupon($id);$rules=array_values($coupon->get_email_restrictions('edit'));
		$other=[$coupon->get_product_ids('edit'),$coupon->get_excluded_product_ids('edit'),$coupon->get_product_categories('edit'),$coupon->get_excluded_product_categories('edit')];
		return ['record'=>$record,'rules'=>$rules,'preserved'=>hash('sha256',wp_json_encode([$other,array_diff_key($record,array_flip(['expected','preserved','policyPreserved','restrictionCounts','usageCount']))]))];
	}
	public function read_coupon_customers(\WP_REST_Request $request): \WP_REST_Response {
		if('GET'!==$request->get_method()||!$this->permission($request))return $this->response($this->error('forbidden','Coupon customer rules are unavailable.',403));
		return $this->response($this->coupon_customers_record(Task_Workspace_UI::id($request->get_url_params()['id']??0),$request->get_query_params()));
	}
	public function coupon_customers_record(int $id,array $input=[]) {
		$state=$this->coupon_customer_state($id);if(is_wp_error($state))return $state;
		$page=max(1,min(1000,(int)($input['page']??1)));$search=Task_Workspace_UI::text($input['search']??'',100);
		$matches=$search===''?$state['rules']:array_values(array_filter($state['rules'],static fn($v)=>stripos($v,$search)!==false));
		return ['id'=>$id,'name'=>$state['record']['name'],'expected'=>$state['record']['expected'],'items'=>array_slice($matches,($page-1)*20,20),'page'=>$page,'hasNext'=>count($matches)>$page*20,'count'=>count($state['rules']),'search'=>$search];
	}
	public function save_coupon_customers(\WP_REST_Request $request): \WP_REST_Response {
		if('POST'!==$request->get_method()||!$this->permission($request))return $this->response($this->error('forbidden','Coupon customer rules are unavailable.',403));
		$input=$request->get_json_params();return $this->response($this->save_coupon_customers_record(Task_Workspace_UI::id($request->get_url_params()['id']??0),is_array($input)?$input:[]));
	}
	public function save_coupon_customers_record(int $id,array $input) {
		$state=$this->coupon_customer_state($id);if(is_wp_error($state))return $state;
		if(array_diff(array_keys($input),['operation','rules','expected'])||!in_array($input['operation']??null,['add','remove'],true)||!is_array($input['rules']??null)||!array_is_list($input['rules'])||count($input['rules'])<1||count($input['rules'])>20)return $this->error('customer_input','Choose one to twenty email rules.');
		if(!is_string($input['expected']??null)||!hash_equals($state['record']['expected'],$input['expected']))return $this->error('stale','The coupon or its rules changed. Reload and review.',409);
		$add=$input['operation']==='add';$rules=[];
		foreach($input['rules']as$value){
			if(!is_string($value)||strlen($value)>254)return $this->error('customer_input','Use a valid email rule.');
			if($add){$value=strtolower(trim($value));if(!$value||sanitize_email($value)!==$value||!is_email($value)||str_contains(substr($value,strrpos($value,'@')+1),'*'))return $this->error('customer_input','Use an email address or a local-part wildcard at an exact domain, such as *@company.com.');}
			elseif(!in_array($value,$state['rules'],true))return $this->error('stale','A selected email rule is no longer saved. Reload and review.',409);
			$rules[]=$value;
		}
		$next=$add?array_values(array_unique(array_merge($state['rules'],$rules))):array_values(array_diff($state['rules'],$rules));
		if($next===$state['rules'])return $state['record'];if($add&&count($next)>max(1000,count($state['rules'])))return $this->error('customer_limit','This coupon has reached one thousand email rules. Remove rules or ask your site designer to review it.');
		try{$coupon=new \WC_Coupon($id);$coupon->set_email_restrictions($next);if(array_values($coupon->get_email_restrictions('edit'))!==$next)return $this->error('customer_legacy','Some saved rules need your site designer to review them before editing.',409);$saved=$coupon->save();}catch(\Throwable $e){return $this->error('coupon_write','Customer rules could not be confirmed. Reload and review before retrying.',409);}
		$after=$this->coupon_customer_state($id);if(!$saved||is_wp_error($after)||$after['rules']!==$next||!hash_equals($state['preserved'],$after['preserved']))return $this->error('coupon_readback','Saved customer rules differ. Reload and review before retrying.',409);
		return $after['record'];
	}


	private const COUPON_TARGETS=['products'=>'product_ids','excludedProducts'=>'excluded_product_ids','categories'=>'product_categories','excludedCategories'=>'excluded_product_categories'];
	private function coupon_target_state(int $id,string $group) {
		$record=$this->coupon_record($id);if(is_wp_error($record))return $record;
		if(!isset(self::COUPON_TARGETS[$group]))return $this->error('target_group','Choose a product or category targeting view.');
		$category=in_array($group,['categories','excludedCategories'],true);$tax=get_taxonomy('product_cat');
		if($category&&(!$tax||!current_user_can($tax->cap->assign_terms)))return $this->error('forbidden','Your account cannot assign product categories.',403);
		$coupon=new \WC_Coupon($id);$sets=[];foreach(self::COUPON_TARGETS as$key=>$field){$getter='get_'.$field;$sets[$key]=array_values(array_unique(array_map('intval',$coupon->$getter('edit'))));}
		$others=$sets;unset($others[$group]);$others['emails']=$coupon->get_email_restrictions('edit');
		$preserved=hash('sha256',wp_json_encode([$others,array_diff_key($record,array_flip(['expected','preserved','policyPreserved','restrictionCounts','usageCount']))]));
		return ['record'=>$record,'sets'=>$sets,'category'=>$category,'preserved'=>$preserved];
	}
	private function coupon_target_label(int $id,bool $category) {
		if($category){$term=get_term($id,'product_cat');return $term&&!is_wp_error($term)?['id'=>$id,'name'=>Task_Workspace_UI::text($term->name,240),'kind'=>'category']:null;}
		$product=wc_get_product($id);
		if(!$product||!in_array($product->get_status('edit'),['publish','draft','pending','private','future'],true))return null;
		if($product->is_type('variation')){
			if(!$product->get_parent_id()||!current_user_can('edit_post',$product->get_parent_id()))return null;
			// Woo registers variations without meta-cap mapping. Selecting a child
			// uses its parent's native authority, never a global edit_product grant.
			$mapped=map_meta_cap('edit_post',get_current_user_id(),$id);
			if($mapped!==['edit_product']&&!current_user_can('edit_post',$id))return null;
		}elseif(!current_user_can('edit_post',$id))return null;
		return ['id'=>$id,'name'=>Task_Workspace_UI::text($product->get_name('edit'),240),'kind'=>$product->is_type('variation')?'variation':'product'];
	}
	public function read_coupon_targets(\WP_REST_Request $request): \WP_REST_Response {
		if('GET'!==$request->get_method()||!$this->permission($request))return $this->response($this->error('forbidden','Coupon targeting is unavailable.',403));
		return $this->response($this->coupon_targets_record(Task_Workspace_UI::id($request->get_url_params()['id']??0),$request->get_query_params()));
	}
	public function coupon_targets_record(int $id,array $input) {
		$group=is_string($input['group']??null)?$input['group']:'products';$state=$this->coupon_target_state($id,$group);if(is_wp_error($state))return $state;
		$page=max(1,min(1000,(int)($input['page']??1)));$selectedPage=max(1,min(1000,(int)($input['selectedPage']??1)));$search=Task_Workspace_UI::text($input['search']??'',100);$selectedIds=$state['sets'][$group];
		$selected=[];foreach(array_slice($selectedIds,($selectedPage-1)*20,20)as$item){$label=$this->coupon_target_label($item,$state['category']);$selected[]=$label?($label+['available'=>true]):['id'=>$item,'name'=>'Unavailable selection #'.$item,'kind'=>$state['category']?'category':'product','available'=>false];}
		if($state['category']){$raw=get_terms(['taxonomy'=>'product_cat','hide_empty'=>false,'number'=>21,'offset'=>($page-1)*20,'search'=>$search,'orderby'=>'term_id','order'=>'ASC']);if(is_wp_error($raw))return $this->error('target_search','Categories could not be read. Reload and review.',409);$ids=array_map(static fn($v)=>(int)$v->term_id,$raw);}
		else{$query=new \WP_Query(['post_type'=>['product','product_variation'],'post_status'=>['publish','draft','pending','private','future'],'s'=>$search,'posts_per_page'=>21,'offset'=>($page-1)*20,'orderby'=>'ID','order'=>'ASC','fields'=>'ids','no_found_rows'=>true,'update_post_term_cache'=>false]);$ids=$query->posts;}
		$opposite=['products'=>'excludedProducts','excludedProducts'=>'products','categories'=>'excludedCategories','excludedCategories'=>'categories'][$group];$candidates=[];
		foreach(array_slice($ids,0,20)as$item){$label=$this->coupon_target_label((int)$item,$state['category']);if(!$label)continue;$candidates[]=$label+['selected'=>in_array((int)$item,$selectedIds,true),'opposite'=>in_array((int)$item,$state['sets'][$opposite],true)];}
		return ['id'=>$id,'name'=>$state['record']['name'],'expected'=>$state['record']['expected'],'type'=>$state['record']['type'],'editable'=>$state['record']['editable'],'reason'=>$state['record']['reason'],'group'=>$group,'counts'=>array_map('count',$state['sets']),'selected'=>$selected,'selectedPage'=>$selectedPage,'selectedHasNext'=>count($selectedIds)>$selectedPage*20,'candidates'=>$candidates,'page'=>$page,'hasNext'=>count($ids)>20,'search'=>$search];
	}
	public function save_coupon_targets(\WP_REST_Request $request): \WP_REST_Response {
		if('POST'!==$request->get_method()||!$this->permission($request))return $this->response($this->error('forbidden','Coupon targeting is unavailable.',403));
		$input=$request->get_json_params();return $this->response($this->save_coupon_targets_record(Task_Workspace_UI::id($request->get_url_params()['id']??0),is_array($input)?$input:[]));
	}
	public function save_coupon_targets_record(int $id,array $input) {
		if(array_diff(array_keys($input),['group','operation','ids','expected'])||!is_string($input['group']??null)||!in_array($input['operation']??null,['add','remove'],true)||!is_array($input['ids']??null)||!array_is_list($input['ids'])||count($input['ids'])<1||count($input['ids'])>20)return $this->error('target_input','Choose up to twenty selections to add or remove.');
		foreach($input['ids']as$item)if(!is_int($item)||$item<1)return $this->error('target_input','Choose valid product or category selections.');
		$group=$input['group'];$state=$this->coupon_target_state($id,$group);if(is_wp_error($state))return $state;
		if(!$state['record']['editable'])return $this->error('coupon_managed','This coupon is read-only in Kiwe.',403);
		if(!is_string($input['expected']??null)||!hash_equals($state['record']['expected'],$input['expected']))return $this->error('stale','The coupon or its rules changed. Reload and review.',409);
		$current=$state['sets'][$group];$ids=array_values(array_unique($input['ids']));$add=$input['operation']==='add';
		$opposite=['products'=>'excludedProducts','excludedProducts'=>'products','categories'=>'excludedCategories','excludedCategories'=>'categories'][$group];
		foreach($ids as$item){if($add){if(!$this->coupon_target_label($item,$state['category']))return $this->error('target_unavailable','A selection is unavailable for your account.',403);if(in_array($item,$state['sets'][$opposite],true))return $this->error('target_overlap','Remove the selection from the opposite rule first.');}elseif(!in_array($item,$current,true))return $this->error('stale','A selection is no longer in this rule. Reload and review.',409);}
		$next=$add?array_values(array_unique(array_merge($current,$ids))):array_values(array_diff($current,$ids));
		if($next===$current)return $state['record'];if($add&&count($next)>max(1000,count($current)))return $this->error('target_limit','This rule has reached the workspace limit of one thousand selections. Remove selections or ask your site designer to review it.');
		try{$coupon=new \WC_Coupon($id);$setter='set_'.self::COUPON_TARGETS[$group];$coupon->$setter($next);$saved=$coupon->save();}catch(\Throwable $e){return $this->error('coupon_write','Targeting could not be confirmed. Reload and review before retrying.',409);}
		$after=$this->coupon_target_state($id,$group);if(!$saved||is_wp_error($after)||!hash_equals($state['preserved'],$after['preserved']))return $this->error('coupon_readback','Saved targeting differs. Reload and review before retrying.',409);
		$actual=$after['sets'][$group];sort($actual);sort($next);if($actual!==$next)return $this->error('coupon_readback','Saved selections differ. Reload and review.',409);
		return $after['record'];
	}

	private function can_create_coupon(): bool {
		$type=get_post_type_object('shop_coupon');return $this->can_coupon()&&$type&&current_user_can($type->cap->create_posts);
	}
	private function coupon_code_exists(string $name,int $except=0): bool {
		// Woo's code lookup only finds published records; drafts must also prevent retries.
		$query=new \WP_Query(['post_type'=>'shop_coupon','post_status'=>['draft','pending','publish','private','future','trash'],'title'=>$name,'post__not_in'=>$except?[$except]:[],'fields'=>'ids','posts_per_page'=>1,'no_found_rows'=>true,'update_post_meta_cache'=>false,'update_post_term_cache'=>false]);return !empty($query->posts);
	}
	public function create_coupon( \WP_REST_Request $request ): \WP_REST_Response {
		if('POST'!==$request->get_method()||!$this->permission($request))return $this->response($this->error('forbidden','Coupon creation is unavailable.',403));
		$input=$request->get_json_params();return $this->response($this->create_coupon_record(is_array($input)?$input:[]));
	}
	public function create_coupon_record(array $input) {
		if(!$this->can_create_coupon()||!class_exists('\WC_Coupon'))return $this->error('forbidden','Your account cannot create coupons.',403);
		if(array_diff(array_keys($input),['name','type','amount','description'])||!is_string($input['name']??null)||!preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_-]{2,79}$/D',$input['name'])||str_starts_with(strtolower($input['name']),'kiwe-')||!in_array($input['type']??null,['percent','fixed_cart','fixed_product'],true)||!is_string($input['amount']??null)||!preg_match('/^\d{1,9}(?:\.\d{1,6})?$/D',$input['amount'])||(float)$input['amount']<=0||('percent'===$input['type']&&(float)$input['amount']>100)||!is_string($input['description']??null)||strlen($input['description'])>4000)return $this->error('coupon_input','Use a 3–80 character code with letters, numbers, hyphens or underscores and a positive discount. Kiwe-prefixed codes are reserved.');
		$name=wc_format_coupon_code($input['name']);$amount=wc_format_decimal($input['amount']);$description=sanitize_textarea_field($input['description']);
		try{
			$existing=new \WC_Coupon($name);if($this->coupon_code_exists($name)||$existing->get_id()||$existing->get_virtual('edit')||wc_get_coupon_id_by_code($name))return $this->error('coupon_exists','That code is already in use. Search your coupons before trying again.',409);
			$coupon=new \WC_Coupon();$coupon->set_code($name);$coupon->set_discount_type($input['type']);$coupon->set_amount($amount);$coupon->set_description($description);$coupon->set_status('draft');$id=(int)$coupon->save();
		}catch(\Throwable $e){return $this->error('coupon_write','Creation could not be confirmed. Search for the code before trying again.',409);}
		$after=$this->coupon_record($id);
		if(!$id||is_wp_error($after)||!$after['editable']||$after['name']!==$name||$after['type']!==$input['type']||$after['amount']!==$amount||$after['description']!==$description||$after['status']!=='draft')return $this->error('coupon_readback','Creation differs from your request. Search for the code and review it before retrying.',409);
		return $after;
	}
	public function save_coupon_policy( \WP_REST_Request $request ): \WP_REST_Response {
		if('POST'!==$request->get_method()||!$this->permission($request))return $this->response($this->error('forbidden','Coupon rules are unavailable.',403));
		$input=$request->get_json_params();return $this->response($this->save_coupon_policy_record(Task_Workspace_UI::id($request->get_url_params()['id']??0),is_array($input)?$input:[]));
	}
	public function save_coupon_policy_record(int $id,array $input) {
		$before=$this->coupon_record($id);if(is_wp_error($before))return $before;
		if(!$before['editable'])return $this->error('coupon_managed','This coupon is read-only in Kiwe.',403);
		if(!is_string($input['expected']??null)||!hash_equals($before['expected'],$input['expected']))return $this->error('stale','The coupon or its usage changed. Reload and review.',409);
		$fields=['status','expiresLocal','usageLimit','perUserLimit','minimum','maximum','individualUse','excludeSaleItems'];
		if(array_diff(array_keys($input),array_merge($fields,['freeShipping','itemLimit','expected']))||array_diff($fields,array_keys($input)))return $this->error('coupon_input','Review every promotion rule before saving.');
		// Older dashboard sessions may omit these additions; preserve native values.
		foreach(['freeShipping','itemLimit']as$key)if(!array_key_exists($key,$input))$input[$key]=$before[$key];
		$fields=array_merge($fields,['freeShipping','itemLimit']);
		if(!is_bool($input['freeShipping']))return $this->error('coupon_input','Choose whether this coupon allows free shipping.');
		if($input['itemLimit']!==$before['itemLimit']&&$input['itemLimit']!==null&&(!is_int($input['itemLimit'])||$input['itemLimit']<0||$input['itemLimit']>1000000))return $this->error('coupon_input','Item allowance must be a whole number from zero to one million, or blank.');
		// Woo's data store reloads a zero allowance as null (unlimited).
		if($input['itemLimit']===0)$input['itemLimit']=null;
		if($before['type']==='fixed_cart'&&$input['itemLimit']!==$before['itemLimit'])return $this->error('coupon_input','Item allowance can be changed on percentage or fixed product coupons.');
		if(!in_array($input['status'],['draft','publish'],true))return $this->error('coupon_input','Choose active or paused.');
		if($input['status']!==$before['status']&&!current_user_can('publish_shop_coupons'))return $this->error('forbidden','Your account cannot activate or pause coupons.',403);
		if($input['status']==='publish'&&$this->coupon_code_exists($before['name'],$id))return $this->error('coupon_exists','Another coupon has this code. Ask your site designer to resolve the duplicate before activation.',409);
		foreach(['usageLimit','perUserLimit']as$key)if(!is_int($input[$key])||$input[$key]<0||$input[$key]>1000000)return $this->error('coupon_input','Usage limits must be whole numbers from zero to one million.');
		foreach(['individualUse','excludeSaleItems']as$key)if(!is_bool($input[$key]))return $this->error('coupon_input','Choose the combination and sale-item rules.');
		foreach(['minimum','maximum']as$key){if(!is_string($input[$key])||($input[$key]!==''&&!preg_match('/^\d{1,9}(?:\.\d{1,6})?$/D',$input[$key])))return $this->error('coupon_input','Use a positive spend amount or leave it blank.');$input[$key]=$input[$key]===''?'':wc_format_decimal($input[$key]);}
		if((float)$input['maximum']>0&&(float)$input['minimum']>(float)$input['maximum'])return $this->error('coupon_input','Maximum spend cannot be below minimum spend.');
		$expires=$this->sale_date($input['expiresLocal']);if(is_wp_error($expires))return $expires;
		$desired=array_intersect_key($input,array_flip($fields));$same=true;foreach($desired as$key=>$value)if($before[$key]!==$value)$same=false;if($same)return $before;
		try{$coupon=new \WC_Coupon($id);$coupon->set_status($input['status']);$coupon->set_date_expires($expires);$coupon->set_usage_limit($input['usageLimit']);$coupon->set_usage_limit_per_user($input['perUserLimit']);$coupon->set_maximum_amount('');$coupon->set_minimum_amount($input['minimum']);$coupon->set_maximum_amount($input['maximum']);$coupon->set_individual_use($input['individualUse']);$coupon->set_exclude_sale_items($input['excludeSaleItems']);$coupon->set_free_shipping($input['freeShipping']);$coupon->set_limit_usage_to_x_items($input['itemLimit']);$saved=$coupon->save();}catch(\Throwable $e){return $this->error('coupon_write','Rule saving could not be confirmed. Reload and review.',409);}
		$after=$this->coupon_record($id);if(!$saved||is_wp_error($after)||!hash_equals($before['policyPreserved'],$after['policyPreserved']))return $this->error('coupon_readback','The saved promotion differs. Reload and review.',409);
		foreach($desired as$key=>$value)if($after[$key]!==$value)return $this->error('coupon_readback','The saved rule differs. Reload and review.',409);
		return $after;
	}
	public function read_coupons( \WP_REST_Request $request ): \WP_REST_Response {
		if ( 'GET' !== $request->get_method() || ! $this->permission( $request ) || ! $this->can_coupon() || ! class_exists( '\WC_Coupon' ) ) return $this->response( $this->error( 'forbidden', 'Coupons are unavailable for your account.', 403 ) );
		$input=$request->get_query_params();$page=max(1,min(1000,(int)($input['page']??1)));$search=Task_Workspace_UI::text($input['search']??'',200);
		$query=new \WP_Query(['post_type'=>'shop_coupon','post_status'=>['publish','draft','private'],'posts_per_page'=>21,'offset'=>($page-1)*20,'orderby'=>'ID','order'=>'DESC','s'=>$search,'no_found_rows'=>true,'update_post_term_cache'=>false]);
		$items=[];foreach(array_slice($query->posts,0,20)as$post){$record=$this->coupon_record((int)$post->ID);if(is_wp_error($record))continue;$items[]=array_intersect_key($record,array_flip(['id','name','type','amount','status','editable','reason','expected']));}
		return $this->response(['items'=>$items,'page'=>$page,'search'=>$search,'hasNext'=>count($query->posts)>20,'enabled'=>'yes'===get_option('woocommerce_enable_coupons','yes'),'canCreate'=>$this->can_create_coupon()]);
	}
	public function read_coupon( \WP_REST_Request $request ): \WP_REST_Response {
		if('GET'!==$request->get_method()||!$this->permission($request))return $this->response($this->error('forbidden','Coupon editing is unavailable.',403));
		return $this->response($this->coupon_record(Task_Workspace_UI::id($request->get_url_params()['id']??0)));
	}
	public function save_coupon( \WP_REST_Request $request ): \WP_REST_Response {
		if('POST'!==$request->get_method()||!$this->permission($request))return $this->response($this->error('forbidden','Coupon editing is unavailable.',403));
		$body=$request->get_json_params();return $this->response($this->save_coupon_record(Task_Workspace_UI::id($request->get_url_params()['id']??0),is_array($body)?$body:[]));
	}
	public function coupon_record( int $id ) {
		if(!$this->can_coupon()||!current_user_can('edit_post',$id)||!class_exists('\WC_Coupon'))return $this->error('forbidden','Your account cannot manage this coupon.',403);
		$post=get_post($id);if(!$post||'shop_coupon'!==$post->post_type||!in_array($post->post_status,['publish','draft','private'],true))return $this->error('coupon','Choose an existing saved coupon.',404);
		try{$coupon=new \WC_Coupon($id);}catch(\Throwable $e){return $this->error('coupon','This coupon could not be read.',409);}
		if((int)$coupon->get_id()!==$id)return $this->error('coupon','The coupon record could not be confirmed.',409);
		$custom=[];foreach($coupon->get_meta_data()as$meta){$data=$meta->get_data();if(!in_array($data['key'],['_edit_lock','_edit_last'],true))$custom[]=$data;}
		$type=(string)$coupon->get_discount_type('edit');$code=(string)$coupon->get_code('edit');
		$editable=!$coupon->get_virtual('edit')&&!str_starts_with(strtolower($code),'kiwe-')&&!$custom&&in_array($type,['percent','fixed_cart','fixed_product'],true);
		$expiry=$coupon->get_date_expires('edit');
		$constraints=['productIds'=>$coupon->get_product_ids('edit'),'excludedProductIds'=>$coupon->get_excluded_product_ids('edit'),'categoryIds'=>$coupon->get_product_categories('edit'),'excludedCategoryIds'=>$coupon->get_excluded_product_categories('edit'),'emailRestrictions'=>$coupon->get_email_restrictions('edit')];
		$out=['id'=>$id,'name'=>$code,'type'=>$type,'amount'=>(string)$coupon->get_amount('edit'),'description'=>(string)$coupon->get_description('edit'),'status'=>$post->post_status,'currency'=>(string)get_option('woocommerce_currency',''),'expiresAt'=>$expiry?$expiry->getTimestamp():null,'usageCount'=>(int)$coupon->get_usage_count('edit'),'usageLimit'=>(int)$coupon->get_usage_limit('edit'),'perUserLimit'=>(int)$coupon->get_usage_limit_per_user('edit'),'itemLimit'=>$coupon->get_limit_usage_to_x_items('edit'),'individualUse'=>(bool)$coupon->get_individual_use('edit'),'freeShipping'=>(bool)$coupon->get_free_shipping('edit'),'excludeSaleItems'=>(bool)$coupon->get_exclude_sale_items('edit'),'minimum'=>(string)$coupon->get_minimum_amount('edit'),'maximum'=>(string)$coupon->get_maximum_amount('edit'),'restrictionCounts'=>array_map('count',$constraints),'editable'=>$editable,'reason'=>$editable?'':'This coupon uses automatic or extension rules. Ask your site designer to manage it.'];

		$out['expiresLocal']=$expiry?(new \DateTimeImmutable('@'.$expiry->getTimestamp()))->setTimezone(wp_timezone())->format('Y-m-d\TH:i:s'):'';
		$out['timezone']=wp_timezone()->getName();$out['canPublish']=current_user_can('publish_shop_coupons');
		$out['policyPreserved']=hash('sha256',wp_json_encode([$constraints,$custom,array_diff_key($out,array_flip(['status','expiresAt','expiresLocal','usageLimit','perUserLimit','minimum','maximum','individualUse','excludeSaleItems','freeShipping','itemLimit','usageCount','canPublish']))]));
		// Do not read get_data()/used_by or return customer email restrictions.
		$out['preserved']=hash('sha256',wp_json_encode([$constraints,$custom,array_diff_key($out,array_flip(['amount','description','usageCount','policyPreserved','canPublish']))]));
		$out['expected']=hash('sha256',wp_json_encode($out));return $out;
	}
	public function save_coupon_record( int $id, array $input ) {
		$before=$this->coupon_record($id);if(is_wp_error($before))return $before;
		if(!$before['editable'])return $this->error('coupon_managed','This coupon is read-only in Kiwe.',403);
		if(!is_string($input['expected']??null)||!hash_equals($before['expected'],$input['expected']))return $this->error('stale','The coupon or its usage changed. Reload and review before saving.',409);
		if(array_diff(array_keys($input),['amount','description','expected'])||!is_string($input['amount']??null)||!preg_match('/^\d{1,9}(?:\.\d{1,6})?$/D',$input['amount'])||(float)$input['amount']<=0||('percent'===$before['type']&&(float)$input['amount']>100)||!is_string($input['description']??null)||strlen($input['description'])>4000)return $this->error('coupon_input','Enter a positive amount (at most 100 for percent) and a description of up to 4000 bytes.');
		$amount=wc_format_decimal($input['amount']);$description=sanitize_textarea_field($input['description']);
		if($amount===$before['amount']&&$description===$before['description'])return $before;
		try{$coupon=new \WC_Coupon($id);$coupon->set_amount($amount);$coupon->set_description($description);$saved=$coupon->save();}catch(\Throwable $e){return $this->error('coupon_write','The coupon save could not be confirmed. Reload and review.',409);}
		$after=$this->coupon_record($id);if(!$saved||is_wp_error($after)||$after['amount']!==$amount||$after['description']!==$description||!hash_equals($before['preserved'],$after['preserved']))return $this->error('coupon_readback','The saved coupon differs. Reload and review before retrying.',409);
		return $after;
	}

	private function can_order(): bool { return current_user_can( 'edit_shop_orders' ) && current_user_can( 'edit_others_shop_orders' ); }
	private function error( string $code, string $message, int $status = 400 ): \WP_Error { return new \WP_Error( 'kiwe_commerce_' . $code, $message, [ 'status'=>$status ] ); }
	public function permission( \WP_REST_Request $request ): bool {
		if ( ! self::enabled() || ! $this->can_use() || ! in_array( $request->get_method(), [ 'GET','POST' ], true ) ) return false;
		if ( ! wp_verify_nonce( (string) $request->get_header( 'X-WP-Nonce' ), 'wp_rest' ) || ! Origin_Checker::is_same_site_request() ) return false;
		return 'POST' !== $request->get_method() || true === Origin_Checker::mutation_allowed( $request );
	}
	public function routes(): void {
		if ( self::enabled() && class_exists( '\DSA\Commerce\Discussion_Review_Adapter' ) ) register_rest_route( 'dsa/v1', '/workspace/commerce/review-policy', [ [ 'methods'=>'GET', 'callback'=>[ $this,'read_review_policy' ], 'permission_callback'=>[ $this,'permission' ] ], [ 'methods'=>'POST', 'callback'=>[ $this,'save_review_policy' ], 'permission_callback'=>[ $this,'permission' ] ] ] );
		register_rest_route('dsa/v1','/workspace/commerce/display-policy',[
			['methods'=>'GET','callback'=>[$this,'read_display_policy'],'permission_callback'=>[$this,'permission']],
			['methods'=>'POST','callback'=>[$this,'save_display_policy'],'permission_callback'=>[$this,'permission']],
		]);
		if ( !self::enabled() ) return;
		register_rest_route('dsa/v1','/workspace/commerce/products/(?P<id>[1-9][0-9]*)/options',[['methods'=>'GET','callback'=>[$this,'read_product_options'],'permission_callback'=>[$this,'permission']],['methods'=>'POST','callback'=>[$this,'change_product_option'],'permission_callback'=>[$this,'permission']]]);
		register_rest_route('dsa/v1','/workspace/commerce/products/(?P<id>[1-9][0-9]*)/options/attributes',['methods'=>'GET','callback'=>[$this,'read_product_option_choices'],'permission_callback'=>[$this,'permission']]);
		register_rest_route('dsa/v1','/workspace/commerce/products/(?P<id>[1-9][0-9]*)/variations',['methods'=>'GET','callback'=>[$this,'read_product_variations'],'permission_callback'=>[$this,'permission']]);
		register_rest_route('dsa/v1','/workspace/commerce/products/(?P<id>[1-9][0-9]*)/variations/(?P<child>0|[1-9][0-9]*)',[['methods'=>'GET','callback'=>[$this,'read_product_variation'],'permission_callback'=>[$this,'permission']],['methods'=>'POST','callback'=>[$this,'save_product_variation'],'permission_callback'=>[$this,'permission']]]);
		register_rest_route( 'dsa/v1','/workspace/commerce/attributes', [ [ 'methods'=>'GET','callback'=>[$this,'read_attributes'],'permission_callback'=>[$this,'permission'] ], [ 'methods'=>'POST','callback'=>[$this,'change_attribute'],'permission_callback'=>[$this,'permission'] ] ] );
		register_rest_route( 'dsa/v1','/workspace/commerce/attributes/(?P<id>[1-9][0-9]*)', [ 'methods'=>'POST','callback'=>[$this,'change_attribute'],'permission_callback'=>[$this,'permission'] ] );
		register_rest_route( 'dsa/v1','/workspace/commerce/attributes/(?P<id>[1-9][0-9]*)/values', [ [ 'methods'=>'GET','callback'=>[$this,'read_attribute_values'],'permission_callback'=>[$this,'permission'] ], [ 'methods'=>'POST','callback'=>[$this,'change_attribute_value'],'permission_callback'=>[$this,'permission'] ] ] );
		register_rest_route( 'dsa/v1','/workspace/commerce/attributes/(?P<id>[1-9][0-9]*)/values/(?P<term>[1-9][0-9]*)', [ 'methods'=>'POST','callback'=>[$this,'change_attribute_value'],'permission_callback'=>[$this,'permission'] ] );
		register_rest_route('dsa/v1','/workspace/commerce/tax',[['methods'=>'GET','callback'=>[$this,'read_tax'],'permission_callback'=>[$this,'permission']],['methods'=>'POST','callback'=>[$this,'save_tax'],'permission_callback'=>[$this,'permission']]]);
		register_rest_route('dsa/v1','/workspace/commerce/tax/classes',[['methods'=>'GET','callback'=>[$this,'read_tax_classes'],'permission_callback'=>[$this,'permission']],['methods'=>'POST','callback'=>[$this,'create_tax_class'],'permission_callback'=>[$this,'permission']]]);
		register_rest_route('dsa/v1','/workspace/commerce/tax/classes/(?P<slug>[a-z0-9][a-z0-9-]*)',['methods'=>'POST','callback'=>[$this,'delete_tax_class'],'permission_callback'=>[$this,'permission']]);
		register_rest_route('dsa/v1','/workspace/commerce/tax/rates/(?P<id>[1-9][0-9]*)/geography',[['methods'=>'GET','callback'=>[$this,'read_tax_geography'],'permission_callback'=>[$this,'permission']],['methods'=>'POST','callback'=>[$this,'save_tax_geography'],'permission_callback'=>[$this,'permission']]]);
		register_rest_route('dsa/v1','/workspace/commerce/tax/rates/(?P<id>[1-9][0-9]*)/rules',[['methods'=>'GET','callback'=>[$this,'read_tax_rules'],'permission_callback'=>[$this,'permission']],['methods'=>'POST','callback'=>[$this,'save_tax_rules'],'permission_callback'=>[$this,'permission']]]);
		register_rest_route('dsa/v1','/workspace/commerce/tax/rates/create',[['methods'=>'GET','callback'=>[$this,'read_tax_creation'],'permission_callback'=>[$this,'permission']],['methods'=>'POST','callback'=>[$this,'create_tax_rate'],'permission_callback'=>[$this,'permission']]]);
		register_rest_route('dsa/v1','/workspace/commerce/tax/rates/(?P<id>[1-9][0-9]*)',[['methods'=>'GET','callback'=>[$this,'read_tax_rate'],'permission_callback'=>[$this,'permission']],['methods'=>'POST','callback'=>[$this,'save_tax_rate'],'permission_callback'=>[$this,'permission']]]);
		register_rest_route('dsa/v1','/workspace/commerce/tax/rates/(?P<id>[1-9][0-9]*)/delete',['methods'=>'POST','callback'=>[$this,'delete_tax_rate'],'permission_callback'=>[$this,'permission']]);
		register_rest_route('dsa/v1','/workspace/commerce/tax/rates',['methods'=>'GET','callback'=>[$this,'read_tax_rates'],'permission_callback'=>[$this,'permission']]);
		register_rest_route('dsa/v1','/workspace/commerce/tax/rates/(?P<id>[1-9][0-9]*)/locations',['methods'=>'GET','callback'=>[$this,'read_tax_locations'],'permission_callback'=>[$this,'permission']]);
		register_rest_route('dsa/v1','/workspace/commerce/shipping',['methods'=>'GET','callback'=>[$this,'read_shipping'],'permission_callback'=>[$this,'permission']]);
		register_rest_route('dsa/v1','/workspace/commerce/shipping/classes',[['methods'=>'GET','callback'=>[$this,'read_shipping_class_catalog'],'permission_callback'=>[$this,'permission']],['methods'=>'POST','callback'=>[$this,'change_shipping_class'],'permission_callback'=>[$this,'permission']]]);
		register_rest_route('dsa/v1','/workspace/commerce/shipping/classes/(?P<id>[1-9][0-9]*)',['methods'=>'POST','callback'=>[$this,'change_shipping_class'],'permission_callback'=>[$this,'permission']]);
		register_rest_route('dsa/v1','/workspace/commerce/shipping/(?P<zone>0|[1-9][0-9]*)',['methods'=>'GET','callback'=>[$this,'read_shipping'],'permission_callback'=>[$this,'permission']]);
		register_rest_route('dsa/v1','/workspace/commerce/shipping/(?P<zone>0|[1-9][0-9]*)/methods/(?P<instance>[1-9][0-9]*)',[['methods'=>'GET','callback'=>[$this,'read_shipping'],'permission_callback'=>[$this,'permission']],['methods'=>'POST','callback'=>[$this,'save_shipping'],'permission_callback'=>[$this,'permission']]]);
		register_rest_route('dsa/v1','/workspace/commerce/shipping/(?P<zone>0|[1-9][0-9]*)/methods/(?P<instance>[1-9][0-9]*)/rates',[['methods'=>'GET','callback'=>[$this,'read_shipping_rates'],'permission_callback'=>[$this,'permission']],['methods'=>'POST','callback'=>[$this,'save_shipping_rates'],'permission_callback'=>[$this,'permission']]]);
		register_rest_route('dsa/v1','/workspace/commerce/shipping/(?P<zone>0|[1-9][0-9]*)/methods/(?P<instance>[1-9][0-9]*)/classes',[['methods'=>'GET','callback'=>[$this,'read_shipping_classes'],'permission_callback'=>[$this,'permission']],['methods'=>'POST','callback'=>[$this,'save_shipping_classes'],'permission_callback'=>[$this,'permission']]]);
		register_rest_route('dsa/v1','/workspace/commerce/shipping/(?P<zone>[1-9][0-9]*)/settings',[['methods'=>'GET','callback'=>[$this,'read_zone_settings'],'permission_callback'=>[$this,'permission']],['methods'=>'POST','callback'=>[$this,'save_zone_settings'],'permission_callback'=>[$this,'permission']]]);
		register_rest_route('dsa/v1','/workspace/commerce/shipping/create',[['methods'=>'GET','callback'=>[$this,'read_delivery_creation'],'permission_callback'=>[$this,'permission']],['methods'=>'POST','callback'=>[$this,'create_delivery'],'permission_callback'=>[$this,'permission']]]);
		register_rest_route('dsa/v1','/workspace/commerce/shipping/(?P<zone>[1-9][0-9]*)/methods/create',['methods'=>'POST','callback'=>[$this,'create_delivery'],'permission_callback'=>[$this,'permission']]);
		register_rest_route('dsa/v1','/workspace/commerce/coupons/(?P<id>[1-9][0-9]*)/customers',[['methods'=>'GET','callback'=>[$this,'read_coupon_customers'],'permission_callback'=>[$this,'permission']],['methods'=>'POST','callback'=>[$this,'save_coupon_customers'],'permission_callback'=>[$this,'permission']]]);


		register_rest_route('dsa/v1','/workspace/commerce/coupons/(?P<id>[1-9][0-9]*)/targets',[['methods'=>'GET','callback'=>[$this,'read_coupon_targets'],'permission_callback'=>[$this,'permission']],['methods'=>'POST','callback'=>[$this,'save_coupon_targets'],'permission_callback'=>[$this,'permission']]]);
		register_rest_route('dsa/v1','/workspace/commerce/coupons/create',['methods'=>'POST','callback'=>[$this,'create_coupon'],'permission_callback'=>[$this,'permission']]);
		register_rest_route('dsa/v1','/workspace/commerce/coupons/(?P<id>[1-9][0-9]*)/policy',['methods'=>'POST','callback'=>[$this,'save_coupon_policy'],'permission_callback'=>[$this,'permission']]);
		register_rest_route('dsa/v1','/workspace/commerce/coupons',['methods'=>'GET','callback'=>[$this,'read_coupons'],'permission_callback'=>[$this,'permission']]);
		register_rest_route('dsa/v1','/workspace/commerce/coupons/(?P<id>[1-9][0-9]*)',[['methods'=>'GET','callback'=>[$this,'read_coupon'],'permission_callback'=>[$this,'permission']],['methods'=>'POST','callback'=>[$this,'save_coupon'],'permission_callback'=>[$this,'permission']]]);
		register_rest_route('dsa/v1','/workspace/commerce/products/(?P<id>[1-9][0-9]*)/merchandising',[['methods'=>'GET','callback'=>[$this,'read_merchandising'],'permission_callback'=>[$this,'permission']],['methods'=>'POST','callback'=>[$this,'save_merchandising'],'permission_callback'=>[$this,'permission']]]);
		register_rest_route('dsa/v1','/workspace/commerce/products/(?P<id>[1-9][0-9]*)/merchandising/choices',['methods'=>'GET','callback'=>[$this,'merchandising_choices'],'permission_callback'=>[$this,'permission']]);
		register_rest_route('dsa/v1','/workspace/commerce/bestsellers/sync',['methods'=>'POST','callback'=>[$this,'sync_bestsellers'],'permission_callback'=>[$this,'permission']]);
		register_rest_route( 'dsa/v1', '/workspace/commerce/products/(?P<id>[1-9][0-9]*)/links/preview', [ 'methods'=>'POST', 'callback'=>[ $this,'preview_links' ], 'permission_callback'=>[ $this,'permission' ] ] );
		register_rest_route( 'dsa/v1', '/workspace/commerce/products/(?P<id>[1-9][0-9]*)/links', [ 'methods'=>'POST', 'callback'=>[ $this,'save_links' ], 'permission_callback'=>[ $this,'permission' ] ] );
		register_rest_route( 'dsa/v1', '/workspace/commerce/products/(?P<id>[1-9][0-9]*)/sale', [ 'methods'=>'GET', 'callback'=>[ $this,'read_sale' ], 'permission_callback'=>[ $this,'permission' ] ] );
		register_rest_route( 'dsa/v1', '/workspace/commerce/products/(?P<id>[1-9][0-9]*)/sale', [ 'methods'=>'POST', 'callback'=>[ $this,'save_sale' ], 'permission_callback'=>[ $this,'permission' ] ] );
		register_rest_route( 'dsa/v1', '/workspace/commerce/products/(?P<id>[1-9][0-9]*)/nutrition', [ 'methods'=>'GET', 'callback'=>[ $this,'read_nutrition' ], 'permission_callback'=>[ $this,'permission' ] ] );
		register_rest_route( 'dsa/v1', '/workspace/commerce/products/(?P<id>[1-9][0-9]*)/nutrition', [ 'methods'=>'POST', 'callback'=>[ $this,'save_nutrition' ], 'permission_callback'=>[ $this,'permission' ] ] );
		register_rest_route( 'dsa/v1', '/workspace/commerce/offer-policy', [ 'methods'=>'GET', 'callback'=>[ $this,'read_offer_policy' ], 'permission_callback'=>[ $this,'permission' ] ] );
		register_rest_route( 'dsa/v1', '/workspace/commerce/offer-policy', [ 'methods'=>'POST', 'callback'=>[ $this,'save_offer_policy' ], 'permission_callback'=>[ $this,'permission' ] ] );
		register_rest_route( 'dsa/v1', '/workspace/commerce/bestsellers/review', [ 'methods'=>'GET', 'callback'=>[ $this,'read_bestseller_review' ], 'permission_callback'=>[ $this,'permission' ] ] );
		register_rest_route( 'dsa/v1', '/workspace/commerce/bestsellers/adopt', [ 'methods'=>'POST', 'callback'=>[ $this,'adopt_bestseller_category' ], 'permission_callback'=>[ $this,'permission' ] ] );
		register_rest_route( 'dsa/v1', '/workspace/commerce/bestsellers', [ 'methods'=>'GET', 'callback'=>[ $this,'read_bestsellers' ], 'permission_callback'=>[ $this,'permission' ] ] );
		register_rest_route( 'dsa/v1', '/workspace/commerce/products/(?P<id>[1-9][0-9]*)/offer', [ 'methods'=>'GET', 'callback'=>[ $this,'read_offer' ], 'permission_callback'=>[ $this,'permission' ] ] );
		register_rest_route( 'dsa/v1', '/workspace/commerce/products/(?P<id>[1-9][0-9]*)/offer', [ 'methods'=>'POST', 'callback'=>[ $this,'save_offer' ], 'permission_callback'=>[ $this,'permission' ] ] );
		register_rest_route( 'dsa/v1', '/workspace/commerce', [ 'methods'=>'GET', 'callback'=>[ $this,'read' ], 'permission_callback'=>[ $this,'permission' ] ] );
		register_rest_route( 'dsa/v1', '/workspace/commerce/products/(?P<id>[1-9][0-9]*)', [ 'methods'=>'POST', 'callback'=>[ $this,'save_product' ], 'permission_callback'=>[ $this,'permission' ] ] );
		register_rest_route( 'dsa/v1', '/workspace/commerce/products', [ 'methods'=>'POST', 'callback'=>[ $this,'create_product' ], 'permission_callback'=>[ $this,'permission' ] ] );
		register_rest_route( 'dsa/v1', '/workspace/commerce/products/(?P<id>[1-9][0-9]*)/inventory', [ 'methods'=>'POST', 'callback'=>[ $this,'save_inventory' ], 'permission_callback'=>[ $this,'permission' ] ] );
		register_rest_route( 'dsa/v1', '/workspace/commerce/products/(?P<id>[1-9][0-9]*)/delivery', [ 'methods'=>'POST', 'callback'=>[ $this,'save_delivery' ], 'permission_callback'=>[ $this,'permission' ] ] );
		register_rest_route( 'dsa/v1', '/workspace/commerce/products/(?P<id>[1-9][0-9]*)/tax', [ [ 'methods'=>'GET', 'callback'=>[ $this,'read_product_tax' ], 'permission_callback'=>[ $this,'permission' ] ], [ 'methods'=>'POST', 'callback'=>[ $this,'save_product_tax' ], 'permission_callback'=>[ $this,'permission' ] ] ] );
		register_rest_route( 'dsa/v1', '/workspace/commerce/orders/(?P<id>[1-9][0-9]*)', [ 'methods'=>'GET', 'callback'=>[ $this,'read_order' ], 'permission_callback'=>[ $this,'permission' ] ] );
		register_rest_route( 'dsa/v1', '/workspace/commerce/orders/(?P<id>[1-9][0-9]*)/refunds', [ 'methods'=>'POST', 'callback'=>[ $this,'refund_order' ], 'permission_callback'=>[ $this,'permission' ] ] );
		register_rest_route( 'dsa/v1', '/workspace/commerce/orders/(?P<id>[1-9][0-9]*)/actions', [ 'methods'=>'POST', 'callback'=>[ $this,'order_action' ], 'permission_callback'=>[ $this,'permission' ] ] );
		register_rest_route( 'dsa/v1', '/workspace/commerce/orders/(?P<id>[1-9][0-9]*)/complete', [ 'methods'=>'POST', 'callback'=>[ $this,'complete_order' ], 'permission_callback'=>[ $this,'permission' ] ] );
	}
	public function read_merchandising(\WP_REST_Request $request): \WP_REST_Response {
		if($request->get_method()!=='GET'||!$this->permission($request))return $this->response($this->error('forbidden','Product relationships unavailable.',403));
		return $this->response((new Product_Merchandising_Workspace_Service())->review((int)$request->get_url_params()['id']));
	}
	public function read_review_policy( \WP_REST_Request $request ): \WP_REST_Response {
		return $this->response( 'GET' === $request->get_method() && $this->permission( $request ) && class_exists( '\DSA\Commerce\Discussion_Review_Adapter' ) ? \DSA\Commerce\Discussion_Review_Adapter::policy() : $this->error( 'forbidden', 'Review settings are unavailable.', 403 ) );
	}
	public function save_review_policy( \WP_REST_Request $request ): \WP_REST_Response {
		return $this->response( 'POST' === $request->get_method() && $this->permission( $request ) && class_exists( '\DSA\Commerce\Discussion_Review_Adapter' ) ? \DSA\Commerce\Discussion_Review_Adapter::save_policy( (array) $request->get_json_params() ) : $this->error( 'forbidden', 'Review settings are unavailable.', 403 ) );
	}
	public function merchandising_choices(\WP_REST_Request $request): \WP_REST_Response {
		if($request->get_method()!=='GET'||!$this->permission($request))return $this->response($this->error('forbidden','Product choices unavailable.',403));
		return $this->response((new Product_Merchandising_Workspace_Service())->choices((int)$request->get_url_params()['id'],$request->get_query_params()));
	}
	public function save_merchandising(\WP_REST_Request $request): \WP_REST_Response {
		if($request->get_method()!=='POST'||!$this->permission($request))return $this->response($this->error('forbidden','Product relationships unavailable.',403));
		$id=(int)$request->get_url_params()['id'];$input=$request->get_json_params();$after=(new Product_Merchandising_Workspace_Service())->change($id,is_array($input)?$input:[]);
		if(!is_wp_error($after))$after['product']=$this->project_product(wc_get_product($id));return $this->response($after);
	}
	public function sync_bestsellers(\WP_REST_Request $request): \WP_REST_Response {
		if($request->get_method()!=='POST'||!$this->permission($request)||!current_user_can('manage_product_terms')||!current_user_can('edit_product_terms'))return $this->response($this->error('forbidden','Collection maintenance unavailable.',403));
		$input=$request->get_json_params();$review=$this->bestseller_review_record();if(is_wp_error($review))return $this->response($review);
		if(!is_array($input)||array_diff(array_keys($input),['expected','confirmed'])||true!==($input['confirmed']??null)||!is_string($input['expected']??null))return $this->response($this->error('input','Confirm the reviewed collection maintenance.'));
		if(!$review['enabled']||!hash_equals($review['expected'],$input['expected']))return $this->response($this->error('stale','Store policy or categories changed. Reload before maintenance.',409));
		(new \DSA\Commerce\Linked_Products_Service(new \DSA\Settings()))->sync_bestseller_terms();
		return $this->response(['status'=>(new \DSA\Commerce\Store_Analytics_Service(new \DSA\Settings()))->bestseller_status(),'review'=>$this->bestseller_review_record()]);
	}
	public function links_preview_record( int $id, array $input ) {
		if ( ! function_exists( 'wc_get_product' ) || ! current_user_can( 'edit_products' ) || ! current_user_can( 'edit_post', $id ) ) return $this->error( 'forbidden', 'Your role cannot manage these product links.', 403 );
		$product = wc_get_product( $id );
		if ( ! $product || ! in_array( $product->get_type(), [ 'simple','variable' ], true ) ) return $this->error( 'type', 'This product cannot use the cross-sell task.', 409 );
		if ( ! is_bool( $input['sameCategory'] ?? null ) || ! is_array( $input['categoryIds'] ?? null ) || count( $input['categoryIds'] ) > 20 ) return $this->error( 'categories', 'Choose this product’s categories or up to 20 other categories.' );
		$categories = $input['sameCategory'] ? array_map( 'intval', $product->get_category_ids() ) : $input['categoryIds'];
		if ( count( $categories ) > 20 || ! array_is_list( $categories ) ) return $this->error( 'categories', 'Choose up to 20 distinct categories.' );
		foreach ( $categories as $category ) if ( ! is_int( $category ) || $category < 1 || ! term_exists( $category, 'product_cat' ) ) return $this->error( 'category', 'A selected product category is unavailable. Reload and choose again.' );
		if ( count( array_unique( $categories ) ) !== count( $categories ) ) return $this->error( 'categories', 'Choose distinct categories.' );
		$existing = array_values( array_unique( array_map( 'intval', (array) $product->get_cross_sell_ids() ) ) );
		if ( count( $existing ) > 100 ) return $this->error( 'limit', 'This product has more than 100 existing cross-sells. Ask your site manager to review its links.', 409 );
		$settings = new \DSA\Settings(); $config = $settings->get( 'commerce', [] );
		$linked = new \DSA\Commerce\Linked_Products_Service( $settings );
		$candidates = [];
		foreach ( array_slice( $linked->products_by_categories( $categories, [ $id ] ), 0, 50 ) as $candidate ) {
			$target = wc_get_product( $candidate );
			if ( ! $target || ! current_user_can( 'edit_post', $candidate ) || 'publish' !== $target->get_status() || 'hidden' === $target->get_catalog_visibility() || (int) $candidate === $id ) continue;
			$candidates[] = [ 'id'=>(int) $candidate, 'name'=>(string) $target->get_name(), 'alreadyLinked'=>in_array( (int) $candidate, $existing, true ) ];
		}
		$out = [ 'id'=>$id, 'name'=>(string) $product->get_name(), 'categoryIds'=>array_values( $categories ), 'existingIds'=>$existing, 'candidates'=>$candidates, 'linkedEnabled'=>! empty( $config['linked_products_enabled'] ), 'crossSellsEnabled'=>! empty( $config['cross_sells_enabled'] ), 'limit'=>50 ];
		$out['expected'] = hash( 'sha256', wp_json_encode( [ $out, self::fingerprint( $product ) ] ) );
		return $out;
	}
	public function preview_links( \WP_REST_Request $request ): \WP_REST_Response {
		if ( 'POST' !== $request->get_method() || ! $this->permission( $request ) ) return $this->response( $this->error( 'forbidden', 'Product link preview is unavailable.', 403 ) );
		$input = $request->get_json_params();
		return $this->response( $this->links_preview_record( Task_Workspace_UI::id( $request->get_url_params()['id'] ?? 0 ), is_array( $input ) ? $input : [] ) );
	}
	public function save_links( \WP_REST_Request $request ): \WP_REST_Response {
		if ( 'POST' !== $request->get_method() || ! $this->permission( $request ) ) return $this->response( $this->error( 'forbidden', 'Product link editing is unavailable.', 403 ) );
		$input = $request->get_json_params();
		return $this->response( $this->save_links_record( Task_Workspace_UI::id( $request->get_url_params()['id'] ?? 0 ), is_array( $input ) ? $input : [] ) );
	}
	public function save_links_record( int $id, array $input ) {
		$preview = $this->links_preview_record( $id, $input ); if ( is_wp_error( $preview ) ) return $preview;
		if ( ! is_string( $input['expected'] ?? null ) || ! hash_equals( $preview['expected'], $input['expected'] ) ) return $this->error( 'stale', 'The product links or category preview changed. Preview again before applying.', 409 );
		$selected = $input['selectedIds'] ?? null;
		if ( ! is_array( $selected ) || ! array_is_list( $selected ) || ! count( $selected ) || count( $selected ) > 50 ) return $this->error( 'selection', 'Select up to 50 distinct products from the preview.' );
		$allowed = array_column( $preview['candidates'], 'id' );
		foreach ( $selected as $candidate ) if ( ! is_int( $candidate ) || ! in_array( $candidate, $allowed, true ) ) return $this->error( 'selection', 'Choose only products in the current preview.' );
		if ( count( array_unique( $selected ) ) !== count( $selected ) ) return $this->error( 'selection', 'Choose distinct products.' );
		$wanted = array_values( array_unique( array_merge( $preview['existingIds'], $selected ) ) );
		if ( count( $wanted ) > 100 ) return $this->error( 'limit', 'Keep this product within 100 cross-sells; choose fewer additions.' );
		if ( $wanted === $preview['existingIds'] ) return $preview;
		$product = wc_get_product( $id );
		try { $product->set_cross_sell_ids( $wanted ); $saved = $product->save(); }
		catch ( \Throwable $error ) { return $this->error( 'links_write', 'The links could not be confirmed. Reload and review before trying again.', 409 ); }
		$readback = $saved ? wc_get_product( $id ) : false;
		if ( ! $readback || array_values( array_map( 'intval', $readback->get_cross_sell_ids() ) ) !== $wanted ) return $this->error( 'links_readback', 'Some product links were not saved. Reload and review before trying again.', 409 );
		$after = $this->links_preview_record( $id, $input );
		if ( ! is_wp_error( $after ) ) $after['product'] = $this->project_product( $readback );
		return $after;
	}
	public function read_bestsellers( \WP_REST_Request $request ): \WP_REST_Response {
		if ( 'GET' !== $request->get_method() || ! $this->permission( $request ) || ! current_user_can( 'edit_products' ) || ! function_exists( 'wc_get_product' ) ) return $this->response( $this->error( 'forbidden', 'Bestseller status is unavailable.', 403 ) );
		return $this->response( ( new \DSA\Commerce\Store_Analytics_Service( new \DSA\Settings() ) )->bestseller_status() );
	}

	/** Four exact native categories only. Reading never creates categories or calculates sales. */
	public function bestseller_review_record() {
		if ( ! function_exists( 'wc_get_product' ) || ! current_user_can( 'edit_products' ) ) return $this->error( 'forbidden', 'Bestseller review is unavailable.', 403 );
		$config = ( new \DSA\Settings() )->get( 'commerce', [] );
		$slug = sanitize_title( $config['bestseller_parent_slug'] ?? 'bestseller' );
		$rows = []; $parent = 0;
		foreach ( [ 'parent'=>'', 'week'=>'-week', 'month'=>'-month', 'year'=>'-year' ] as $period=>$suffix ) {
			$term = '' !== $slug ? get_term_by( 'slug', $slug . $suffix, 'product_cat' ) : false;
			if ( is_wp_error( $term ) ) return $this->error( 'read', 'Categories could not be read. Reload before making changes.', 409 );
			$id = $term ? (int) $term->term_id : 0;
			if ( 'parent' === $period ) $parent = $id;
			$markers = $id ? get_term_meta( $id, '_kiwe_bestseller_owner', false ) : [];
			$hierarchy = $term && (int) $term->parent === ( 'parent' === $period ? 0 : ( $parent ?: -1 ) );
			$state = ! $id ? 'missing' : ( ! $hierarchy ? 'hierarchy' : ( [] === $markers ? 'unowned' : ( [ $slug . ':' . $period ] === $markers ? 'owned' : 'conflict' ) ) );
			$rows[] = [ 'id'=>$id, 'period'=>$period, 'slug'=>$slug . $suffix, 'name'=>$term ? (string) $term->name : ucfirst( $period ), 'parentId'=>$term ? (int) $term->parent : 0, 'count'=>$term ? (int) $term->count : 0, 'state'=>$state ];
		}
		$out = [ 'enabled'=>! empty( $config['bestseller_enabled'] ), 'canAdopt'=>current_user_can( 'manage_product_terms' ) && current_user_can( 'edit_product_terms' ), 'rows'=>$rows ];
		$out['expected'] = hash( 'sha256', wp_json_encode( [ $out, $slug, (int) ( $config['bestseller_limit'] ?? 20 ) ] ) );
		return $out;
	}
	public function read_bestseller_review( \WP_REST_Request $request ): \WP_REST_Response {
		if ( 'GET' !== $request->get_method() || ! $this->permission( $request ) ) return $this->response( $this->error( 'forbidden', 'Bestseller review is unavailable.', 403 ) );
		return $this->response( $this->bestseller_review_record() );
	}
	public function adopt_bestseller_category( \WP_REST_Request $request ): \WP_REST_Response {
		if ( 'POST' !== $request->get_method() || ! $this->permission( $request ) ) return $this->response( $this->error( 'forbidden', 'Category approval is unavailable.', 403 ) );
		$input = $request->get_json_params();
		return $this->response( $this->adopt_bestseller_record( is_array( $input ) ? $input : [] ) );
	}
	public function adopt_bestseller_record( array $input ) {
		if ( ! current_user_can( 'edit_products' ) || ! current_user_can( 'manage_product_terms' ) || ! current_user_can( 'edit_product_terms' ) ) return $this->error( 'forbidden', 'Your account can view these categories but cannot approve their management.', 403 );
		if ( ! is_int( $input['id'] ?? null ) || $input['id'] < 1 || true !== ( $input['acceptMembershipChanges'] ?? null ) ) return $this->error( 'approval', 'Select one existing category and acknowledge future membership changes.' );
		global $wpdb;
		$lock = 'kiwe-bestseller-' . substr( hash( 'sha256', $wpdb->prefix ), 0, 32 );
		if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', $lock ) ) ) return $this->error( 'busy', 'Bestseller maintenance is running. Reload and review after it finishes.', 409 );
		try {
			$review = $this->bestseller_review_record(); if ( is_wp_error( $review ) ) return $review;
			if ( ! is_string( $input['expected'] ?? null ) || ! hash_equals( $review['expected'], $input['expected'] ) ) return $this->error( 'stale', 'Category details or store policy changed. Reload the review before approving.', 409 );
			$selected = null; foreach ( $review['rows'] as $row ) if ( $row['id'] === $input['id'] ) $selected = $row;
			if ( ! $selected || 'unowned' !== $selected['state'] || ! current_user_can( 'edit_term', $selected['id'] ) ) return $this->error( 'category', 'This category cannot be approved here. Review its current ownership and hierarchy.', 409 );
			$value = $review['rows'][0]['slug'] . ':' . $selected['period'];
			if ( ! add_term_meta( $selected['id'], '_kiwe_bestseller_owner', $value, true ) || [ $value ] !== get_term_meta( $selected['id'], '_kiwe_bestseller_owner', false ) ) return $this->error( 'write', 'Category approval could not be confirmed. Reload and review before retrying.', 409 );
			$after = $this->bestseller_review_record();
			if ( is_wp_error( $after ) ) return $after;
			foreach ( $after['rows'] as $row ) if ( $row['id'] === $selected['id'] && 'owned' === $row['state'] ) return $after;
			return $this->error( 'changed', 'The category changed during approval. Reload and ask your site manager to review.', 409 );
		} catch ( \Throwable $error ) {
			return $this->error( 'write', 'Category approval could not be confirmed. Reload and review before retrying.', 409 );
		} finally { $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) ); }
	}


 public static function display_policy_fields(): array {
  return [
   'cart_badges_enabled'=>['label'=>'Show stock messages','type'=>'bool'],
   'stock_badge_alert_threshold'=>['label'=>'Low stock at or below','type'=>'int','min'=>1,'max'=>999],
   'stock_badge_urgent_threshold'=>['label'=>'Very low stock at or below','type'=>'int','min'=>1,'max'=>999],
   'stock_badge_alert_text'=>['label'=>'Low-stock message','type'=>'text','max'=>160],
   'stock_badge_urgent_text'=>['label'=>'Very-low-stock message','type'=>'text','max'=>160],
   'fbt_title'=>['label'=>'Frequently bought together title','type'=>'text','max'=>160],
   'fbt_max_products'=>['label'=>'Maximum related products','type'=>'int','min'=>1,'max'=>12],
   'fbt_show_out_of_stock'=>['label'=>'Include unavailable related products','type'=>'bool'],
   'cross_sells_product_panel_enabled'=>['label'=>'Show the companion panel on product pages','type'=>'bool'],
   'add_to_cart_mode'=>['label'=>'Product add button','type'=>'choice','options'=>[['default','Theme default'],['plus_only','Plus button'],['quantity','Quantity and add'],['replace','Replace the theme button']]],
   'first_cart_confetti_enabled'=>['label'=>'Celebrate the first cart addition','type'=>'bool'],
  ];
 }
 public function display_policy_record() {
  if(!self::enabled()||!function_exists('wc_get_product')||!current_user_can('edit_products'))return $this->error('forbidden','Product display is unavailable.',403);
  $raw=get_option(DSA_OPTION_SETTINGS,null);if(!is_array($raw))return $this->error('settings','Reload the store before changing product display.',409);
  $fields=self::display_policy_fields();$values=array_intersect_key((new \DSA\Settings())->get('commerce',[]),$fields);
  return ['schema'=>'kiwe.store-display.v1','canEdit'=>current_user_can('kiwe_manage_context'),'fields'=>$fields,'values'=>$values,'expected'=>hash_hmac('sha256',serialize($raw),wp_salt('nonce'))];
 }
 public function read_display_policy(\WP_REST_Request $request): \WP_REST_Response {
  return $this->response($this->permission($request)&&'GET'===$request->get_method()?$this->display_policy_record():$this->error('forbidden','Product display is unavailable.',403));
 }
 public function save_display_policy(\WP_REST_Request $request): \WP_REST_Response {
  return $this->response($this->permission($request)&&'POST'===$request->get_method()?$this->save_display_policy_record((array)$request->get_json_params()):$this->error('forbidden','Product display is unavailable.',403));
 }
 public function save_display_policy_record(array $body) {
  if(!self::enabled()||!function_exists('wc_get_product')||!current_user_can('edit_products')||!current_user_can('kiwe_manage_context'))return $this->error('forbidden','Your role cannot change product display.',403);
  $before=$this->display_policy_record();if(is_wp_error($before))return $before;
  if(array_diff(array_keys($body),['expected','changes'])||!is_string($body['expected']??null)||!hash_equals($before['expected'],$body['expected']))return $this->error('stale','Store settings changed. Reload and review before saving.',409);
  $changes=$body['changes']??null;$fields=self::display_policy_fields();if(!is_array($changes)||!$changes||count($changes)>11||array_diff(array_keys($changes),array_keys($fields)))return $this->error('display','Choose a product display setting.');
  foreach($changes as $key=>&$value){$f=$fields[$key];$valid=match($f['type']){'bool'=>is_bool($value),'int'=>is_int($value)&&$value>=$f['min']&&$value<=$f['max'],'text'=>is_string($value)&&strlen($value)<=$f['max'],'choice'=>is_string($value)&&in_array($value,array_column($f['options'],0),true),default=>false};if(!$valid)return $this->error('display','A product display choice is invalid.');if('text'===$f['type'])$value=sanitize_text_field($value);}unset($value);
  $effective=array_replace($before['values'],$changes);if($effective['stock_badge_urgent_threshold']>$effective['stock_badge_alert_threshold'])return $this->error('display','The very-low-stock threshold must be at or below the low-stock threshold.');
  if(!array_diff_assoc($changes,$before['values']))return $before;
  $raw=get_option(DSA_OPTION_SETTINGS,null);if(!is_array($raw)||!hash_equals($before['expected'],hash_hmac('sha256',serialize($raw),wp_salt('nonce'))))return $this->error('stale','Store settings changed. Reload and review before saving.',409);
  if(!(new \DSA\Settings())->compare_and_update_selected($raw,['commerce'=>$changes]))return $this->error('stale','Store settings changed during saving.',409);
  do_action('litespeed_purge_all');$after=$this->display_policy_record();if(is_wp_error($after))return $after;foreach($changes as $key=>$value)if($after['values'][$key]!==$value)return $this->error('readback','Product display could not be confirmed. Reload before retrying.',409);return $after;
 }

 public function offer_policy_record() {
  if ( ! function_exists( 'wc_get_product' ) || ! current_user_can( 'edit_products' ) ) return $this->error( 'forbidden', 'Cart display is unavailable.', 403 );
  $raw = get_option( DSA_OPTION_SETTINGS, [] );
  if ( ! is_array( $raw ) || ( isset( $raw['commerce'] ) && ! is_array( $raw['commerce'] ) ) ) return $this->error( 'settings', 'Store settings need site-manager review before editing.', 409 );
  $settings = new \DSA\Settings(); $config = $settings->get( 'commerce', [] );
  $out = [ 'canEdit'=>current_user_can( 'kiwe_manage_context' ), 'switches'=>[], 'context'=>[] ];
  foreach ( [ 'cart_show_delivery','cart_show_fbt' ] as $key ) $out['switches'][$key] = ! empty( $config[$key] );
  // Opaque site-specific fingerprint includes unrelated option changes without exposing private settings.
  $out['expected'] = hash_hmac( 'sha256', serialize( [ $raw, $out ] ), wp_salt( 'nonce' ) );
  return $out;
 }
 public function read_offer_policy( \WP_REST_Request $request ): \WP_REST_Response {
  if ( 'GET' !== $request->get_method() || ! $this->permission( $request ) ) return $this->response( $this->error( 'forbidden', 'Cart display is unavailable.', 403 ) );
  return $this->response( $this->offer_policy_record() );
 }
 public function save_offer_policy( \WP_REST_Request $request ): \WP_REST_Response {
  if ( 'POST' !== $request->get_method() || ! $this->permission( $request ) ) return $this->response( $this->error( 'forbidden', 'Cart display editing is unavailable.', 403 ) );
  $input = $request->get_json_params();
  return $this->response( $this->save_offer_policy_record( is_array( $input ) ? $input : [] ) );
 }
 public function save_offer_policy_record( array $input ) {
  if ( ! function_exists( 'wc_get_product' ) || ! current_user_can( 'edit_products' ) || ! current_user_can( 'kiwe_manage_context' ) ) return $this->error( 'forbidden', 'Your account can view these settings but cannot change Cart display.', 403 );
  $key = $input['key'] ?? null;
  if ( ! is_string( $key ) || ! in_array( $key, [ 'cart_show_delivery','cart_show_fbt' ], true ) || ! is_bool( $input['value'] ?? null ) || true !== ( $input['acceptEffect'] ?? null ) || array_diff( array_keys( $input ), [ 'key','value','expected','acceptEffect' ] ) ) return $this->error( 'policy', 'Choose one supported switch and confirm its effect.' );
  global $wpdb;
  $lock = 'kiwe-offer-policy-' . substr( hash( 'sha256', $wpdb->prefix ), 0, 32 );
  if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', $lock ) ) ) return $this->error( 'busy', 'Another Cart display save is running. Reload and review before retrying.', 409 );
  try {
   $before = $this->offer_policy_record(); if ( is_wp_error( $before ) ) return $before;
   if ( ! is_string( $input['expected'] ?? null ) || ! hash_equals( $before['expected'], $input['expected'] ) ) return $this->error( 'stale', 'Store settings changed. Reload and review the current values before saving.', 409 );
   if ( $before['switches'][$key] === $input['value'] ) return $before;
   $raw = get_option( DSA_OPTION_SETTINGS, [] ); $next = $raw; $next['commerce'][$key] = $input['value'];
   // Native option writer preserves every other raw setting and runs WordPress invalidation hooks.
   update_option( DSA_OPTION_SETTINGS, $next, false );
   if ( get_option( DSA_OPTION_SETTINGS, [] ) !== $next ) return $this->error( 'write', 'Store settings could not be confirmed. Reload and review before retrying.', 409 );
   $after = $this->offer_policy_record();
   if ( is_wp_error( $after ) || $after['switches'][$key] !== $input['value'] ) return $this->error( 'readback', 'The effective Cart display setting differs from your change. Reload and review.', 409 );
   return $after;
  } catch ( \Throwable $error ) { return $this->error( 'write', 'Store settings could not be confirmed. Reload and review before retrying.', 409 ); }
  finally { $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) ); }
 }


 public function nutrition_record( int $id ) {
  if ( ! function_exists( 'wc_get_product' ) || ! current_user_can( 'edit_products' ) || ! current_user_can( 'edit_post', $id ) ) return $this->error( 'forbidden', 'Your account cannot edit this product label.', 403 );
  $product = wc_get_product( $id );
  if ( ! $product || ! in_array( $product->get_type(), [ 'simple','variable' ], true ) ) return $this->error( 'type', 'This product does not support the nutrition-label task.', 409 );
  $image = absint( get_post_meta( $id, \DSA\Commerce\Product_Context_Service::META_NUTRITION_IMAGE_ID, true ) );
  $out = [ 'id'=>$id, 'name'=>(string) $product->get_name(), 'imageId'=>$image, 'image'=>Workspace_Media::image( $image ), 'cartAutomationUrl'=>current_user_can( 'kiwe_manage_notification_policy' ) ? admin_url( 'admin.php?page=kiwe-notifications&view=campaigns&campaign_tab=automations' ) . '#saved-cart-config' : '', 'canUpload'=>current_user_can( 'upload_files' ) ];
  $out['expected'] = hash( 'sha256', wp_json_encode( [ $out, self::fingerprint( $product ) ] ) );
  return $out;
 }
 public function read_nutrition( \WP_REST_Request $request ): \WP_REST_Response {
  if ( 'GET' !== $request->get_method() || ! $this->permission( $request ) ) return $this->response( $this->error( 'forbidden', 'Product nutrition labels are unavailable.', 403 ) );
  return $this->response( $this->nutrition_record( Task_Workspace_UI::id( $request->get_url_params()['id'] ?? 0 ) ) );
 }
 public function save_nutrition( \WP_REST_Request $request ): \WP_REST_Response {
  if ( 'POST' !== $request->get_method() || ! $this->permission( $request ) ) return $this->response( $this->error( 'forbidden', 'Product nutrition editing is unavailable.', 403 ) );
  $input = $request->get_json_params();
  return $this->response( $this->save_nutrition_record( Task_Workspace_UI::id( $request->get_url_params()['id'] ?? 0 ), is_array( $input ) ? $input : [] ) );
 }
 public function save_nutrition_record( int $id, array $input ) {
  $before = $this->nutrition_record( $id ); if ( is_wp_error( $before ) ) return $before;
  if ( ! is_string( $input['expected'] ?? null ) || ! hash_equals( $before['expected'], $input['expected'] ) ) return $this->error( 'stale', 'The product or label changed. Reload before saving.', 409 );
  if ( array_diff( array_keys( $input ), [ 'imageId','expected' ] ) ) return $this->error( 'input', 'Only the nutrition-label image can be changed here.' );
  $image = Workspace_Media::validate( $input['imageId'] ?? null, $before['imageId'] ); if ( is_wp_error( $image ) ) return $image;
  if ( $image === $before['imageId'] ) return $before;
  try { update_post_meta( $id, \DSA\Commerce\Product_Context_Service::META_NUTRITION_IMAGE_ID, $image ); }
  catch ( \Throwable $error ) { return $this->error( 'write', 'The nutrition label could not be confirmed. Reload and review before retrying.', 409 ); }
  $after = $this->nutrition_record( $id );
  if ( is_wp_error( $after ) || $after['imageId'] !== $image ) return $this->error( 'readback', 'The saved nutrition label differs. Reload and review before retrying.', 409 );
  return $after;
 }


 public function sale_record( int $id ) {
  if ( ! function_exists( 'wc_get_product' ) || ! current_user_can( 'edit_products' ) || ! current_user_can( 'edit_post', $id ) ) return $this->error( 'forbidden', 'Your account cannot manage this product sale.', 403 );
  $product = wc_get_product( $id );
  if ( ! $product || 'simple' !== $product->get_type() || ! in_array( $product->get_status(), [ 'draft','pending','publish','private' ], true ) ) return $this->error( 'type', 'This sale task supports simple products. Variation pricing remains in full product editing.', 409 );
  $zone = wp_timezone(); $from = $product->get_date_on_sale_from( 'edit' ); $to = $product->get_date_on_sale_to( 'edit' );
  $format = static fn($date)=>$date ? (new \DateTimeImmutable('@'.$date->getTimestamp()))->setTimezone($zone)->format('Y-m-d\TH:i:s') : '';
  $out = [ 'id'=>$id, 'name'=>(string)$product->get_name(), 'regularPrice'=>(string)$product->get_regular_price('edit'), 'salePrice'=>(string)$product->get_sale_price('edit'), 'startsAt'=>$format($from), 'endsAt'=>$format($to), 'timezone'=>$zone->getName(), 'currency'=>(string)get_option('woocommerce_currency',''), 'activePrice'=>(string)$product->get_price('edit'), 'onSale'=>(bool)$product->is_on_sale('edit') ];
  $out['expected'] = hash('sha256',wp_json_encode([$out,self::fingerprint($product)]));return $out;
 }
 /** Reject nonexistent and repeated local times rather than silently choosing an offset. */
 public function sale_date( $value ) {
  if ( ! is_string($value) ) return $this->error('date','Enter a website-local date and time.');
  if ( '' === $value ) return null;
  if ( ! preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}$/D',$value) ) return $this->error('date','Use a complete date and time, including seconds.');
  $zone=wp_timezone();$date=\DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s',$value,$zone);
  if ( ! $date || $date->format('Y-m-d\TH:i:s') !== $value || $date->getTimestamp()<=0 ) return $this->error('date','That local time does not exist. Choose another time.');
  $stamp=$date->getTimestamp();$transitions=$zone->getTransitions($stamp-172800,$stamp+172800);$offsets=[$zone->getOffset($date)];foreach($transitions?:[]as$t)$offsets[]=$t['offset'];
  $wall=$stamp+$zone->getOffset($date);$matches=[];
  foreach(array_unique($offsets)as$offset){$candidate=$wall-$offset;if((new \DateTimeImmutable('@'.$candidate))->setTimezone($zone)->format('Y-m-d\TH:i:s')===$value)$matches[]=$candidate;}
  return count($matches)===1?$stamp:$this->error('date','This time occurs twice during a timezone change. Choose an unambiguous time.');
 }
 public function read_sale( \WP_REST_Request $request ): \WP_REST_Response {
  if('GET'!==$request->get_method()||!$this->permission($request))return $this->response($this->error('forbidden','Product sale editing is unavailable.',403));
  return $this->response($this->sale_record(Task_Workspace_UI::id($request->get_url_params()['id']??0)));
 }
 public function save_sale( \WP_REST_Request $request ): \WP_REST_Response {
  if('POST'!==$request->get_method()||!$this->permission($request))return $this->response($this->error('forbidden','Product sale editing is unavailable.',403));
  $input=$request->get_json_params();return $this->response($this->save_sale_record(Task_Workspace_UI::id($request->get_url_params()['id']??0),is_array($input)?$input:[]));
 }
 public function save_sale_record( int $id, array $input ) {
  $before=$this->sale_record($id);if(is_wp_error($before))return $before;
  if(!is_string($input['expected']??null)||!hash_equals($before['expected'],$input['expected']))return $this->error('stale','Product pricing or the website timezone changed. Reload before saving.',409);
  if(array_diff(array_keys($input),['salePrice','startsAt','endsAt','expected'])||!is_string($input['salePrice']??null))return $this->error('input','Complete the sale price and schedule only.');
  $price=$input['salePrice'];if(''!==$price&&(!preg_match('/^\d{1,9}(?:\.\d{1,6})?$/D',$price)||''===$before['regularPrice']||(float)$price>=(float)$before['regularPrice']))return $this->error('price','Enter a non-negative sale price lower than the regular price, or clear the sale.');
  $price=''===$price?'':wc_format_decimal($price);$from=$this->sale_date($input['startsAt']??null);if(is_wp_error($from))return $from;$to=$this->sale_date($input['endsAt']??null);if(is_wp_error($to))return $to;
  if(''===$price&&(null!==$from||null!==$to))return $this->error('schedule','Clear both dates when removing a sale.');
  if(null!==$to&&((null!==$from&&$to<=$from)||$to<=time()))return $this->error('schedule','Choose an end after the start and after the current time.');
  if($price===$before['salePrice']&&$input['startsAt']===$before['startsAt']&&$input['endsAt']===$before['endsAt'])return $before;
  $product=wc_get_product($id);
  try{$product->set_sale_price($price);$product->set_date_on_sale_from($from);$product->set_date_on_sale_to($to);$saved=$product->save();}catch(\Throwable $error){return $this->error('write','The sale could not be confirmed. Reload and review before retrying.',409);}
  if(!$saved)return $this->error('write','The sale could not be confirmed. Reload and review.',409);
  $after=$this->sale_record($id);
  if(is_wp_error($after)||$after['salePrice']!==$price||$after['startsAt']!==$input['startsAt']||$after['endsAt']!==$input['endsAt'])return $this->error('readback','The saved sale differs. Reload and review before retrying.',409);
  $after['product']=$this->project_product(wc_get_product($id));return $after;
 }

	public function offer_record( int $id ) {
		if ( ! function_exists( 'wc_get_product' ) || ! current_user_can( 'edit_products' ) || ! current_user_can( 'edit_post', $id ) ) return $this->error( 'forbidden', 'Your role cannot edit this product offer.', 403 );
		$product = wc_get_product( $id );
		if ( ! $product || ! in_array( $product->get_type(), [ 'simple','variable' ], true ) ) return $this->error( 'product', 'This product cannot use the offer editor.', 409 );
		$raw = []; foreach ( [ 'product_id','discount','discount_type','discount_scope' ] as $field ) $raw[$field] = get_post_meta( $id, '_sc_upsell_'.$field, true );
		$type = in_array( $raw['discount_type'], [ 'percent','fixed' ], true ) ? $raw['discount_type'] : 'percent';
		$scope = in_array( $raw['discount_scope'], [ 'single_lowest','single_highest','both' ], true ) ? $raw['discount_scope'] : 'single_lowest';
		$config = ( new \DSA\Settings() )->get( 'commerce', [] );
		$out = [ 'id'=>$id, 'name'=>(string) $product->get_name(), 'productId'=>absint( $raw['product_id'] ), 'discount'=>(string) max( 0, 'percent' === $type ? min( 100, (float) $raw['discount'] ) : (float) $raw['discount'] ), 'discountType'=>$type, 'discountScope'=>$scope, 'currency'=>(string) get_option( 'woocommerce_currency', '' ) ];
		$out['expected'] = hash( 'sha256', wp_json_encode( [ $out, $raw, self::fingerprint( $product ) ] ) );
		return $out;
	}
	public function read_offer( \WP_REST_Request $request ): \WP_REST_Response {
		if ( 'GET' !== $request->get_method() || ! $this->permission( $request ) ) return $this->response( $this->error( 'forbidden', 'Product offers are unavailable.', 403 ) );
		$out = $this->offer_record( Task_Workspace_UI::id( $request->get_url_params()['id'] ?? 0 ) );
		if ( is_wp_error( $out ) ) return $this->response( $out );
		$out['choices'] = []; $query = $request->get_query_params(); $search = is_string( $query['search'] ?? null ) ? sanitize_text_field( $query['search'] ) : '';
		if ( strlen( $search ) >= 2 && strlen( $search ) <= 100 ) {
			$ids = get_posts( [ 'post_type'=>[ 'product','product_variation' ], 'post_status'=>'publish', 's'=>$search, 'posts_per_page'=>20, 'fields'=>'ids', 'no_found_rows'=>true, 'post__not_in'=>[ $out['id'] ] ] );
			foreach ( $ids as $candidate ) { $product = wc_get_product( $candidate ); if ( $product && current_user_can( 'edit_post', $candidate ) && in_array( $product->get_type(), [ 'simple','variation' ], true ) ) $out['choices'][] = [ 'id'=>(int) $candidate, 'name'=>(string) $product->get_name() ]; }
		}
		if ( $out['productId'] && current_user_can( 'edit_post', $out['productId'] ) ) { $selected = wc_get_product( $out['productId'] ); if ( $selected ) $out['selectedName'] = (string) $selected->get_name(); }
		return $this->response( $out );
	}
	public function save_offer( \WP_REST_Request $request ): \WP_REST_Response {
		if ( 'POST' !== $request->get_method() || ! $this->permission( $request ) ) return $this->response( $this->error( 'forbidden', 'Product offers are unavailable.', 403 ) );
		$input = $request->get_json_params();
		return $this->response( $this->save_offer_record( Task_Workspace_UI::id( $request->get_url_params()['id'] ?? 0 ), is_array( $input ) ? $input : [] ) );
	}
	public function save_offer_record( int $id, array $input ) {
		$before = $this->offer_record( $id ); if ( is_wp_error( $before ) ) return $before;
		if ( ! is_string( $input['expected'] ?? null ) || ! hash_equals( $before['expected'], $input['expected'] ) ) return $this->error( 'stale', 'The product, offer or store settings changed. Reload before saving.', 409 );
		if ( ! is_int( $input['productId'] ?? null ) || $input['productId'] < 0 || $input['productId'] === $id ) return $this->error( 'offer', 'Choose another product, or clear the offer.' );
		if ( ! in_array( $input['discountType'] ?? null, [ 'percent','fixed' ], true ) || ! in_array( $input['discountScope'] ?? null, [ 'single_lowest','single_highest','both' ], true ) || ! is_string( $input['discount'] ?? null ) || ! preg_match( '/^\d{1,9}(?:\.\d{1,2})?$/D', $input['discount'] ) || ( 'percent' === $input['discountType'] && (float) $input['discount'] > 100 ) ) return $this->error( 'discount', 'Use a non-negative amount with up to two decimals. Percent cannot exceed 100.' );
		if ( $input['productId'] && $input['productId'] !== $before['productId'] ) {
			$target = wc_get_product( $input['productId'] );
			if ( ! $target || ! current_user_can( 'edit_post', $input['productId'] ) || ! in_array( $target->get_type(), [ 'simple','variation' ], true ) || 'publish' !== $target->get_status() ) return $this->error( 'target', 'Choose a published simple product or a specific variation you can manage.' );
		}
		$wanted = [ 'product_id'=>$input['productId'], 'discount'=>(string) (float) $input['discount'], 'discount_type'=>$input['discountType'], 'discount_scope'=>$input['discountScope'] ];
		try { foreach ( $wanted as $field=>$value ) update_post_meta( $id, '_sc_upsell_'.$field, $value ); }
		catch ( \Throwable $error ) { return $this->error( 'offer_write', 'The offer could not be confirmed. Reload and review before retrying.', 409 ); }
		foreach ( $wanted as $field=>$value ) if ( (string) get_post_meta( $id, '_sc_upsell_'.$field, true ) !== (string) $value ) return $this->error( 'offer_readback', 'Some offer details were not saved. Reload and review before retrying.', 409 );
		return $this->offer_record( $id );
	}
	public function read_order( \WP_REST_Request $request ): \WP_REST_Response {
		if ( 'GET' !== $request->get_method() || ! $this->permission( $request ) ) return $this->response( $this->error( 'forbidden', 'Order details are unavailable.', 403 ) );
		return $this->response( ( new Order_Workspace_Service() )->review( Task_Workspace_UI::id( $request->get_url_params()['id'] ?? 0 ) ) );
	}
	public function refund_order( \WP_REST_Request $request ): \WP_REST_Response {
		if ( 'POST' !== $request->get_method() || ! $this->permission( $request ) ) return $this->response( $this->error( 'forbidden', 'You cannot refund this order.', 403 ) );
		$input = $request->get_json_params();
		return $this->response( is_array( $input ) ? ( new Order_Workspace_Service() )->refund( Task_Workspace_UI::id( $request->get_url_params()['id'] ?? 0 ), $input ) : $this->error( 'input', 'Review one refund task.' ) );
	}
	public function order_action( \WP_REST_Request $request ): \WP_REST_Response {
		if ( 'POST' !== $request->get_method() || ! $this->permission( $request ) ) return $this->response( $this->error( 'forbidden', 'Order actions are unavailable.', 403 ) );
		$input = $request->get_json_params();
		return $this->response( is_array( $input ) ? ( new Order_Workspace_Service() )->change( Task_Workspace_UI::id( $request->get_url_params()['id'] ?? 0 ), $input ) : $this->error( 'input', 'Review one order action.' ) );
	}
	public function complete_order( \WP_REST_Request $request ): \WP_REST_Response {
		if ( 'POST' !== $request->get_method() || ! $this->permission( $request ) ) return $this->response( $this->error( 'forbidden', 'Order completion is unavailable.', 403 ) );
		$input = $request->get_json_params();
		if ( ! is_array( $input ) || isset( $input['operation'] ) ) return $this->response( $this->error( 'input', 'Review fulfilment only through this action.' ) );
		$input['operation'] = 'complete';
		return $this->response( ( new Order_Workspace_Service() )->change( Task_Workspace_UI::id( $request->get_url_params()['id'] ?? 0 ), $input ) );
	}
	public function create_product( \WP_REST_Request $request ): \WP_REST_Response {
		if ( 'POST' !== $request->get_method() || ! $this->permission( $request ) || ! current_user_can( 'create_products' ) || ! current_user_can( 'edit_products' ) || ! class_exists( '\WC_Product_Simple' ) ) return $this->response( $this->error( 'forbidden', 'Your role cannot create products.', 403 ) );
		$input = $request->get_json_params();
		if ( ! is_array( $input ) || ! is_string( $input['name'] ?? null ) || strlen( $input['name'] ) > 960 ) return $this->response( $this->error( 'name', 'Enter a product name of up to 240 characters.' ) );
		$name = sanitize_text_field( $input['name'] );
		if ( '' === $name || preg_match_all( '/./us', $name ) > 240 ) return $this->response( $this->error( 'name', 'Enter a product name of up to 240 characters.' ) );
		$image = Workspace_Media::validate( $input['imageId'] ?? 0 );
		if ( is_wp_error( $image ) ) return $this->response( $image );
		$type=$input['type']??'simple';if(!in_array($type,['simple','variable','grouped'],true))return $this->response($this->error('type','Choose a simple, variable or grouped draft product.'));
		// Creation only makes a draft. Categories and inventory use the existing
		// record's checked editors; a lost response is never auto-replayed.
		try { $product = $type==='variable'?new \WC_Product_Variable():($type==='grouped'?new \WC_Product_Grouped():new \WC_Product_Simple()); $product->set_name( $name ); $product->set_image_id( $image ); $product->set_status( 'draft' ); $id = $product->save(); }
		catch ( \Throwable $error ) { return $this->response( $this->error( 'create', 'The draft could not be confirmed. Reload the product list before trying again.', 409 ) ); }
		$record = $id ? wc_get_product( $id ) : false;
		if ( ! $record || $record->get_type()!==$type || $record->get_name( 'edit' ) !== $name || (int) $record->get_image_id( 'edit' ) !== $image || 'draft' !== $record->get_status( 'edit' ) ) return $this->response( $this->error( 'create', 'The draft could not be confirmed. Reload the product list before trying again.', 409 ) );
		return $this->response( $this->project_product( $record ) );
	}
	public function save_inventory( \WP_REST_Request $request ): \WP_REST_Response {
		if ( 'POST' !== $request->get_method() || ! $this->permission( $request ) ) return $this->response( $this->error( 'forbidden', 'Inventory editing is unavailable.', 403 ) );
		$body = $request->get_json_params();
		return $this->response( $this->save_inventory_record( Task_Workspace_UI::id( $request->get_url_params()['id'] ?? 0 ), is_array( $body ) ? $body : [] ) );
	}
	public function save_inventory_record( int $id, array $input ) {
		if ( ! function_exists( 'wc_get_product' ) || ! current_user_can( 'edit_products' ) || ! current_user_can( 'edit_post', $id ) ) return $this->error( 'forbidden', 'Your role cannot edit this product.', 403 );
		$product = wc_get_product( $id ); if ( ! $product ) return $this->error( 'missing', 'This product is unavailable.', 404 );
		$before = $this->project_product( $product );
		if ( ! $before['inventoryEditable'] ) return $this->error( 'type', 'This inventory editor is for simple products.', 409 );
		if ( ! is_string( $input['expected'] ?? null ) || ! hash_equals( $before['expected'], $input['expected'] ) ) return $this->error( 'stale', 'This product changed elsewhere. Reload before saving stock.', 409 );
		if ( ! is_bool( $input['manageStock'] ?? null ) || ! is_string( $input['sku'] ?? null ) || strlen( $input['sku'] ) > 100 || ! in_array( $input['stockStatus'] ?? '', [ 'instock','outofstock','onbackorder' ], true ) || ! array_key_exists( 'stock', $input ) || ( null !== $input['stock'] && ( ! is_int( $input['stock'] ) || abs( $input['stock'] ) > 1000000000 ) ) || ( $input['manageStock'] && null === $input['stock'] ) ) return $this->error( 'inventory', 'Enter a valid SKU, stock quantity and availability.' );
		$sku = sanitize_text_field( $input['sku'] );
		if ( $sku && function_exists( 'wc_product_has_unique_sku' ) && ! wc_product_has_unique_sku( $id, $sku ) ) return $this->error( 'sku', 'Another product already uses this SKU.' );
		if ( $input['manageStock'] && ! $before['manageStock'] && ! in_array( $input['stockStatus'], [ 'instock','outofstock' ], true ) ) return $this->error( 'inventory', 'Choose stock availability before enabling quantity tracking.' );
		try {
			$product->set_sku( $sku ); $product->set_manage_stock( $input['manageStock'] );
			// Woo computes availability for quantity-managed products using its
			// existing backorder and out-of-stock threshold settings.
			if ( $input['manageStock'] ) $product->set_stock_quantity( $input['stock'] );
			else $product->set_stock_status( $input['stockStatus'] );
			$saved = $product->save();
		} catch ( \Throwable $error ) { return $this->error( 'inventory_write', 'Stock could not be confirmed. Reload before trying again.', 409 ); }
		$record = $saved ? wc_get_product( $id ) : false;
		if ( ! $record ) return $this->error( 'inventory_readback', 'Saved stock could not be read. Reload before trying again.', 409 );
		$after = $this->project_product( $record );
		if ( $after['sku'] !== $sku || $after['manageStock'] !== $input['manageStock'] || ( $input['manageStock'] ? (float) $after['stock'] !== (float) $input['stock'] : $after['stockStatus'] !== $input['stockStatus'] ) ) return $this->error( 'inventory_readback', 'The saved stock differs. Reload and review it.', 409 );
		return $after;
	}
	private function response( $value ): \WP_REST_Response {
		return is_wp_error( $value ) ? Task_Workspace_UI::failure( $value, (int) ( $value->get_error_data()['status'] ?? 400 ) ) : Task_Workspace_UI::response( $value );
	}
	public function read_tax(\WP_REST_Request $request): \WP_REST_Response {
		if($request->get_method()!=='GET'||!$this->permission($request))return $this->response($this->error('forbidden','Tax setup unavailable.',403));return $this->response((new Tax_Workspace_Service())->settings());
	}
	public function read_tax_classes(\WP_REST_Request $request): \WP_REST_Response {
		if($request->get_method()!=='GET'||!$this->permission($request))return $this->response($this->error('forbidden','Tax classes unavailable.',403));return $this->response((new Tax_Workspace_Service())->classes());
	}
	public function create_tax_class(\WP_REST_Request $request): \WP_REST_Response {
		if($request->get_method()!=='POST'||!$this->permission($request))return $this->response($this->error('forbidden','Tax class creation unavailable.',403));$body=$request->get_json_params();return $this->response((new Tax_Workspace_Service())->create_class(is_array($body)?$body:[]));
	}
	public function delete_tax_class(\WP_REST_Request $request): \WP_REST_Response {
		if($request->get_method()!=='POST'||!$this->permission($request))return $this->response($this->error('forbidden','Tax class deletion unavailable.',403));$body=$request->get_json_params();return $this->response((new Tax_Workspace_Service())->delete_class((string)$request->get_url_params()['slug'],is_array($body)?$body:[]));
	}
	public function save_tax(\WP_REST_Request $request): \WP_REST_Response {
		if($request->get_method()!=='POST'||!$this->permission($request))return $this->response($this->error('forbidden','Tax setup unavailable.',403));$body=$request->get_json_params();return $this->response((new Tax_Workspace_Service())->save(is_array($body)?$body:[]));
	}
	public function read_tax_rates(\WP_REST_Request $request): \WP_REST_Response {
		if($request->get_method()!=='GET'||!$this->permission($request))return $this->response($this->error('forbidden','Tax rates unavailable.',403));return $this->response((new Tax_Workspace_Service())->rates($request->get_query_params()));
	}
	public function read_tax_geography(\WP_REST_Request $request): \WP_REST_Response {
		if($request->get_method()!=='GET'||!$this->permission($request))return $this->response($this->error('forbidden','Tax regions unavailable.',403));return $this->response((new Tax_Workspace_Service())->geography((int)$request->get_url_params()['id'],$request->get_query_params()));
	}
	public function save_tax_geography(\WP_REST_Request $request): \WP_REST_Response {
		if($request->get_method()!=='POST'||!$this->permission($request))return $this->response($this->error('forbidden','Tax regions unavailable.',403));$body=$request->get_json_params();return $this->response((new Tax_Workspace_Service())->save_geography((int)$request->get_url_params()['id'],is_array($body)?$body:[]));
	}
	public function read_tax_rules(\WP_REST_Request $request): \WP_REST_Response {
		if($request->get_method()!=='GET'||!$this->permission($request))return $this->response($this->error('forbidden','Tax rules unavailable.',403));return $this->response((new Tax_Workspace_Service())->rules((int)$request->get_url_params()['id'],$request->get_query_params()));
	}
	public function save_tax_rules(\WP_REST_Request $request): \WP_REST_Response {
		if($request->get_method()!=='POST'||!$this->permission($request))return $this->response($this->error('forbidden','Tax rules unavailable.',403));$body=$request->get_json_params();return $this->response((new Tax_Workspace_Service())->save_rules((int)$request->get_url_params()['id'],is_array($body)?$body:[]));
	}
	public function read_tax_creation(\WP_REST_Request $request): \WP_REST_Response {
		if($request->get_method()!=='GET'||!$this->permission($request))return $this->response($this->error('forbidden','Tax creation unavailable.',403));return $this->response((new Tax_Workspace_Service())->creation($request->get_query_params()));
	}
	public function create_tax_rate(\WP_REST_Request $request): \WP_REST_Response {
		if($request->get_method()!=='POST'||!$this->permission($request))return $this->response($this->error('forbidden','Tax creation unavailable.',403));$body=$request->get_json_params();return $this->response((new Tax_Workspace_Service())->create_rate(is_array($body)?$body:[]));
	}
	public function read_tax_rate(\WP_REST_Request $request): \WP_REST_Response {
		if($request->get_method()!=='GET'||!$this->permission($request))return $this->response($this->error('forbidden','Tax rate unavailable.',403));return $this->response((new Tax_Workspace_Service())->rate((int)$request->get_url_params()['id']));
	}
	public function save_tax_rate(\WP_REST_Request $request): \WP_REST_Response {
		if($request->get_method()!=='POST'||!$this->permission($request))return $this->response($this->error('forbidden','Tax rate unavailable.',403));$body=$request->get_json_params();return $this->response((new Tax_Workspace_Service())->save_rate((int)$request->get_url_params()['id'],is_array($body)?$body:[]));
	}
	public function delete_tax_rate(\WP_REST_Request $request): \WP_REST_Response {
		if($request->get_method()!=='POST'||!$this->permission($request))return $this->response($this->error('forbidden','Tax-rate deletion unavailable.',403));$body=$request->get_json_params();return $this->response((new Tax_Workspace_Service())->delete_rate((int)$request->get_url_params()['id'],is_array($body)?$body:[]));
	}
	public function read_tax_locations(\WP_REST_Request $request): \WP_REST_Response {
		if($request->get_method()!=='GET'||!$this->permission($request))return $this->response($this->error('forbidden','Tax regions unavailable.',403));return $this->response((new Tax_Workspace_Service())->locations((int)$request->get_url_params()['id'],$request->get_query_params()));
	}
	public function read_attributes( \WP_REST_Request $request ): \WP_REST_Response {
		if ( $request->get_method() !== 'GET' || ! $this->permission( $request ) ) return $this->response( $this->error( 'forbidden','Attributes unavailable.',403 ) );
		return $this->response( (new Product_Attribute_Workspace_Service())->catalog( $request->get_query_params() ) );
	}
	public function read_product_options(\WP_REST_Request $request): \WP_REST_Response {
		if($request->get_method()!=='GET'||!$this->permission($request))return $this->response($this->error('forbidden','Product choices unavailable.',403));return $this->response((new Product_Variation_Workspace_Service())->setup((int)$request->get_url_params()['id']));
	}
	public function read_product_option_choices(\WP_REST_Request $request): \WP_REST_Response {
		if($request->get_method()!=='GET'||!$this->permission($request))return $this->response($this->error('forbidden','Product choices unavailable.',403));return $this->response((new Product_Variation_Workspace_Service())->options((int)$request->get_url_params()['id'],(int)$request->get_param('attribute'),$request->get_query_params()));
	}
	private function product_option_result($result,int $parent) {
		if(!is_wp_error($result)){$product=wc_get_product($parent);if(!$product)return $this->error('readback','Product change could not be read. Refresh before trying again.',409);$product->get_data_store()->read($product);$result['product']=$this->project_product($product);}return $result;
	}
	public function change_product_option(\WP_REST_Request $request): \WP_REST_Response {
		if($request->get_method()!=='POST'||!$this->permission($request))return $this->response($this->error('forbidden','Product choices unavailable.',403));$input=$request->get_json_params();$id=(int)$request->get_url_params()['id'];return $this->response($this->product_option_result((new Product_Variation_Workspace_Service())->change_attribute($id,is_array($input)?$input:[]),$id));
	}
	public function read_product_variations(\WP_REST_Request $request): \WP_REST_Response {
		if($request->get_method()!=='GET'||!$this->permission($request))return $this->response($this->error('forbidden','Product variations unavailable.',403));return $this->response((new Product_Variation_Workspace_Service())->variations((int)$request->get_url_params()['id'],$request->get_query_params()));
	}
	public function read_product_variation(\WP_REST_Request $request): \WP_REST_Response {
		if($request->get_method()!=='GET'||!$this->permission($request))return $this->response($this->error('forbidden','Product variation unavailable.',403));$ids=$request->get_url_params();return $this->response((new Product_Variation_Workspace_Service())->variation((int)$ids['id'],(int)$ids['child']));
	}
	public function save_product_variation(\WP_REST_Request $request): \WP_REST_Response {
		if($request->get_method()!=='POST'||!$this->permission($request))return $this->response($this->error('forbidden','Product variation unavailable.',403));$input=$request->get_json_params();$ids=$request->get_url_params();return $this->response($this->product_option_result((new Product_Variation_Workspace_Service())->save_variation((int)$ids['id'],(int)$ids['child'],is_array($input)?$input:[]),(int)$ids['id']));
	}
	public function read_attribute_values( \WP_REST_Request $request ): \WP_REST_Response {
		if ( $request->get_method() !== 'GET' || ! $this->permission( $request ) ) return $this->response( $this->error( 'forbidden','Attribute values unavailable.',403 ) );
		return $this->response( (new Product_Attribute_Workspace_Service())->values( (int) $request->get_url_params()['id'], $request->get_query_params() ) );
	}
	public function change_attribute( \WP_REST_Request $request ): \WP_REST_Response {
		if ( $request->get_method() !== 'POST' || ! $this->permission( $request ) ) return $this->response( $this->error( 'forbidden','Attribute change unavailable.',403 ) );
		$input = $request->get_json_params();
		return $this->response( (new Product_Attribute_Workspace_Service())->change( (int) ($request->get_url_params()['id'] ?? 0), 0, is_array($input) ? $input : [] ) );
	}
	public function change_attribute_value( \WP_REST_Request $request ): \WP_REST_Response {
		if ( $request->get_method() !== 'POST' || ! $this->permission( $request ) ) return $this->response( $this->error( 'forbidden','Attribute value change unavailable.',403 ) );
		$input = $request->get_json_params(); $ids = $request->get_url_params();
		return $this->response( (new Product_Attribute_Workspace_Service())->change( (int) $ids['id'], (int) ($ids['term'] ?? 0), is_array($input) ? $input : [], true ) );
	}
	public function read_shipping_class_catalog(\WP_REST_Request $request): \WP_REST_Response {
		if($request->get_method()!=='GET'||!$this->permission($request))return $this->response($this->error('forbidden','Shipping classes unavailable.',403));return $this->response((new Shipping_Class_Workspace_Service())->classes($request->get_query_params()));
	}
	public function change_shipping_class(\WP_REST_Request $request): \WP_REST_Response {
		if($request->get_method()!=='POST'||!$this->permission($request))return $this->response($this->error('forbidden','Shipping-class change unavailable.',403));$body=$request->get_json_params();return $this->response((new Shipping_Class_Workspace_Service())->change((int)($request->get_url_params()['id']??0),is_array($body)?$body:[]));
	}
	public function read_shipping_classes(\WP_REST_Request $request): \WP_REST_Response {
		if($request->get_method()!=='GET'||!$this->permission($request))return $this->response($this->error('forbidden','Class charges unavailable.',403));$url=$request->get_url_params();return $this->response((new Shipping_Workspace_Service())->class_rates((int)$url['zone'],(int)$url['instance'],$request->get_query_params()));
	}
	public function save_shipping_classes(\WP_REST_Request $request): \WP_REST_Response {
		if($request->get_method()!=='POST'||!$this->permission($request))return $this->response($this->error('forbidden','Class charges unavailable.',403));$url=$request->get_url_params();$body=$request->get_json_params();return $this->response((new Shipping_Workspace_Service())->save_class_rates((int)$url['zone'],(int)$url['instance'],is_array($body)?$body:[]));
	}
	public function read_delivery_creation(\WP_REST_Request $request): \WP_REST_Response {
		if($request->get_method()!=='GET'||!$this->permission($request))return $this->response($this->error('forbidden','Delivery creation unavailable.',403));return $this->response((new Shipping_Workspace_Service())->creation());
	}
	public function create_delivery(\WP_REST_Request $request): \WP_REST_Response {
		if($request->get_method()!=='POST'||!$this->permission($request))return $this->response($this->error('forbidden','Delivery creation unavailable.',403));$url=$request->get_url_params();$body=$request->get_json_params();return $this->response((new Shipping_Workspace_Service())->create_delivery(is_array($body)?$body:[],(int)($url['zone']??0)));
	}
	public function read_zone_settings(\WP_REST_Request $request): \WP_REST_Response {
		if($request->get_method()!=='GET'||!$this->permission($request))return $this->response($this->error('forbidden','Zone editing is unavailable.',403));$url=$request->get_url_params();return $this->response((new Shipping_Workspace_Service())->zone_settings((int)$url['zone'],$request->get_query_params()));
	}
	public function save_zone_settings(\WP_REST_Request $request): \WP_REST_Response {
		if($request->get_method()!=='POST'||!$this->permission($request))return $this->response($this->error('forbidden','Zone editing is unavailable.',403));$url=$request->get_url_params();$body=$request->get_json_params();return $this->response((new Shipping_Workspace_Service())->save_zone((int)$url['zone'],is_array($body)?$body:[]));
	}
	public function read_shipping_rates(\WP_REST_Request $request): \WP_REST_Response {
		if($request->get_method()!=='GET'||!$this->permission($request))return $this->response($this->error('forbidden','Delivery rates are unavailable.',403));
		$url=$request->get_url_params();return $this->response((new Shipping_Workspace_Service())->rates((int)$url['zone'],(int)$url['instance']));
	}
	public function save_shipping_rates(\WP_REST_Request $request): \WP_REST_Response {
		if($request->get_method()!=='POST'||!$this->permission($request))return $this->response($this->error('forbidden','Delivery rates are unavailable.',403));
		$url=$request->get_url_params();$body=$request->get_json_params();return $this->response((new Shipping_Workspace_Service())->save_rates((int)$url['zone'],(int)$url['instance'],is_array($body)?$body:[]));
	}
	public function read_shipping(\WP_REST_Request $request): \WP_REST_Response {
		if($request->get_method()!=='GET'||!$this->permission($request))return $this->response($this->error('forbidden','Delivery setup is unavailable.',403));
		$service=new Shipping_Workspace_Service();$url=$request->get_url_params();$query=$request->get_query_params();
		return $this->response(isset($url['instance'])?$service->method((int)$url['zone'],(int)$url['instance']):(isset($url['zone'])?$service->detail((int)$url['zone'],$query):$service->zones($query)));
	}
	public function save_shipping(\WP_REST_Request $request): \WP_REST_Response {
		if($request->get_method()!=='POST'||!$this->permission($request))return $this->response($this->error('forbidden','Delivery setup is unavailable.',403));
		$url=$request->get_url_params();$body=$request->get_json_params();return $this->response((new Shipping_Workspace_Service())->save((int)$url['zone'],(int)$url['instance'],is_array($body)?$body:[]));
	}
	public function save_delivery( \WP_REST_Request $request ): \WP_REST_Response {
		if ( 'POST' !== $request->get_method() || ! $this->permission( $request ) ) return $this->response( $this->error( 'forbidden', 'Delivery editing is unavailable.', 403 ) );
		$input = $request->get_json_params();
		return $this->response( $this->save_delivery_record( Task_Workspace_UI::id( $request->get_url_params()['id'] ?? 0 ), is_array( $input ) ? $input : [] ) );
	}
	public function save_delivery_record( int $id, array $input ) {
		if ( ! function_exists( 'wc_get_product' ) || ! current_user_can( 'edit_products' ) || ! current_user_can( 'edit_post', $id ) ) return $this->error( 'forbidden', 'Your role cannot edit this product.', 403 );
		$product = wc_get_product( $id ); if ( ! $product ) return $this->error( 'missing', 'This product is unavailable.', 404 );
		$before = $this->project_product( $product );
		if ( ! $before['deliveryEditable'] ) return $this->error( 'type', 'This delivery editor is for simple products.', 409 );
		if ( ! is_string( $input['expected'] ?? null ) || ! hash_equals( $before['expected'], $input['expected'] ) ) return $this->error( 'stale', 'The product or measurement units changed. Reload before saving.', 409 );
		if ( ! is_bool( $input['virtual'] ?? null ) || ! is_int( $input['shippingClassId'] ?? null ) || $input['shippingClassId'] < 0 ) return $this->error( 'delivery', 'Choose physical or virtual delivery and an existing shipping class.' );
		$wanted = [ 'virtual'=>$input['virtual'], 'shippingClassId'=>$input['shippingClassId'] ];
		foreach ( [ 'weight','length','width','height' ] as $key ) {
			$value = $input[$key] ?? null;
			if ( ! is_string( $value ) || ( '' !== $value && ! preg_match( '/^\d{1,9}(?:\.\d{1,6})?$/D', $value ) ) ) return $this->error( 'measurement', 'Use a non-negative measurement with a dot for decimals, or leave it blank.' );
			$wanted[$key] = '' === $value ? '' : wc_format_decimal( $value );
		}
		if ( $wanted['shippingClassId'] !== $before['shippingClassId'] ) {
			$taxonomy = get_taxonomy( 'product_shipping_class' );
			if ( ! $taxonomy || ! current_user_can( $taxonomy->cap->assign_terms ) ) return $this->error( 'class', 'Your role cannot assign shipping classes.', 403 );
			if ( $wanted['shippingClassId'] && ! term_exists( $wanted['shippingClassId'], 'product_shipping_class' ) ) return $this->error( 'class', 'Choose an existing WooCommerce shipping class.' );
		}
		try {
			foreach ( $wanted as $key=>$value ) { $setter = 'shippingClassId' === $key ? 'set_shipping_class_id' : 'set_'.$key; $product->{$setter}( $value ); }
			$saved = $product->save();
		} catch ( \Throwable $error ) { return $this->error( 'delivery_write', 'Delivery details could not be confirmed. Reload before retrying.', 409 ); }
		$record = $saved ? wc_get_product( $id ) : false;
		if ( ! $record ) return $this->error( 'delivery_readback', 'Saved delivery details could not be read. Reload before retrying.', 409 );
		$after = $this->project_product( $record );
		foreach ( $wanted as $key=>$value ) if ( $after[$key] !== $value ) return $this->error( 'delivery_readback', 'Some delivery details were not saved as requested. Reload and review.', 409 );
		return $after;
	}
	public function read( \WP_REST_Request $request ): \WP_REST_Response {
		if ( ! $this->permission( $request ) ) return $this->response( $this->error( 'forbidden', 'Store work is unavailable for your account.', 403 ) );
		$input = $request->get_query_params();
		if ( 'orders' === ( $input['tab'] ?? '' ) && $this->can_order() && Order_Workspace_Service::available() ) {
			$checked = ( new Order_Workspace_Service() )->query_filters( array_intersect_key( $input, array_flip( [ 'kind','from','to' ] ) ) );
			if ( is_wp_error( $checked ) ) return $this->response( $checked );
		}
		$filters = [
			'status'=>is_scalar( $input['status'] ?? null ) ? sanitize_key( (string) $input['status'] ) : 'all',
			'sort'=>is_scalar( $input['sort'] ?? null ) ? sanitize_key( (string) $input['sort'] ) : 'updated_desc',
			'search'=>is_scalar( $input['search'] ?? null ) ? Task_Workspace_UI::text( (string) $input['search'], 80 ) : '',
			'stock'=>is_scalar( $input['stock'] ?? null ) ? sanitize_key( (string) $input['stock'] ) : 'all',
			'kind'=>is_scalar( $input['kind'] ?? null ) ? sanitize_key( (string) $input['kind'] ) : 'all',
			'from'=>is_string( $input['from'] ?? null ) ? $input['from'] : '',
			'to'=>is_string( $input['to'] ?? null ) ? $input['to'] : '',
		];
		return $this->response( $this->workspace( 'orders' === ( $input['tab'] ?? '' ) ? 'orders' : 'products', max( 1, min( 1000, absint( $input['page'] ?? 1 ) ) ), is_scalar( $input['id'] ?? null ) ? absint( $input['id'] ) : 0, $filters ) );
	}
	public function save_product( \WP_REST_Request $request ): \WP_REST_Response {
		if ( ! $this->permission( $request ) ) return $this->response( $this->error( 'forbidden', 'Product editing is unavailable for your account.', 403 ) );
		$body = $request->get_json_params();
		return $this->response( $this->save_product_record( Task_Workspace_UI::id( $request->get_url_params()['id'] ?? 0 ), is_array( $body ) ? $body : [] ) );
	}
	public function workspace( string $tab = 'products', int $page = 1, int $id = 0, array $filters = [] ): array {
		$available = function_exists( 'wc_get_products' ) && function_exists( 'wc_get_orders' ) && post_type_exists( 'product' );
		$page = max( 1, min( 1000, $page ) );
		$out = [ 'commerceAvailable'=>$available, 'tab'=>'orders' === $tab ? 'orders' : 'products', 'page'=>$page, 'products'=>[], 'orders'=>[], 'productCategories'=>[], 'productTags'=>[], 'hasNext'=>false, 'canUpload'=>current_user_can( 'upload_files' ), 'canEditProducts'=>current_user_can( 'edit_products' ), 'canPublish'=>current_user_can( 'publish_products' ), 'canManageOrders'=>$this->can_order(), 'canManageCoupons'=>$this->can_coupon(), 'canCreate'=>current_user_can( 'create_products' ) && current_user_can( 'edit_products' ), 'createUrl'=>'' ];
		if ( ! $available || ! $this->can_use() ) return $out;
		// Offset advances by the displayed page size; the extra row is only a sentinel.
		$args = [ 'limit'=>self::PAGE_SIZE + 1, 'offset'=>( $page - 1 ) * self::PAGE_SIZE, 'orderby'=>'ID', 'order'=>'DESC' ];
		if ( $id > 0 ) { $args['include'] = [ $id ]; $args['offset'] = 0; }
		if ( 'orders' === $out['tab'] ) {
			if ( ! $this->can_order() ) return $out;
			$order_statuses = array_map( static fn( $status ) => str_starts_with( $status, 'wc-' ) ? substr( $status, 3 ) : $status, array_values( array_diff( array_keys( wc_get_order_statuses() ), [ 'wc-checkout-draft','wc-auto-draft' ] ) ) );
			$status = in_array( $filters['status'] ?? '', $order_statuses, true ) ? $filters['status'] : 'all';
			$sort = in_array( $filters['sort'] ?? '', [ 'date_desc','date_asc','total_desc','total_asc' ], true ) ? $filters['sort'] : 'date_desc';
			$args['status'] = 'all' === $status ? $order_statuses : [ $status ];
			$args['orderby'] = str_starts_with( $sort, 'total_' ) ? 'total' : 'date';
			$args['order'] = str_ends_with( $sort, '_asc' ) ? 'ASC' : 'DESC';
			$out['status'] = $status; $out['sort'] = $sort;
			$extra = ( new Order_Workspace_Service() )->query_filters( $filters );
			if ( is_wp_error( $extra ) ) return $out;
			$args = array_merge( $args, $extra );
			$out['orderStatuses'] = [ [ 'id'=>'all', 'label'=>'all orders' ] ];
			foreach ( wc_get_order_statuses() as $key=>$label ) if ( in_array( substr( $key, 3 ), $order_statuses, true ) ) $out['orderStatuses'][] = [ 'id'=>substr( $key, 3 ), 'label'=>sanitize_text_field( $label ) ];
			$items = wc_get_orders( $args );
			if ( ! is_array( $items ) ) return $out;
			$out['hasNext'] = count( $items ) > self::PAGE_SIZE;
			foreach ( array_slice( $items, 0, self::PAGE_SIZE ) as $order ) {
				if ( ! is_object( $order ) || ! current_user_can( 'edit_shop_order', $order->get_id() ) ) continue;
				$out['orders'][] = [ 'id'=>(int) $order->get_id(), 'number'=>(string) $order->get_order_number(), 'status'=>(string) $order->get_status(), 'statusLabel'=>sanitize_text_field( wc_get_order_status_name( $order->get_status() ) ), 'total'=>(string) $order->get_total(), 'currency'=>(string) $order->get_currency(), 'customer'=>sanitize_text_field( trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ) ), 'date'=>$order->get_date_created() ? $order->get_date_created()->date( 'c' ) : '' ];
			}
			return $out;
		}
		if ( ! current_user_can( 'edit_products' ) ) return $out;
		$out['menus'] = class_exists( Navigation_Workspace_Service::class ) ? ( new Navigation_Workspace_Service() )->dsa_menus() : [];
		$status = in_array( $filters['status'] ?? 'all', [ 'draft','pending','future','publish','private' ], true ) ? $filters['status'] : 'all';
		$sort = in_array( $filters['sort'] ?? 'updated_desc', [ 'updated_desc','updated_asc','date_desc','date_asc','title_asc','title_desc' ], true ) ? ( $filters['sort'] ?? 'updated_desc' ) : 'updated_desc';
		$args['status'] = 'all' === $status ? [ 'publish','draft','pending','private','future' ] : [ $status ];
		$args['orderby'] = str_starts_with( $sort, 'title_' ) ? 'name' : ( str_starts_with( $sort, 'date_' ) ? 'date' : 'modified' );
		$args['order'] = str_ends_with( $sort, '_asc' ) ? 'ASC' : 'DESC';
		$search = trim( (string) ( $filters['search'] ?? '' ) );
		if ( '' !== $search ) {
			if ( ctype_digit( $search ) && (int) $search > 0 ) { $args['include'] = [ (int) $search ]; $args['offset'] = 0; }
			else $args['name'] = $search;
		}
		$stock = in_array( $filters['stock'] ?? 'all', [ 'instock','outofstock','onbackorder' ], true ) ? $filters['stock'] : 'all';
		if ( 'all' !== $stock ) $args['stock_status'] = $stock;
		$out['status'] = $status; $out['sort'] = $sort; $out['search'] = $search; $out['stock'] = $stock;
		$items = wc_get_products( $args );
		if ( ! is_array( $items ) ) return $out;
		$out['hasNext'] = count( $items ) > self::PAGE_SIZE;
		foreach ( array_slice( $items, 0, self::PAGE_SIZE ) as $product ) {
			if ( is_object( $product ) && current_user_can( 'edit_post', (int) $product->get_id() ) ) $out['products'][] = $this->project_product( $product );
		}
		$terms = get_terms( [ 'taxonomy'=>'product_cat', 'hide_empty'=>false, 'number'=>100, 'orderby'=>'name', 'order'=>'ASC' ] );
		$known_categories = [];
		foreach ( is_array( $terms ) ? $terms : [] as $term ) { $known_categories[] = (int) $term->term_id; $out['productCategories'][] = [ 'id'=>(int) $term->term_id, 'name'=>(string) $term->name ]; }
		$selected_categories = []; foreach ( $out['products'] as $product ) foreach ( $product['categories'] as $category_id ) $selected_categories[] = $category_id;
		$missing_categories = array_values( array_diff( array_unique( $selected_categories ), $known_categories ) );
		if ( $missing_categories ) { $extra = get_terms( [ 'taxonomy'=>'product_cat', 'hide_empty'=>false, 'include'=>$missing_categories ] ); foreach ( is_array( $extra ) ? $extra : [] as $term ) $out['productCategories'][] = [ 'id'=>(int) $term->term_id, 'name'=>(string) $term->name ]; }
		$tags = get_terms( [ 'taxonomy'=>'product_tag', 'hide_empty'=>false, 'number'=>100, 'orderby'=>'name', 'order'=>'ASC' ] );
		$known = [];
		foreach ( is_array( $tags ) ? $tags : [] as $term ) { $known[] = (int) $term->term_id; $out['productTags'][] = [ 'id'=>(int) $term->term_id, 'name'=>(string) $term->name ]; }
		$selected = []; foreach ( $out['products'] as $product ) foreach ( $product['tags'] as $tag_id ) $selected[] = $tag_id;
		$missing = array_values( array_diff( array_unique( $selected ), $known ) );
		if ( $missing ) { $extra = get_terms( [ 'taxonomy'=>'product_tag', 'hide_empty'=>false, 'include'=>$missing ] ); foreach ( is_array( $extra ) ? $extra : [] as $term ) $out['productTags'][] = [ 'id'=>(int) $term->term_id, 'name'=>(string) $term->name ]; }
		$out['shippingClasses'] = [];
		$classes = get_terms( [ 'taxonomy'=>'product_shipping_class', 'hide_empty'=>false, 'number'=>100, 'orderby'=>'name', 'order'=>'ASC' ] );
		foreach ( is_array( $classes ) ? $classes : [] as $term ) $out['shippingClasses'][] = [ 'id'=>(int) $term->term_id, 'name'=>(string) $term->name ];
		return $out;
	}
	private static function product_values( $product ): array {
		$categories = array_values( array_map( 'intval', $product->get_category_ids( 'edit' ) ) ); sort( $categories, SORT_NUMERIC );
		$tags = array_values( array_map( 'intval', $product->get_tag_ids( 'edit' ) ) ); sort( $tags, SORT_NUMERIC );
		return [ 'id'=>(int) $product->get_id(), 'type'=>(string) $product->get_type(), 'name'=>(string) $product->get_name( 'edit' ), 'description'=>(string) $product->get_description( 'edit' ), 'shortDescription'=>(string) $product->get_short_description( 'edit' ), 'status'=>(string) $product->get_status( 'edit' ), 'price'=>(string) $product->get_regular_price( 'edit' ), 'salePrice'=>(string) $product->get_sale_price( 'edit' ), 'saleFrom'=>$product->get_date_on_sale_from( 'edit' ) ? $product->get_date_on_sale_from( 'edit' )->getTimestamp() : null, 'saleTo'=>$product->get_date_on_sale_to( 'edit' ) ? $product->get_date_on_sale_to( 'edit' )->getTimestamp() : null, 'taxStatus'=>(string) $product->get_tax_status( 'edit' ), 'taxClass'=>(string) $product->get_tax_class( 'edit' ), 'virtual'=>(bool) $product->get_virtual( 'edit' ), 'weight'=>(string) $product->get_weight( 'edit' ), 'length'=>(string) $product->get_length( 'edit' ), 'width'=>(string) $product->get_width( 'edit' ), 'height'=>(string) $product->get_height( 'edit' ), 'shippingClassId'=>(int) $product->get_shipping_class_id( 'edit' ), 'weightUnit'=>(string) get_option( 'woocommerce_weight_unit', 'kg' ), 'dimensionUnit'=>(string) get_option( 'woocommerce_dimension_unit', 'cm' ), 'stock'=>$product->get_stock_quantity( 'edit' ), 'manageStock'=>$product->get_manage_stock( 'edit' ), 'stockStatus'=>(string) $product->get_stock_status( 'edit' ), 'sku'=>(string) $product->get_sku( 'edit' ), 'imageId'=>(int) $product->get_image_id( 'edit' ), 'galleryIds'=>array_map( 'intval', $product->get_gallery_image_ids( 'edit' ) ), 'categories'=>$categories, 'tags'=>$tags, 'groupedIds'=>$product->get_type()==='grouped'?array_map('intval',$product->get_children('edit')):[],'upsellIds'=>array_map('intval',$product->get_upsell_ids('edit')),'crossSellIds'=>array_map('intval',$product->get_cross_sell_ids('edit')),'attributes'=>Product_Variation_Workspace_Service::attribute_values($product),'defaultAttributes'=>$product->get_default_attributes('edit'),'modifiedGmt'=>$product->get_date_modified( 'edit' ) ? $product->get_date_modified( 'edit' )->date( 'c' ) : '' ];
	}
	public static function fingerprint( $product ): string { return hash( 'sha256', wp_json_encode( self::product_values( $product ) ) ); }
	private function project_product( $product ): array {
		$out = self::product_values( $product );
		$out['expected'] = self::fingerprint( $product );
		$out['image'] = Workspace_Media::image( $out['imageId'] );
		$out['gallery'] = array_map( [ Workspace_Media::class, 'image' ], $out['galleryIds'] );
		$out['editable'] = in_array($out['type'],['simple','variable','grouped'],true) && in_array( $out['status'], [ 'draft','pending','publish','private' ], true );
		$out['merchandisingEditable']=in_array($out['type'],['simple','variable','grouped','external'],true)&&in_array($out['status'],['draft','pending','publish','private'],true);
		$out['bundleProviderAvailable']=class_exists('WC_Product_Bundle');
		$out['inventoryEditable']=$out['deliveryEditable']=$out['type']==='simple'&&$out['editable'];
		$out['taxEditable'] = in_array( $out['type'], [ 'simple','external','variable' ], true ) && in_array( $out['status'], [ 'draft','pending','publish','private' ], true );
		// The client workspace deliberately blocks this WordPress editor route.
		$out['advancedUrl'] = Site_Owner_Service::is_owner() ? admin_url( 'post.php?post=' . $out['id'] . '&action=edit' ) : '';
		$out['viewUrl'] = 'publish' === $out['status'] ? (string) get_permalink( $out['id'] ) : '';
		$out['controls'] = class_exists( Record_Card_Control_Service::class ) ? Record_Card_Control_Service::project( 'product', $out['id'] ) : null;
		$out['canTrash'] = current_user_can( 'delete_post', $out['id'] );
		$out['canExport'] = class_exists( \DSA\Backup\Content_Archive_Service::class ) && \DSA\Backup\Content_Archive_Service::can_export( 'product', $out['id'] );
		return $out;
	}
	public function save_product_record( int $id, array $input ) {
		if ( ! function_exists( 'wc_get_product' ) || ! current_user_can( 'edit_products' ) || ! current_user_can( 'edit_post', $id ) ) return $this->error( 'forbidden', 'Your role cannot edit this product.', 403 );
		$product = wc_get_product( $id );
		if ( ! $product ) return $this->error( 'missing', 'This product is unavailable.', 404 );
		$before = $this->project_product( $product );
		if ( ! $before['editable'] ) return $this->error( 'type', 'This product cannot be changed from the basic details task.', 409 );
		if ( ! is_string( $input['expected'] ?? null ) || ! hash_equals( $before['expected'], $input['expected'] ) ) return $this->error( 'stale', 'This product changed elsewhere. Refresh before saving.', 409 );
		foreach ( [ 'name','price','status' ] as $key ) if ( ! is_string( $input[ $key ] ?? null ) ) return $this->error( 'input', 'Complete the product details.' );
		$description_changed = array_key_exists( 'description', $input );
		$short_changed = array_key_exists( 'shortDescription', $input );
		if ( $short_changed && ! is_string( $input['shortDescription'] ) ) return $this->error( 'input', 'Complete the product short description.' );
		if ( $description_changed && ! is_string( $input['description'] ) ) return $this->error( 'input', 'Complete the product description.' );
		if ( strlen( $input['name'] ) > 960 || ( $description_changed && strlen( $input['description'] ) > 100000 ) || ( $short_changed && strlen( $input['shortDescription'] ) > 5000 ) ) return $this->error( 'size', 'The product details exceed the writing limit.' );
		$name = sanitize_text_field( $input['name'] );
		if ( '' === $name || preg_match_all( '/./us', $name ) > 240 ) return $this->error( 'name', 'Enter a product name of up to 240 characters.' );
		$status = $input['status'];
		if ( ! in_array( $status, [ 'draft','pending','publish','private' ], true ) ) return $this->error( 'status', 'Choose a valid product status.' );
		if ( in_array( $status, [ 'publish','private' ], true ) && ! current_user_can( 'publish_products' ) ) return $this->error( 'publish', 'Your role cannot publish products.', 403 );
		if ( '' !== $input['price'] && ! preg_match( '/^\d{1,12}(?:\.\d{1,6})?$/D', $input['price'] ) ) return $this->error( 'price', 'Enter a non-negative price, using a dot for decimals.' );
		$price = '' === $input['price'] ? '' : wc_format_decimal( $input['price'] );
		if(in_array($before['type'],['variable','grouped'],true)&&$price!==$before['price'])return $this->error('price','Set prices on the individual products or variations.',409);
		$categories = $input['categories'] ?? null;
		if ( ! is_array( $categories ) || count( $categories ) > 100 ) return $this->error( 'categories', 'Choose existing product categories.' );
		$tax = get_taxonomy( 'product_cat' );
		if ( ! $tax || ! current_user_can( $tax->cap->assign_terms ) ) return $this->error( 'categories', 'Your role cannot assign product categories.', 403 );
		foreach ( $categories as $term ) if ( ! is_int( $term ) || $term < 1 || ! term_exists( $term, 'product_cat' ) ) return $this->error( 'categories', 'Choose existing product categories.' );
		$categories = array_values( array_unique( $categories ) ); sort( $categories, SORT_NUMERIC );
		$tag_changed = array_key_exists( 'tags', $input );
		$tags = $tag_changed ? $input['tags'] : $before['tags'];
		if ( $tag_changed ) {
			if ( ! is_array( $tags ) || count( $tags ) > 100 ) return $this->error( 'tags', 'Choose up to 100 existing product tags.' );
			$tag_taxonomy = get_taxonomy( 'product_tag' );
			if ( ! $tag_taxonomy || ! current_user_can( $tag_taxonomy->cap->assign_terms ) ) return $this->error( 'tags', 'Your role cannot assign product tags.', 403 );
			foreach ( $tags as $term ) if ( ! is_int( $term ) || $term < 1 || ! term_exists( $term, 'product_tag' ) ) return $this->error( 'tags', 'Choose existing product tags.' );
		}
		$tags = array_values( array_unique( $tags ) ); sort( $tags, SORT_NUMERIC );
		$image = array_key_exists( 'imageId', $input ) ? Workspace_Media::validate( $input['imageId'], $before['imageId'] ) : null;
		if ( is_wp_error( $image ) ) return $image;
		$gallery = $input['galleryIds'] ?? null;
		if ( array_key_exists( 'galleryIds', $input ) ) {
			if ( ! is_array( $gallery ) || count( $gallery ) > 20 || count( array_unique( $gallery, SORT_REGULAR ) ) !== count( $gallery ) ) return $this->error( 'gallery', 'Choose up to 20 different gallery images.' );
			if ( $gallery !== $before['galleryIds'] && ! current_user_can( 'upload_files' ) ) return $this->error( 'gallery', 'Your role cannot change gallery images.', 403 );
			foreach ( $gallery as $attachment ) { $valid = Workspace_Media::validate( $attachment, in_array( $attachment, $before['galleryIds'], true ) ? $attachment : 0 ); if ( is_wp_error( $valid ) || 0 === $valid ) return $this->error( 'gallery', 'Choose images you can manage in the media library.', 403 ); }
		}
		// Omitted content is an explicit no-op: a price/image edit must never parse or sanitize legacy HTML.
		$description = $description_changed ? wp_kses_post( $input['description'] ) : $before['description'];
		$short = $short_changed ? wp_kses_post( $input['shortDescription'] ) : $before['shortDescription'];
		try {
			$product->set_name( $name ); if ( $description_changed ) $product->set_description( $description ); if ( $short_changed ) $product->set_short_description( $short ); if($before['type']==='simple')$product->set_regular_price( $price ); $product->set_status( $status ); $product->set_category_ids( $categories ); if ( $tag_changed ) $product->set_tag_ids( $tags );
			if ( null !== $image ) $product->set_image_id( $image );
			if ( null !== $gallery ) $product->set_gallery_image_ids( $gallery );
			$saved = $product->save();
		} catch ( \Throwable $error ) { return $this->error( 'write', 'The save could not be confirmed. Refresh before retrying.', 409 ); }
		if ( ! $saved || is_wp_error( $saved ) ) return $this->error( 'write', 'The save could not be confirmed. Refresh before retrying.', 409 );
		$record = wc_get_product( $id );
		if ( ! $record ) return $this->error( 'readback', 'The saved product could not be read. Refresh before retrying.', 409 );
		$after = $this->project_product( $record );
		foreach ( [ 'name'=>$name, 'description'=>$description, 'shortDescription'=>$short, 'price'=>$price, 'status'=>$status ] as $key=>$value ) if ( $after[ $key ] !== $value ) return $this->error( 'readback', 'The saved product differs. Refresh and review it.', 409 );
		if ( $after['categories'] !== $categories ) return $this->error( 'readback', 'The saved categories differ. Refresh and review them.', 409 );
		if ( $after['tags'] !== $tags ) return $this->error( 'readback', 'The saved product tags differ. Refresh and review them.', 409 );
		if ( ( null !== $image && $after['imageId'] !== $image ) || ( null !== $gallery && $after['galleryIds'] !== $gallery ) ) return $this->error( 'image_readback', 'The saved images differ. Reload and review them.', 409 );
		// Stock, SKU, sale dates and tax/shipping properties are untouched by this task.
		return $after;
	}
	private function product_tax_record( int $id ) {
		if ( ! function_exists( 'wc_get_product' ) || ! current_user_can( 'edit_products' ) || ! current_user_can( 'edit_post', $id ) ) return $this->error( 'forbidden', 'Your role cannot manage this product tax setup.', 403 );
		$product = wc_get_product( $id ); if ( ! $product ) return $this->error( 'missing', 'This product is unavailable.', 404 );
		$projected = $this->project_product( $product ); if ( ! $projected['taxEditable'] ) return $this->error( 'type', 'Tax classification is available for simple, external and variable parent products.', 409 );
		$settings = ( new Tax_Workspace_Service() )->settings(); if ( is_wp_error( $settings ) ) return $settings;
		$classes = array_values( array_filter( $settings['classes'], static fn( $item ) => 'inherit' !== $item['value'] ) );
		$known = in_array( $projected['taxClass'], array_column( $classes, 'value' ), true );
		return [ 'id'=>$id, 'name'=>$projected['name'], 'type'=>$projected['type'], 'taxStatus'=>$projected['taxStatus'], 'taxClass'=>$projected['taxClass'], 'classes'=>$classes, 'large'=>$settings['large'], 'effectiveEnabled'=>$settings['effectiveEnabled'], 'canEdit'=>! $settings['large'] && $known, 'productExpected'=>$projected['expected'], 'expected'=>hash_hmac( 'sha256', serialize( [ $projected['expected'], $settings['expected'] ] ), wp_salt( 'nonce' ) ) ];
	}
	public function read_product_tax( \WP_REST_Request $request ): \WP_REST_Response {
		if ( 'GET' !== $request->get_method() || ! $this->permission( $request ) ) return $this->response( $this->error( 'forbidden', 'Product tax setup is unavailable.', 403 ) );
		return $this->response( $this->product_tax_record( Task_Workspace_UI::id( $request->get_url_params()['id'] ?? 0 ) ) );
	}
	public function save_product_tax( \WP_REST_Request $request ): \WP_REST_Response {
		if ( 'POST' !== $request->get_method() || ! $this->permission( $request ) ) return $this->response( $this->error( 'forbidden', 'Product tax setup is unavailable.', 403 ) );
		$body = $request->get_json_params(); return $this->response( $this->save_product_tax_record( Task_Workspace_UI::id( $request->get_url_params()['id'] ?? 0 ), is_array( $body ) ? $body : [] ) );
	}
	public function save_product_tax_record( int $id, array $input ) {
		if ( array_diff( array_keys( $input ), [ 'taxStatus','taxClass','expected','acceptEffect' ] ) || ! is_string( $input['taxStatus'] ?? null ) || ! is_string( $input['taxClass'] ?? null ) || ! is_string( $input['expected'] ?? null ) || true !== ( $input['acceptEffect'] ?? false ) ) return $this->error( 'tax_input', 'Review and confirm this product tax change.' );
		if ( ! in_array( $input['taxStatus'], [ 'taxable','shipping','none' ], true ) ) return $this->error( 'tax_status', 'Choose taxable, shipping only or no tax.' );
		global $wpdb; $lock = 'kiwe-tax-' . substr( hash( 'sha256', $wpdb->prefix ), 0, 32 ); if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', $lock ) ) ) return $this->error( 'busy', 'Another tax change is running. Refresh and review.', 409 );
		try {
			$before = $this->product_tax_record( $id ); if ( is_wp_error( $before ) ) return $before; if ( ! hash_equals( $before['expected'], $input['expected'] ) ) return $this->error( 'stale', 'This product or the tax classes changed. Refresh before saving.', 409 ); if ( ! $before['canEdit'] ) return $this->error( 'tax_protected', 'Your designer manages this custom or large product tax setup.', 409 );
			if ( ! in_array( $input['taxClass'], array_column( $before['classes'], 'value' ), true ) ) return $this->error( 'tax_class', 'Choose a displayed native tax class.' );
			if ( $before['taxStatus'] === $input['taxStatus'] && $before['taxClass'] === $input['taxClass'] ) return $before;
			$product = wc_get_product( $id ); if ( ! $product ) return $this->error( 'missing', 'This product is unavailable.', 404 );
			$preserved = $this->project_product( $product ); unset( $preserved['taxStatus'], $preserved['taxClass'], $preserved['modifiedGmt'], $preserved['expected'] );
			try { $product->set_tax_status( $input['taxStatus'] ); $product->set_tax_class( $input['taxClass'] ); $saved = $product->save(); } catch ( \Throwable $error ) { return $this->error( 'tax_write', 'The product tax change could not be confirmed. Refresh before retrying.', 409 ); }
			if ( ! $saved ) return $this->error( 'tax_write', 'The product tax change could not be confirmed. Refresh before retrying.', 409 );
			$after = $this->product_tax_record( $id ); $savedProduct = wc_get_product( $id ); $remaining = $savedProduct ? $this->project_product( $savedProduct ) : []; unset( $remaining['taxStatus'], $remaining['taxClass'], $remaining['modifiedGmt'], $remaining['expected'] );
			if ( is_wp_error( $after ) || $after['taxStatus'] !== $input['taxStatus'] || $after['taxClass'] !== $input['taxClass'] || $after['classes'] !== $before['classes'] || $after['large'] !== $before['large'] || $remaining !== $preserved ) return $this->error( 'tax_readback', 'The saved product tax setup differs. Refresh and review it.', 409 );
			return $after;
		} finally { $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) ); }
	}
public function document(): void {
		if ( ! self::enabled() || ! is_admin() || is_network_admin() || is_user_admin() || wp_doing_ajax() || wp_doing_cron() || ! $this->can_use() || ! in_array( $_SERVER['REQUEST_METHOD'] ?? '', [ 'GET', 'HEAD' ], true ) ) return; nocache_headers(); header( 'Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0', true ); header( 'X-Robots-Tag: noindex, nofollow', true ); header( 'X-Content-Type-Options: nosniff', true ); if ( 'HEAD' === $_SERVER['REQUEST_METHOD'] ) exit; Workspace_Media::enqueue(); wp_enqueue_editor(); wp_enqueue_style( 'kiwe-commerce-app', \DSA\Apps\Asset_Locator::url('assets/css/commerce-app.css'), \DSA\Access\Workspace_Design_Service::app_dependencies( [] ), \DSA\Apps\Asset_Locator::version('assets/css/commerce-app.css') ); wp_enqueue_script( 'kiwe-commerce-app', \DSA\Apps\Asset_Locator::url('assets/js/commerce-app.js'), [ 'wp-element' ], \DSA\Apps\Asset_Locator::version('assets/js/commerce-app.js'), true ); wp_localize_script( 'kiwe-commerce-app', 'KIWE_COMMERCE_APP', [ 'endpoint' => rest_url( 'dsa/v1/workspace/commerce' ), 'controlsEndpoint' => rest_url( 'dsa/v1/workspace/record-controls' ), 'navigationEndpoint' => rest_url( 'dsa/v1/workspace/navigation' ), 'seoEndpoint' => \DSA\AI\AI_Feature_Policy::seo_enabled() ? rest_url( 'dsa/v1/workspace/seo' ) : '', 'taxonomyEndpoint' => rest_url( 'dsa/v1/workspace/taxonomies' ), 'tagGroupEndpoint' => current_user_can( 'edit_products' ) ? rest_url( 'dsa/v1/workspace/tag-groups' ) : '', 'canCreateProductCategories' => current_user_can( 'manage_product_terms' ) && current_user_can( 'edit_product_terms' ), 'canCreateProductTags' => current_user_can( 'manage_product_terms' ) && current_user_can( 'edit_product_terms' ), 'nonce' => wp_create_nonce( 'wp_rest' ), 'home' => Workspace_Admin_Service::url(), ...Workspace_Chrome_Service::projection(), ...( class_exists( Content_Archive_App_Service::class ) ? Content_Archive_App_Service::config() : [] ), 'canUpload'=>current_user_can( 'upload_files' ) ] ); ?><!doctype html><html <?php language_attributes(); ?>><head><meta charset="<?php bloginfo( 'charset' ); ?>"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?php esc_html_e( 'Store · Kiwe', 'dsa' ); ?></title><?php Workspace_Media::styles(); wp_print_styles( [ 'editor-buttons', 'kiwe-commerce-app' ] ); ?></head><body class="kiwe-commerce-app"><div id="kiwe-commerce-app"><p><?php esc_html_e( 'Opening your store…', 'dsa' ); ?></p><noscript><?php esc_html_e( 'This workspace needs JavaScript. Switch off the experiment to restore the native store screen.', 'dsa' ); ?></noscript></div><?php Workspace_Media::footer(); wp_print_scripts( [ 'editor', 'quicktags', 'wp-element' ] ); \_WP_Editors::print_default_editor_scripts(); wp_print_scripts( [ 'kiwe-commerce-app' ] ); ?></body></html><?php exit;
	}
}
