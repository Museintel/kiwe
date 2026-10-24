<?php
namespace DSA\Access;
if ( ! defined( 'ABSPATH' ) ) exit;

/** Private native product choices; no visitor hooks, catalog copies or workers. */
final class Product_Variation_Workspace_Service {
	private int $affectedId = 0;
	public static function available(): bool { return function_exists('wc_get_product') && class_exists('WC_Product_Attribute') && class_exists('WC_Product_Variation'); }
	private function error(string $code,string $message,int $status=409) { return new \WP_Error('kiwe_product_options_'.$code,$message,['status'=>$status]); }
	public static function attribute_values($product): array {
		$out=[];foreach($product->get_attributes('edit') as $attribute) if($attribute instanceof \WC_Product_Attribute)$out[]=['id'=>(int)$attribute->get_id(),'name'=>$attribute->get_name(),'options'=>array_values($attribute->get_options()),'position'=>(int)$attribute->get_position(),'visible'=>(bool)$attribute->get_visible(),'variation'=>(bool)$attribute->get_variation()];return $out;
	}
	private function rows(string $sql): array {
		global $wpdb;$out=$wpdb->get_results($sql,ARRAY_A);if($wpdb->last_error!==''||!is_array($out))throw new \RuntimeException('Native product choices could not be read.');return $out;
	}
	private function fresh(int $id) {
		clean_post_cache($id);wp_cache_delete($id,'post_meta');$p=wc_get_product($id);if($p)$p->get_data_store()->read($p);return $p;
	}
	private function state(int $id) {
		if(!self::available()||!current_user_can('edit_products')||!current_user_can('edit_post',$id))return $this->error('forbidden','Your account cannot edit this product.',403);
		try{
			$p=$this->fresh($id);if(!$p)return $this->error('missing','This product is unavailable.',404);
			if(!in_array($p->get_type(),['simple','variable'],true)||!in_array($p->get_status('edit'),['draft','pending','publish','private'],true))return $this->error('type','This product setup needs its native provider editor.');
			global $wpdb;$raw=$this->rows($wpdb->prepare("SELECT meta_id,post_id,meta_key,meta_value FROM {$wpdb->postmeta} WHERE post_id=%d ORDER BY meta_id LIMIT 1001",$id));
			$children=$this->rows($wpdb->prepare("SELECT ID,post_parent,post_status,menu_order,post_modified_gmt FROM {$wpdb->posts} WHERE post_parent=%d AND post_type='product_variation' ORDER BY ID LIMIT 201",$id));
			$childIds=array_map('intval',array_column($children,'ID'));$choiceRows=[];
			if($childIds)$choiceRows=$this->rows("SELECT meta_id,post_id,meta_key,meta_value FROM {$wpdb->postmeta} WHERE post_id IN (".implode(',',$childIds).") AND meta_key LIKE 'attribute\\_%' ORDER BY meta_id LIMIT 4001");
			$attributes=self::attribute_values($p);$large=count($attributes)>20||count($raw)>1000||count($children)>200||count($choiceRows)>4000||strlen(serialize($raw))>2000000;
			$items=[];$terms=[];
			foreach(array_slice($attributes,0,20)as$row){
				$key=sanitize_title($row['name']);$choices=[];$native=$row['id']?wc_get_attribute($row['id']):null;$taxonomy=$row['id']?get_taxonomy($row['name']):null;
				$editable=!$row['id']||($native&&$native->slug===$row['name']&&$native->type==='select'&&$taxonomy);
				if(count($row['options'])>100)$large=true;
				if($row['id']&&$editable){$selected=get_terms(['taxonomy'=>$row['name'],'include'=>array_map('intval',$row['options']),'hide_empty'=>false,'number'=>101]);if(is_wp_error($selected))throw new \RuntimeException('Native attribute values are unavailable.');foreach($selected as$term){$choices[(int)$term->term_id]=['id'=>(int)$term->term_id,'value'=>$term->slug,'label'=>$term->name];$terms[]=[(int)$term->term_id,$term->slug,$term->name];}if(count($choices)!==count($row['options']))$editable=false;}
				else if(!$row['id'])foreach($row['options']as$option){if(!is_string($option)||strlen($option)>240)$editable=false;else $choices[$option]=['id'=>$option,'value'=>$option,'label'=>$option];}
				$items[]=$row+['key'=>$key,'label'=>$native?$native->name:$row['name'],'choices'=>array_values($choices),'canEdit'=>$editable&&(!$taxonomy||current_user_can($taxonomy->cap->assign_terms))];
			}
			$relationships=$this->rows($wpdb->prepare("SELECT tt.taxonomy,tr.term_taxonomy_id FROM {$wpdb->term_relationships} tr INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id=tr.term_taxonomy_id WHERE tr.object_id=%d ORDER BY tt.taxonomy,tr.term_taxonomy_id LIMIT 1001",$id));if(count($relationships)>1000)$large=true;
			$settings=[get_option('timezone_string'),get_option('gmt_offset'),get_option('woocommerce_currency'),get_option('woocommerce_price_num_decimals'),get_option('woocommerce_manage_stock'),get_option('woocommerce_notify_no_stock_amount')];
			$expected=hash_hmac('sha256',serialize([get_post($id,ARRAY_A),$raw,$children,$choiceRows,$attributes,$terms,$relationships,$settings]),wp_salt('nonce'));
			return ['product'=>$p,'post'=>get_post($id,ARRAY_A),'id'=>$id,'name'=>$p->get_name('edit'),'type'=>$p->get_type(),'attributes'=>$items,'defaults'=>$p->get_default_attributes('edit'),'expected'=>$expected,'large'=>$large,'children'=>$children,'choiceRows'=>$choiceRows,'raw'=>$raw,'relationships'=>$relationships,'canCreate'=>!$large&&$p->is_type('variable')&&current_user_can('create_products')&&count($children)<200];
		}catch(\Throwable $e){return $this->error('read','Product choices could not be read. Refresh and review.');}
	}
	public function setup(int $id) {
		$s=$this->state($id);if(is_wp_error($s))return $s;return array_intersect_key($s,array_flip(['id','name','type','attributes','defaults','expected','large','canCreate']));
	}
	public function options(int $id,int $attribute=0,array $input=[]) {
		$s=$this->state($id);if(is_wp_error($s))return $s;
		if(!$attribute){$catalog=(new Product_Attribute_Workspace_Service())->catalog($input);if(is_wp_error($catalog))return $catalog;return ['items'=>$catalog['items'],'page'=>$catalog['page'],'hasNext'=>$catalog['hasNext'],'large'=>$catalog['large']];}
		$native=wc_get_attribute($attribute);if(!$native||$native->type!=='select'||!taxonomy_exists($native->slug))return $this->error('attribute','Choose a registered native attribute.');
		$terms=get_terms(['taxonomy'=>$native->slug,'hide_empty'=>false,'number'=>201,'orderby'=>'name','order'=>'ASC']);if(is_wp_error($terms)||count($terms)>200)return $this->error('limit','This attribute needs designer review before assigning values.');
		return ['id'=>$attribute,'name'=>$native->slug,'label'=>$native->name,'items'=>array_map(static fn($t)=>['id'=>(int)$t->term_id,'value'=>$t->slug,'label'=>$t->name],$terms),'canAssign'=>current_user_can(get_taxonomy($native->slug)->cap->assign_terms)];
	}
	private function child(array $s,int $id) {
		if(!in_array($id,array_map('intval',array_column($s['children'],'ID')),true))return $this->error('child','This variation does not belong to the selected product.',404);
		$p=$this->fresh($id);if(!$p||!$p->is_type('variation')||(int)$p->get_parent_id()!==$s['id'])return $this->error('child','This variation is unavailable.',404);
		// Native variation edit_post mapping is not parent-aware. Never infer access from a child ID alone.
		if(!in_array($p->get_status('edit'),['publish','private'],true))return $this->error('status','This variation is review-only.');return $p;
	}
	private function variation_values($p): array {
		$zone=wp_timezone();$date=static fn($d)=>$d?(new \DateTimeImmutable('@'.$d->getTimestamp()))->setTimezone($zone)->format('Y-m-d\TH:i:s'):'';
		return ['id'=>(int)$p->get_id(),'attributes'=>$p->get_attributes('edit'),'enabled'=>$p->get_status('edit')==='publish','price'=>(string)$p->get_regular_price('edit'),'salePrice'=>(string)$p->get_sale_price('edit'),'startsAt'=>$date($p->get_date_on_sale_from('edit')),'endsAt'=>$date($p->get_date_on_sale_to('edit')),'sku'=>(string)$p->get_sku('edit'),'manageStock'=>(bool)$p->get_data()['manage_stock'],'stock'=>$p->get_data()['stock_quantity'],'stockStatus'=>$p->get_stock_status('edit'),'backorders'=>$p->get_backorders('edit'),'imageId'=>(int)$p->get_image_id('edit'),'description'=>$p->get_description('edit'),'virtual'=>(bool)$p->get_virtual('edit'),'weight'=>(string)$p->get_weight('edit'),'length'=>(string)$p->get_length('edit'),'width'=>(string)$p->get_width('edit'),'height'=>(string)$p->get_height('edit'),'shippingClassId'=>(int)$p->get_shipping_class_id('edit'),'taxClass'=>$p->get_tax_class('edit'),'position'=>(int)$p->get_menu_order('edit')];
	}
	public function variations(int $id,array $input=[]) {
		$s=$this->state($id);if(is_wp_error($s))return $s;if($s['type']!=='variable')return $this->error('type','Variations belong to a variable product.');
		$page=max(1,min(1000,(int)($input['page']??1)));$search=Task_Workspace_UI::text($input['search']??'',80);$status=in_array($input['status']??'', ['enabled','disabled'],true)?$input['status']:'all';$sort=($input['sort']??'position')==='newest'?'newest':'position';$rows=$s['children'];
		if($status!=='all')$rows=array_values(array_filter($rows,static fn($r)=>$r['post_status']===($status==='enabled'?'publish':'private')));
		if($search!==''){$matches=[];foreach($s['choiceRows']as$r)if(stripos($r['meta_value'],$search)!==false)$matches[(int)$r['post_id']]=true;$rows=array_values(array_filter($rows,static fn($r)=>(ctype_digit($search)&&(int)$r['ID']===(int)$search)||isset($matches[(int)$r['ID']])));}
		usort($rows,static fn($a,$b)=>$sort==='newest'?((int)$b['ID']<=>(int)$a['ID']):(((int)$a['menu_order']<=>(int)$b['menu_order'])?:((int)$a['ID']<=>(int)$b['ID'])));$items=[];
		foreach(array_slice($rows,($page-1)*20,20)as$r){$p=$this->child($s,(int)$r['ID']);if(is_wp_error($p)){$items[]=['id'=>(int)$r['ID'],'label'=>'Variation #'.$r['ID'],'status'=>$r['post_status'],'canEdit'=>false];continue;}$values=$this->variation_values($p);$items[]=['id'=>$values['id'],'label'=>wp_strip_all_tags(wc_get_formatted_variation($p,true,false))?:'Variation #'.$values['id'],'status'=>$values['enabled']?'enabled':'disabled','price'=>$values['price'],'stockStatus'=>$values['stockStatus'],'image'=>Workspace_Media::image($values['imageId']),'canEdit'=>!$s['large']&&!$p->is_downloadable()];}
		return ['id'=>$id,'name'=>$s['name'],'items'=>$items,'expected'=>$s['expected'],'large'=>$s['large'],'canCreate'=>$s['canCreate']&&count(array_filter($s['attributes'],static fn($a)=>$a['variation']))>0,'page'=>$page,'hasNext'=>count($rows)>$page*20,'count'=>count($s['children']),'search'=>$search,'status'=>$status,'sort'=>$sort];
	}
	public function variation(int $parent,int $id) {
		$s=$this->state($parent);if(is_wp_error($s))return $s;$p=$id?$this->child($s,$id):new \WC_Product_Variation();if(is_wp_error($p))return $p;if(!$id){$p->set_parent_id($parent);$p->set_status('private');}
		if($s['type']!=='variable'||($id&&$p->is_downloadable()))return $this->error('type','This variation needs its native provider editor.');
		$values=$this->variation_values($p);$meta=$id?$this->metadata($id):[];if(is_wp_error($meta))return $meta;
		$expected=hash_hmac('sha256',serialize([$s['expected'],$values,$meta,$id?get_post($id,ARRAY_A):null]),wp_salt('nonce'));
		$shipping=get_terms(['taxonomy'=>'product_shipping_class','hide_empty'=>false,'number'=>201]);if(is_wp_error($shipping))return $this->error('shipping','Shipping classes could not be read.');
		$choices=array_values(array_filter($s['attributes'],static fn($a)=>$a['variation']));$canEdit=!$s['large']&&count($shipping)<=200&&($id>0||$s['canCreate'])&&count(array_filter($choices,static fn($a)=>!$a['canEdit']))===0;
		return $values+['expected'=>$expected,'parentExpected'=>$s['expected'],'parentId'=>$parent,'parentName'=>$s['name'],'parentManagesStock'=>(bool)$s['product']->get_manage_stock('edit'),'choices'=>$choices,'image'=>Workspace_Media::image($values['imageId']),'canUpload'=>current_user_can('upload_files'),'canPublish'=>current_user_can('publish_products'),'canEdit'=>$canEdit,'currency'=>get_option('woocommerce_currency',''),'timezone'=>wp_timezone_string(),'shippingClasses'=>array_map(static fn($t)=>['id'=>(int)$t->term_id,'name'=>$t->name],array_slice($shipping,0,200)),'taxClasses'=>array_merge([['value'=>'parent','label'=>'Same as product'],['value'=>'','label'=>'Standard']],array_map(static fn($slug)=>['value'=>$slug,'label'=>ucwords(str_replace('-',' ',$slug))],\WC_Tax::get_tax_class_slugs()))];
	}
	private function metadata(int $id) { global $wpdb;$rows=$this->rows($wpdb->prepare("SELECT meta_id,meta_key,meta_value FROM {$wpdb->postmeta} WHERE post_id=%d ORDER BY meta_id LIMIT 1001",$id));return count($rows)>1000||strlen(serialize($rows))>2000000?$this->error('limit','This product metadata needs designer review.'):$rows; }
	private function begin(): bool {
		global $wpdb;$tables=[$wpdb->posts,$wpdb->postmeta,$wpdb->terms,$wpdb->term_taxonomy,$wpdb->term_relationships,$wpdb->options,$wpdb->prefix.'wc_product_meta_lookup',$wpdb->prefix.'wc_product_attributes_lookup'];
		$rows=$this->rows($wpdb->prepare('SELECT TABLE_NAME,ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('.implode(',',array_fill(0,count($tables),'%s')).')',...$tables));
		if(count($rows)!==count($tables)||(string)$wpdb->get_var('SELECT @@session.in_transaction')!=='0')return false;foreach($rows as$r)if(strtoupper($r['ENGINE'])!=='INNODB')return false;
		return $wpdb->query('SET TRANSACTION ISOLATION LEVEL READ COMMITTED')!==false&&$wpdb->query('START TRANSACTION')!==false;
	}
	private function protected_meta(array $rows,array $allowed): array { $out=[];foreach($rows as$r)if(!in_array($r['meta_key'],$allowed,true))$out[]=$r;return $out; }
	private function mutation(int $parent,int $child,array $input,callable $write,array $allowed,bool $attributes=false) {
		if(!self::available()||!current_user_can('edit_products')||!current_user_can('edit_post',$parent))return $this->error('forbidden','Your account cannot change this product.',403);
		if(($input['confirmed']??false)!==true||!is_string($input['expected']??null))return $this->error('confirmation','Review and confirm one product change.',400);
		global $wpdb;$lock='kiwe-product-options-'.substr(hash('sha256',$wpdb->prefix.':'.$parent),0,32);if((string)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,0)',$lock))!=='1')return $this->error('busy','Another product change is running. Refresh and review.');$transaction=false;$affected=$child;$this->affectedId=0;
		try{
			$s=$this->state($parent);if(is_wp_error($s))return $s;if($s['large'])return $this->error('limit','This product needs designer review before another change.');
			$record=$attributes?$this->setup($parent):$this->variation($parent,$child);if(is_wp_error($record))return $record;if(!hash_equals($record['expected'],$input['expected']))return $this->error('stale','The product, inventory or choices changed. Refresh before saving.');
			$before=$attributes?$this->metadata($parent):($child?$this->metadata($child):[]);if(is_wp_error($before))return $before;
			if(!$this->begin())return $this->error('transaction','Safe native transactions are unavailable. Ask your site administrator to review the store.');$transaction=true;
			$fresh=$attributes?$this->setup($parent):$this->variation($parent,$child);if(is_wp_error($fresh)||!hash_equals($record['expected'],$fresh['expected']))throw new \RuntimeException('The saved product changed during review.');
			$result=$write($s,$record);if(is_wp_error($result)){ $wpdb->query('ROLLBACK');$transaction=false;return $result; }$affected=(int)$result['id'];
			$after=$this->metadata($attributes?$parent:$affected);if(is_wp_error($after))throw new \RuntimeException('Saved metadata unavailable.');
			if(($attributes||$child)&&$this->protected_meta($before,$allowed)!==$this->protected_meta($after,$allowed))throw new \RuntimeException('A native hook changed protected metadata.');
			$parentAfter=$this->state($parent);if(is_wp_error($parentAfter))throw new \RuntimeException('Parent readback unavailable.');
			$protectedPost=['post_title','post_content','post_excerpt','post_status','post_author','post_name','post_parent','menu_order'];if(array_intersect_key($s['post'],array_flip($protectedPost))!==array_intersect_key($parentAfter['post'],array_flip($protectedPost)))throw new \RuntimeException('Protected parent content changed.');
			$parentAllowed=$attributes?$allowed:['_product_version','_price','_regular_price','_sale_price','_stock_status'];
			if($this->protected_meta($s['raw'],$parentAllowed)!==$this->protected_meta($parentAfter['raw'],$parentAllowed))throw new \RuntimeException('Protected parent metadata changed.');
			$taxes=['product_visibility'];if($attributes){$key=$input['key'];if(!empty($input['attribute']['id'])){$definition=wc_get_attribute($input['attribute']['id']);if($definition)$taxes[]=$definition->slug;}$taxes[]=$key;}
			$keep=static fn($rows)=>array_values(array_filter($rows,static fn($r)=>!in_array($r['taxonomy'],$taxes,true)));
			if($keep($s['relationships'])!==$keep($parentAfter['relationships']))throw new \RuntimeException('Protected product categories or relationships changed.');
			if($wpdb->query('COMMIT')===false)throw new \RuntimeException('The native transaction could not be confirmed.');$transaction=false;return $result;
		}catch(\Throwable $e){return $this->error('write','This save could not be confirmed. Refresh the saved state before trying again.');}
		finally{if($transaction)$wpdb->query('ROLLBACK');foreach(array_unique([$parent,$child,$affected,$this->affectedId])as$id)if($id){clean_post_cache($id);wp_cache_delete($id,'post_meta');wc_delete_product_transients($id);}\WC_Cache_Helper::invalidate_cache_group('products');$wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock));}
	}
	public function change_attribute(int $id,array $input) {
		if(array_diff(array_keys($input),['key','attribute','default','remove','expected','confirmed'])||!is_string($input['key']??null)||!is_bool($input['remove']??null))return $this->error('input','Review one product attribute.',400);
		return $this->mutation($id,0,$input,function($s,$record)use($input){
			$all=$s['product']->get_attributes('edit');$key=$input['key'];$old=$key!==''?($all[$key]??null):null;if(($key!==''||$input['remove'])&&!$old)return $this->error('missing','This product attribute is unavailable.',404);
			$defaults=$s['defaults'];$rows=array_column($s['attributes'],null,'key');$removed=$input['remove'];$wanted=null;
			if(!$removed){$row=$input['attribute']??null;if(!is_array($row)||array_diff(array_keys($row),['id','name','options','visible','variation'])||!is_int($row['id']??null)||$row['id']<0||!is_string($row['name']??null)||!is_array($row['options']??null)||!array_is_list($row['options'])||count($row['options'])<1||count($row['options'])>100||!is_bool($row['visible']??null)||!is_bool($row['variation']??null))return $this->error('input','Choose one attribute and its values.',400);
				if($row['id']){$native=wc_get_attribute($row['id']);$tax=$native?get_taxonomy($native->slug):null;if(!$native||$native->type!=='select'||!$tax||!current_user_can($tax->cap->assign_terms))return $this->error('attribute','Your role cannot assign this native attribute.',403);$row['name']=$native->slug;foreach($row['options']as$term)if(!is_int($term)||$term<1||!term_exists($term,$native->slug))return $this->error('term','Choose existing attribute values.',400);}
				else{$row['name']=trim($row['name']);if($row['name']===''||strlen($row['name'])>80||sanitize_text_field($row['name'])!==$row['name']||str_starts_with(sanitize_title($row['name']),'pa_'))return $this->error('name','Enter a product-specific attribute name.',400);foreach($row['options']as$option)if(!is_string($option)||trim($option)!==$option||$option===''||strlen($option)>240||sanitize_text_field($option)!==$option||str_contains($option,'|'))return $this->error('value','Enter one plain value per line, without a vertical bar.',400);}
				if(count(array_unique($row['options'],SORT_REGULAR))!==count($row['options']))return $this->error('duplicate','Choose each value once.',400);
				$newKey=sanitize_title($row['name']);if($newKey===''||($old&&($old->get_id()!==$row['id']||$key!==$newKey)))return $this->error('identity','Keep the native attribute address. Rename reusable attributes in Store settings.');if(!$old&&isset($all[$newKey]))return $this->error('duplicate','This product already has that attribute.');if(!$old&&count($all)>=20)return $this->error('limit','This product supports up to twenty reviewed attributes.');$key=$newKey;
				$wanted=new \WC_Product_Attribute();$wanted->set_id($row['id']);$wanted->set_name($row['name']);$wanted->set_options($row['options']);$wanted->set_visible($row['visible']);$wanted->set_variation($row['variation']);$wanted->set_position($old?$old->get_position():count($all));
			}
			$values=[];if($wanted){if($wanted->is_taxonomy()){foreach($wanted->get_options()as$term)$values[]=get_term($term,$wanted->get_name())->slug;}else $values=$wanted->get_options();}
			foreach($s['choiceRows']as$choice)if($choice['meta_key']==='attribute_'.$key&&(!$wanted||!$wanted->get_variation()||($choice['meta_value']!==''&&!in_array($choice['meta_value'],$values,true))))return $this->error('assigned','Saved variations still use this attribute or its values. Keep them connected.');
			$default=$input['default']??null;if(!is_string($default)||($default!==''&&(!$wanted||!$wanted->get_variation()||!in_array($default,$values,true))))return $this->error('default','Choose an assigned variation value as the default, or leave it unset.',400);
			if($removed)unset($all[$key]);else $all[$key]=$wanted;if($default==='')unset($defaults[$key]);else $defaults[$key]=$default;
			$s['product']->set_attributes(array_values($all));$s['product']->set_default_attributes($defaults);if(!$s['product']->save())throw new \RuntimeException('Native attribute save failed.');$after=$this->setup($s['id']);if(is_wp_error($after))throw new \RuntimeException('Attribute readback failed.');$saved=array_column($after['attributes'],null,'key');
			if($removed&&isset($saved[$key]))throw new \RuntimeException('Removed attribute remains.');
			if(!$removed){if(!isset($saved[$key]))throw new \RuntimeException('Saved attribute missing.');$expected=['id'=>(int)$wanted->get_id(),'name'=>$wanted->get_name(),'options'=>$wanted->get_options(),'position'=>(int)$wanted->get_position(),'visible'=>(bool)$wanted->get_visible(),'variation'=>(bool)$wanted->get_variation()];$actual=array_intersect_key($saved[$key],$expected);if($wanted->is_taxonomy()){sort($expected['options'],SORT_NUMERIC);sort($actual['options'],SORT_NUMERIC);}if($actual!==$expected)throw new \RuntimeException('Native attribute readback differed.');}
			if($after['defaults']!==$defaults)throw new \RuntimeException('Native defaults differed.');
			return ['id'=>$s['id'],'changed'=>true,'action'=>'attribute','setup'=>$after];
		},['_product_attributes','_default_attributes','_product_version','_price','_regular_price','_sale_price','_stock_status'],true);
	}
	public function save_variation(int $parent,int $id,array $input) {
		$fields=['attributes','enabled','price','salePrice','startsAt','endsAt','sku','manageStock','stock','stockStatus','backorders','imageId','description','virtual','weight','length','width','height','shippingClassId','taxClass','position'];
		if(array_diff(array_keys($input),[...$fields,'expected','confirmed'])||array_diff($fields,array_keys($input)))return $this->error('input','Complete one variation only.',400);
		return $this->mutation($parent,$id,$input,function($s,$record)use($input,$id,$fields){
			if(!$record['canEdit']||(!$id&&!current_user_can('create_products')))return $this->error('forbidden','Your role cannot make this variation change.',403);
			$axes=array_column($record['choices'],null,'key');if(!$axes||!is_array($input['attributes'])||array_diff(array_keys($input['attributes']),array_keys($axes)))return $this->error('choices','Select the product variation attributes.',400);
			$attrs=[];foreach($axes as$key=>$axis){$value=$input['attributes'][$key]??null;if(!is_string($value)||($value!==''&&!in_array($value,array_column($axis['choices'],'value'),true)))return $this->error('choices','Choose assigned product values or Any value.',400);$attrs[$key]=$value;}
			$maps=[];foreach($s['choiceRows']as$row)$maps[(int)$row['post_id']][substr($row['meta_key'],10)]=$row['meta_value'];foreach($s['children']as$child){if((int)$child['ID']===$id)continue;$other=[];foreach($axes as$key=>$axis)$other[$key]=$maps[(int)$child['ID']][$key]??'';if($other===$attrs)return $this->error('duplicate','A saved variation already has this combination. Open it to make changes.');}
			foreach(['enabled','manageStock','virtual']as$key)if(!is_bool($input[$key]))return $this->error('input','Complete the variation choices.',400);
			if($input['enabled']&&!current_user_can('publish_products'))return $this->error('publish','Your role cannot enable variations for sale.',403);
			foreach(['price','salePrice']as$key)if(!is_string($input[$key])||($input[$key]!==''&&!preg_match('/^\d{1,12}(?:\.\d{1,6})?$/D',$input[$key])))return $this->error('price','Use a non-negative price with a dot for decimals.',400);
			$price=$input['price']===''?'':wc_format_decimal($input['price']);$sale=$input['salePrice']===''?'':wc_format_decimal($input['salePrice']);if($sale!==''&&($price===''||(float)$sale>=(float)$price))return $this->error('price','The sale price must be below the regular price.',400);
			$commerce=new Commerce_App_Service();$from=$commerce->sale_date($input['startsAt']);if(is_wp_error($from))return $from;$to=$commerce->sale_date($input['endsAt']);if(is_wp_error($to))return $to;
			if(($sale===''&&($from!==null||$to!==null))||($to!==null&&(($from!==null&&$to<=$from)||($input['endsAt']!==$record['endsAt']&&$to<=time()))))return $this->error('schedule','Use a valid website-local sale interval, or clear both dates.',400);
			if(!is_string($input['sku'])||strlen($input['sku'])>100||sanitize_text_field($input['sku'])!==$input['sku']||($input['sku']!==''&&!wc_product_has_unique_sku($id,$input['sku'])))return $this->error('sku','Choose a unique native SKU.',400);
			if(($input['stock']!==null&&(!is_int($input['stock'])||abs($input['stock'])>1000000000))||($input['manageStock']&&$input['stock']===null)||!in_array($input['stockStatus'],['instock','outofstock','onbackorder'],true)||!in_array($input['backorders'],['no','notify','yes'],true))return $this->error('stock','Review stock quantity, availability and backorders.',400);
			$image=Workspace_Media::validate($input['imageId'],$record['imageId']);if(is_wp_error($image))return $image;
			if(!is_string($input['description'])||strlen($input['description'])>5000)return $this->error('description','Use a variation description of up to 5,000 bytes.',400);$description=$input['description']===$record['description']?$input['description']:wp_kses_post($input['description']);
			foreach(['weight','length','width','height']as$key)if(!is_string($input[$key])||($input[$key]!==''&&!preg_match('/^\d{1,9}(?:\.\d{1,6})?$/D',$input[$key])))return $this->error('measurement','Use non-negative measurements or leave them blank.',400);
			if(!is_int($input['position'])||$input['position']<0||$input['position']>10000||!is_int($input['shippingClassId'])||$input['shippingClassId']<0)return $this->error('input','Choose a valid native order and shipping class.',400);
			if($input['shippingClassId']!==$record['shippingClassId']){$tax=get_taxonomy('product_shipping_class');if(!$tax||!current_user_can($tax->cap->assign_terms)||($input['shippingClassId']&&!term_exists($input['shippingClassId'],'product_shipping_class')))return $this->error('shipping','Choose a shipping class you can assign.',403);}
			if(!is_string($input['taxClass'])||(!in_array($input['taxClass'],['','parent',...\WC_Tax::get_tax_class_slugs()],true)&&$input['taxClass']!==$record['taxClass']))return $this->error('tax','Choose a native tax class or inheritance.',400);
			$p=$id?$this->child($s,$id):new \WC_Product_Variation();if(is_wp_error($p))return $p;if(!$id){$p->set_parent_id($s['id']);$p->set_status('private');}
			$wanted=$input;$wanted['attributes']=$attrs;$wanted['price']=$price;$wanted['salePrice']=$sale;$wanted['description']=$description;foreach(['weight','length','width','height']as$key)$wanted[$key]=$input[$key]===''?'':wc_format_decimal($input[$key]);
			if($id&&array_intersect_key($record,array_flip($fields))===array_intersect_key($wanted,array_flip($fields)))return ['id'=>$id,'changed'=>false,'action'=>'update','variation'=>$record];
			$p->set_attributes($attrs);$p->set_status($input['enabled']?'publish':'private');$p->set_regular_price($price);$p->set_sale_price($sale);$p->set_date_on_sale_from($from);$p->set_date_on_sale_to($to);$p->set_sku($input['sku']);$p->set_manage_stock($input['manageStock']);if($input['manageStock']||$input['stock']!==$record['stock'])$p->set_stock_quantity($input['stock']);$p->set_stock_status($input['stockStatus']);$p->set_backorders($input['backorders']);$p->set_image_id($image);$p->set_description($description);$p->set_virtual($input['virtual']);foreach(['weight','length','width','height']as$key)$p->{'set_'.$key}($input[$key]);$p->set_shipping_class_id($input['shippingClassId']);$p->set_tax_class($input['taxClass']);$p->set_menu_order($input['position']);try{$saved=$p->save();}finally{$this->affectedId=(int)$p->get_id();}if(!$saved)throw new \RuntimeException('Native variation save failed.');
			\WC_Product_Variable::sync($s['id']);$after=$this->variation($s['id'],(int)$saved);if(is_wp_error($after))throw new \RuntimeException('Saved variation unavailable.');
			foreach($fields as$key){if($key==='stockStatus'&&$input['manageStock'])continue;if($after[$key]!==$wanted[$key])throw new \RuntimeException('Saved variation differs.');}
			return ['id'=>(int)$saved,'changed'=>true,'action'=>$id?'update':'create','variation'=>$after];
		},['_regular_price','_sale_price','_price','_sale_price_dates_from','_sale_price_dates_to','_sku','_manage_stock','_stock','_stock_status','_backorders','_thumbnail_id','_variation_description','_virtual','_weight','_length','_width','_height','_tax_class','_product_version',...array_map(static fn($key)=>'attribute_'.$key,array_keys(is_array($input['attributes']??null)?$input['attributes']:[]))]);
	}
}
