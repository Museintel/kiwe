<?php
/** Disposable native Woo fixture; actual routes/writers/cipher, synthetic people and catalog only. */
if (PHP_SAPI!=='cli'||!defined('ABSPATH')||!defined('KIWE_WORKSPACE_TEST_SITE')||KIWE_WORKSPACE_TEST_SITE!==true||!defined('KIWE_WORKSPACE_EXPECTED_VERSION')) exit(2);
use DSA\Access\Product_Attribute_Workspace_Service as Catalog;
use DSA\Access\Site_Owner_Service as Owner;
use DSA\Backup\Product_Attribute_Snapshot_Service as Labels;
use DSA\Backup\Configuration_Snapshot_Service as Snapshots;
$proof=['schema'=>'kiwe-native-product-attributes.v1','version'=>DSA_VERSION,'php'=>PHP_VERSION,'wordpress'=>$GLOBALS['wp_version'],'woocommerce'=>defined('WC_VERSION')?WC_VERSION:'absent','checks'=>[],'cleanup'=>false];
$assert=static function(bool $ok,string $label)use(&$proof){$proof['checks'][]=['label'=>$label,'passed'=>$ok];if(!$ok)throw new RuntimeException($label);};
$actor=get_current_user_id();$originalOptions=$wpdb->options;$originalCache=$GLOBALS['wp_object_cache'];$table=$wpdb->prefix.'kiwe_attr_qa_'.bin2hex(random_bytes(6));$prefix='kiweattrqa_'.bin2hex(random_bytes(5));$slug='kqa297-'.bin2hex(random_bytes(4));$users=[];$posts=[];$attributes=[];$terms=[];$copies=[];$reviews=[];$filters=[];$http=[];$mail=[];$isolated=false;
$filter=static function(string $hook,callable $callback,int $args=1)use(&$filters){add_filter($hook,$callback,PHP_INT_MAX,$args);$filters[]=[$hook,$callback];};
$request=static function(string $method,string $suffix,array $body=[],bool $nonce=true,bool $mutation=true){$r=new WP_REST_Request($method,'/dsa/v1/workspace/commerce'.$suffix);if($nonce)$r->set_header('X-WP-Nonce',wp_create_nonce('wp_rest'));if($mutation)$r->set_header('X-Kiwe-Mutation','1');$r->set_header('Content-Type','application/json');if($method==='GET')$r->set_query_params($body);else $r->set_body(wp_json_encode($body));return rest_do_request($r);};
$native=new Catalog();
$payload=static function(string $action,array $extra=[],int $attribute=0)use($native){$state=$attribute?$native->values($attribute):$native->catalog();if(is_wp_error($state))throw new RuntimeException('Native attribute state unavailable');return ['action'=>$action,'expected'=>$state['expected'],'acceptEffect'=>true]+$extra;};
$find=static function(int $attribute,int $term)use($native){$state=$native->values($attribute,['sort'=>'id_desc']);if(is_wp_error($state))throw new RuntimeException('Native values unavailable');foreach($state['items']as$item)if($item['id']===$term)return $item;return null;};
$originalCatalogHash=hash('sha256',serialize($wpdb->get_results("SELECT * FROM {$wpdb->prefix}woocommerce_attribute_taxonomies ORDER BY attribute_id",ARRAY_A)));
$originalChoices=[];foreach(['active_plugins','template','stylesheet','kiwe_site_logo_id','kiwe_site_logo_inverse_id','kiwe_seam_design_context_v1',DSA_OPTION_SETTINGS]as$key)$originalChoices[$key]=get_option($key);
try {
 $manifest=DSA\Runtime\Package_Manifest::verify(true);$assert(DSA_VERSION===KIWE_WORKSPACE_EXPECTED_VERSION&&!empty($manifest['valid'])&&!empty($manifest['complete']),'matching complete installed candidate');
 $assert(Catalog::available()&&!wp_using_ext_object_cache(),'actual Woo owners and request-local cache available');
 $filter('pre_wp_mail',static function($return,$args)use(&$mail){$mail[]=true;return true;},2);
 $filter('pre_http_request',static function($return,$args,$url)use(&$http){$http[]=true;return new WP_Error('kiwe_qa_block','Synthetic acceptance blocks outbound work');},3);
 $ownerOff=static fn()=>[];$filter('pre_option_'.Owner::OPTION,$ownerOff);
 foreach(['owner'=>'administrator','client'=>'administrator','manager'=>'shop_manager','editor'=>'editor','author'=>'author','subscriber'=>'subscriber']as$name=>$role){$id=wp_insert_user(['user_login'=>$prefix.'_'.$name,'user_pass'=>wp_generate_password(40,true,true),'user_email'=>$prefix.'_'.$name.'@example.invalid','role'=>$role]);if(is_wp_error($id))throw new RuntimeException('Synthetic account creation failed');$users[$name]=(int)$id;}
 remove_filter('pre_option_'.Owner::OPTION,$ownerOff,PHP_INT_MAX);
 $seed=['db_version'=>get_option('db_version'),'initial_db_version'=>get_option('initial_db_version'),'woocommerce_db_version'=>get_option('woocommerce_db_version'),'siteurl'=>get_option('siteurl'),'home'=>get_option('home'),$wpdb->prefix.'user_roles'=>wp_roles()->roles,'active_plugins'=>get_option('active_plugins'),'template'=>get_option('template'),'stylesheet'=>get_option('stylesheet'),DSA_OPTION_SETTINGS=>(new DSA\Settings())->defaults(),'woocommerce_attribute_lookup_enabled'=>'yes','woocommerce_attribute_lookup_direct_updates'=>'yes'];
 $assert(false!==$wpdb->query("CREATE TABLE {$table} LIKE {$originalOptions}"),'native temporary options isolated');$isolated=true;$wpdb->options=$table;$GLOBALS['wp_object_cache']=new WP_Object_Cache();foreach($seed as$key=>$value)add_option($key,$value,'',false);
 $filter('pre_option_'.Owner::OPTION,static fn()=>['enabled'=>true,'ownerId'=>$users['owner'],'rankMath'=>'inactive']);wp_set_current_user($users['client']);
 $state=$request('GET','/attributes');$assert($state->get_status()===200&&$state->get_data()['canCreate'],'client reads native attributes through guarded route');
 $body=$payload('create',['name'=>$prefix.' Color','slug'=>$slug]);$created=$request('POST','/attributes',$body);$id=(int)($created->get_data()['id']??0);if($id)$attributes[]=$id;
 $proof['creation']=['status'=>$created->get_status(),'result'=>$created->get_data()];
 $assert($created->get_status()===200&&$id>0&&wc_get_attribute($id)->name===$body['name'],'client creates a native select attribute');
 $attribute=wc_get_attribute($id);$assert(!$attribute->has_archives&&$attribute->type==='select'&&$attribute->slug==='pa_'.$slug,'new archives disabled and address native');
 $assert($request('POST','/attributes',$body)->get_status()===409,'stale creation cannot duplicate');
 $assert($request('POST','/attributes',$payload('create',['name'=>$prefix.' Duplicate','slug'=>$slug]))->get_status()===409,'duplicate native address refused');
 $assert($request('POST','/attributes',$payload('create',['name'=>'Name','slug'=>'author']))->get_status()===400,'reserved native address refused');
 $assert($request('POST','/attributes',$payload('create',['name'=>'Name','slug'=>str_repeat('a',40)]))->get_status()===400,'oversized native address refused');
 $assert($request('POST','/attributes',$payload('create',['name'=>'Name','slug'=>$slug.'x']),false)->get_status()===403,'native nonce required');
 $assert($request('POST','/attributes',$payload('create',['name'=>'Name','slug'=>$slug.'x']),true,false)->get_status()===403,'mutation proof required');
 $origin=$_SERVER['HTTP_ORIGIN']??null;$_SERVER['HTTP_ORIGIN']='https://foreign.example.invalid';$denied=$request('POST','/attributes',$payload('create',['name'=>'Name','slug'=>$slug.'x']));if($origin===null)unset($_SERVER['HTTP_ORIGIN']);else $_SERVER['HTTP_ORIGIN']=$origin;$assert($denied->get_status()===403,'cross-origin save refused');
 // Woo registers new taxonomies on the next request. This fixture registers only its owned one.
 register_taxonomy($attribute->slug,['product'],['hierarchical'=>false,'public'=>false,'show_ui'=>false,'capabilities'=>['manage_terms'=>'manage_product_terms','edit_terms'=>'edit_product_terms','delete_terms'=>'delete_product_terms','assign_terms'=>'assign_product_terms']]);
 $second=$request('POST','/attributes',$payload('create',['name'=>$body['name'],'slug'=>$slug.'x']));$secondId=(int)($second->get_data()['id']??0);if($secondId)$attributes[]=$secondId;$assert($second->get_status()===200&&$secondId!==$id,'native global attributes may share a display label with distinct addresses');
 $secondAttribute=wc_get_attribute($secondId);register_taxonomy($secondAttribute->slug,['product'],['hierarchical'=>false,'public'=>false,'show_ui'=>false,'capabilities'=>['manage_terms'=>'manage_product_terms','edit_terms'=>'edit_product_terms','delete_terms'=>'delete_product_terms','assign_terms'=>'assign_product_terms']]);
 $new=$request('POST','/attributes/'.$id.'/values',$payload('create',['name'=>"Red's & bright"],$id));$red=(int)($new->get_data()['id']??0);if($red)$terms[]=$red;$assert($new->get_status()===200&&$red>0,'native value creation preserves punctuation');
 $new=$request('POST','/attributes/'.$id.'/values',$payload('create',['name'=>'Unused value'],$id));$unused=(int)($new->get_data()['id']??0);if($unused)$terms[]=$unused;$assert($new->get_status()===200&&$unused>0,'second native value created');
 $originalSlug=get_term($red,$attribute->slug)->slug;add_term_meta($red,'rank_math_title','Keep SEO');add_term_meta($red,'_kiwe_seo_description','Keep native SEO');$seo=get_term_meta($red);
 $choice=new WC_Product_Attribute();$choice->set_id($id);$choice->set_name($attribute->slug);$choice->set_options([$red]);$choice->set_visible(true);$choice->set_variation(true);
 $product=new WC_Product_Variable();$product->set_name($prefix.' Product');$product->set_status('draft');$product->set_attributes([$choice]);$productId=$product->save();$posts[]=$productId;
 $variation=new WC_Product_Variation();$variation->set_parent_id($productId);$variation->set_attributes([$attribute->slug=>$originalSlug]);$variation->set_regular_price('10');$variationId=$variation->save();$posts[]=$variationId;
 $beforeProduct=wp_json_encode(wc_get_product($productId)->get_data());$beforeVariation=wp_json_encode(wc_get_product($variationId)->get_data());
 // Woo owns display projections: summary regeneration refreshes post_excerpt; native reads also refresh post_title.
 $productState=static function()use($wpdb,$productId,$variationId){$ids=(int)$productId.','.(int)$variationId;return ['posts'=>$wpdb->get_results("SELECT * FROM {$wpdb->posts} WHERE ID IN ({$ids}) ORDER BY ID",ARRAY_A),'metadata'=>$wpdb->get_results("SELECT * FROM {$wpdb->postmeta} WHERE post_id IN ({$ids}) ORDER BY meta_id",ARRAY_A),'lookup'=>$wpdb->get_results("SELECT * FROM {$wpdb->prefix}wc_product_meta_lookup WHERE product_id IN ({$ids}) ORDER BY product_id",ARRAY_A),'attributes'=>$wpdb->get_results("SELECT * FROM {$wpdb->prefix}wc_product_attributes_lookup WHERE product_id IN ({$ids}) ORDER BY product_id, taxonomy, term_id",ARRAY_A)];};
 $beforeNativeProducts=$productState();
 $assert(!$find($id,$red)['canDelete']&&$find($id,$red)['assigned']>0&&$find($id,$red)['variationCount']>0,'draft products and native variations protect assigned values');
 $renamed=$request('POST','/attributes/'.$id,$payload('rename',['name'=>$prefix.' Finish']));$assert($renamed->get_status()===200&&wc_get_attribute($id)->slug===$attribute->slug,'definition rename preserves native address ID and archive policy');
 $renamed=$request('POST','/attributes/'.$id.'/values/'.$red,$payload('rename',['name'=>'Ruby & red'],$id));$assert($renamed->get_status()===200&&get_term($red,$attribute->slug)->slug===$originalSlug&&get_term_meta($red)===$seo,'value rename preserves native address and both SEO owners');
 $unusedSlug=get_term($unused,$attribute->slug)->slug;$alias=$request('POST','/attributes/'.$id.'/values/'.$unused,$payload('rename',['name'=>'Ruby & red'],$id));$assert($alias->get_status()===200&&get_term($unused,$attribute->slug)->slug===$unusedSlug,'native value aliases retain distinct addresses without merging records');
 $assert($request('POST','/attributes/'.$id.'/values/'.$unused,$payload('rename',['name'=>'Unused value'],$id))->get_status()===200,'native alias label can be changed independently');
 $derivedAfter=[wp_json_encode(wc_get_product($productId)->get_data()),wp_json_encode(wc_get_product($variationId)->get_data())];$proof['derivedRefreshFields']=[];foreach([$beforeProduct,$beforeVariation]as$index=>$beforeJson){$previous=json_decode($beforeJson,true);$current=json_decode($derivedAfter[$index],true);$proof['derivedRefreshFields'][$index]=array_keys(array_filter($current,static fn($value,$key)=>($previous[$key]??null)!==$value,ARRAY_FILTER_USE_BOTH));}
 $afterNativeProducts=$productState();$proof['nativeProductChanges']=[];
 foreach($afterNativeProducts as$group=>$rows)foreach($rows as$index=>$row)foreach($row as$key=>$value)if(($beforeNativeProducts[$group][$index][$key]??null)!==$value)$proof['nativeProductChanges'][]=['group'=>$group,'record'=>$row['ID']??$row['meta_id']??$row['product_id']??null,'field'=>$key];
 $expectedSummary=WC_Data_Store::load('product-variation')->get_attribute_summary(wc_get_product($variationId));
 $expectedTitle=get_post_field('post_title',$productId).' - '.wc_get_formatted_variation(wc_get_product($variationId),true,false);
 $expectedNativeProducts=$beforeNativeProducts;foreach($expectedNativeProducts['posts']as&$row)if((int)$row['ID']===$variationId){$row['post_excerpt']=$expectedSummary;$row['post_title']=$expectedTitle;}unset($row);
 $assert($afterNativeProducts===$expectedNativeProducts&&$wpdb->last_error==='','native variation title and summary follow renamed labels while all other product columns metadata choices and lookups remain exact');
 $proof['nativeProjection']=['title'=>$expectedTitle,'summary'=>$expectedSummary,'requestAttributeLabel'=>wc_attribute_label($attribute->slug)];
 $assert(str_contains($expectedSummary,'Ruby'),'native variation summary follows the new value; Woo owns request-local attribute labels');
 $noop=$request('POST','/attributes/'.$id,$payload('rename',['name'=>$prefix.' Finish']));$assert($noop->get_status()===200&&$noop->get_data()['changed']===false,'no-op rename avoids writer work');
 foreach(['editor','author','subscriber']as$role){wp_set_current_user($users[$role]);$assert($request('GET','/attributes')->get_status()===403,'non-store '.$role.' cannot read attribute workspace');}wp_set_current_user($users['manager']);$assert($request('GET','/attributes')->get_status()===200,'native shop manager can review attribute workspace');wp_set_current_user($users['client']);
 $cap=get_taxonomy($attribute->slug)->cap->delete_terms;get_taxonomy($attribute->slug)->cap->delete_terms='kiwe_qa_denied';$assert($request('POST','/attributes/'.$id.'/values/'.$unused,$payload('delete',['confirmName'=>'Unused value'],$id))->get_status()===403,'native value delete capability authoritative');get_taxonomy($attribute->slug)->cap->delete_terms=$cap;
 $stale=$payload('rename',['name'=>'A stale value'],$id);add_term_meta($unused,'kiwe_qa_metadata','protected');$assert($request('POST','/attributes/'.$id.'/values/'.$unused,$stale)->get_status()===409&&!$find($id,$unused)['canDelete'],'changed extension metadata invalidates snapshot and protects deletion');delete_term_meta($unused,'kiwe_qa_metadata');
 $wildcard=new WC_Product_Variation();$wildcard->set_parent_id($productId);$wildcard->set_attributes([$attribute->slug=>'']);$wildcardId=$wildcard->save();$posts[]=$wildcardId;$assert(!$find($id,$unused)['canDelete'],'any-value variation protects unused choices');wp_delete_post($wildcardId,true);$posts=array_values(array_diff($posts,[$wildcardId]));
 update_post_meta($productId,'_default_attributes',[$attribute->slug=>$originalSlug]);$assert(!$find($id,$unused)['canDelete'],'native default selection conservatively protects the attribute');delete_post_meta($productId,'_default_attributes');
 $beforeRollbackProducts=$productState();$mutator=static function($termId,$tt,$taxonomy)use($red,$attribute){if((int)$termId===$red&&$taxonomy===$attribute->slug)update_term_meta($red,'rank_math_title','Unexpected');};$filter('edited_term',$mutator,3);
 $rollback=$request('POST','/attributes/'.$id.'/values/'.$red,$payload('rename',['name'=>'Should roll back'],$id));remove_action('edited_term',$mutator,PHP_INT_MAX);$assert($rollback->get_status()===409&&$find($id,$red)['name']==='Ruby & red'&&get_term_meta($red)===$seo,'unexpected native hook metadata mutation rolls back with fresh cache');
 $assert($productState()===$beforeRollbackProducts,'failed native label edit also rolls back the Woo variation summary');
 $snapshots=new Snapshots();$phrase=bin2hex(random_bytes(24));$copy=$snapshots->create($phrase,'Synthetic attribute labels',[Labels::GROUP]);$copies[]=$copy['id'];$bytes=file_get_contents($snapshots->download_path($copy['id']));$assert(is_string($bytes)&&!str_contains($bytes,$prefix),'shared encrypted backup includes typed labels without plaintext leakage');
 $assert($request('POST','/attributes/'.$id,$payload('rename',['name'=>$prefix.' Changed']))->get_status()===200,'definition changed after encrypted capture');
 $assert($request('POST','/attributes/'.$secondId,$payload('rename',['name'=>$prefix.' Finish']))->get_status()===200&&$request('POST','/attributes/'.$id,$payload('rename',['name'=>$prefix.' Color']))->get_status()===200,'existing global labels can swap between stable native identities');
 $assert($request('POST','/attributes/'.$id.'/values/'.$red,$payload('rename',['name'=>'Changed choice'],$id))->get_status()===200,'value changed after encrypted capture');
 $new=$request('POST','/attributes/'.$id.'/values',$payload('create',['name'=>'Newer value'],$id));$newer=(int)($new->get_data()['id']??0);if($newer)$terms[]=$newer;$assert($new->get_status()===200,'newer native value created after capture');
 $review=$snapshots->preview_upload($bytes,$phrase);$reviews[]=$review['token'];$assert($review['changedCount']===1&&count($review['organization'][0]['changes'])===3,'common restore preview shows swapped definition labels and the value change');
 $restored=$snapshots->apply($review['token'],$phrase);$copies[]=$restored['rollbackId'];$assert($restored['restored']===1&&wc_get_attribute($id)->name===$prefix.' Finish'&&$find($id,$red)['name']==='Ruby & red'&&get_term($newer,$attribute->slug),'actual encrypted label recovery preserves newer values');
 $assert(wc_get_attribute($secondId)->name===$prefix.' Color'&&wc_get_attribute($secondId)->slug===$secondAttribute->slug,'encrypted recovery restores swapped native labels without changing addresses');
 $assert(get_term_meta($red)===$seo&&get_post_meta($variationId,'attribute_'.$attribute->slug,true)===$originalSlug,'recovery preserves current SEO and variation choice');
 $again=$snapshots->preview_upload($bytes,$phrase);$reviews[]=$again['token'];$assert($again['changedCount']===0,'restored shared snapshot is idempotent');$snapshots->retire_review($again['token']);
 $saved=Labels::read();$bad=$saved;foreach($bad['records']as&$row)if($row['id']===$id)$row['identity']=str_repeat('0',64);unset($row);$refused=false;try{Labels::validate_options([Labels::NAME=>['exists'=>true,'value'=>$bad]]);}catch(Throwable $e){$refused=true;}$assert($refused,'recovery rejects different native structural identity');
 $deleted=$request('POST','/attributes/'.$id.'/values/'.$unused,$payload('delete',['confirmName'=>'Unused value'],$id));$assert($deleted->get_status()===200&&!get_term($unused,$attribute->slug)&&get_term($red,$attribute->slug),'reviewed unused-value deletion preserves assigned choices');
 $missing=false;try{Labels::validate_options([Labels::NAME=>['exists'=>true,'value'=>$saved]]);}catch(Throwable $e){$missing=true;}$assert($missing,'label recovery refuses missing records instead of rebuilding or replaying choices');
 $assert($request('POST','/attributes/'.$id,$payload('delete',['confirmName'=>$prefix.' Finish']))->get_status()===409&&wc_get_attribute($id),'global definition deletion protected pending full Store recovery');
 $assert(count($native->catalog(['search'=>$prefix])['items'])===2&&$native->values($id,['sort'=>'id_desc'])['items'][0]['id']===$newer,'native search and newest sorting');
 $assert(!$http&&!$mail,'no provider requests or messages');$proof['passed']=true;
}catch(Throwable $e){$proof['passed']=false;$proof['error']=$e->getMessage();}
finally{
 try{
  if($isolated){wp_set_current_user($users['client']??0);$snapshots=new Snapshots();foreach($reviews as$token)$snapshots->retire_review($token);foreach(array_unique($copies)as$copy)$snapshots->remove($copy);}
  foreach(array_reverse($posts)as$post)wp_delete_post($post,true);
  foreach($attributes as$id){$attribute=wc_get_attribute($id);if($attribute){foreach($terms as$term)if(get_term($term,$attribute->slug))wp_delete_term($term,$attribute->slug);wc_delete_attribute($id);unregister_taxonomy($attribute->slug);}}
  // Remove process-local protection before deleting the synthetic owner account.
  foreach($filters as[$hook,$callback])if($hook==='pre_option_'.Owner::OPTION)remove_filter($hook,$callback,PHP_INT_MAX);
  $wpdb->options=$originalOptions;$GLOBALS['wp_object_cache']=$originalCache;wp_set_current_user($actor);require_once ABSPATH.'wp-admin/includes/user.php';foreach(array_reverse($users)as$user)wp_delete_user($user);
  if($isolated)$assert(false!==$wpdb->query("DROP TABLE {$table}"),'temporary native options retired');
  foreach($filters as[$hook,$callback])remove_filter($hook,$callback,PHP_INT_MAX);
  $assert(hash('sha256',serialize($wpdb->get_results("SELECT * FROM {$wpdb->prefix}woocommerce_attribute_taxonomies ORDER BY attribute_id",ARRAY_A)))===$originalCatalogHash,'original native attribute definitions unchanged');
  foreach($originalChoices as$key=>$value)$assert(get_option($key)===$value,'original authored choice preserved '.$key);
  foreach($users as$user)$assert(!get_userdata($user),'synthetic account retired');foreach($posts as$post)$assert(!get_post($post),'synthetic product retired');
  $proof['cleanup']=true;
 }catch(Throwable $e){$proof['passed']=false;$proof['cleanupError']=$e->getMessage();}
 $proof['httpAttempts']=count($http);$proof['mailAttempts']=count($mail);
}
echo wp_json_encode($proof,JSON_UNESCAPED_SLASHES).PHP_EOL;
