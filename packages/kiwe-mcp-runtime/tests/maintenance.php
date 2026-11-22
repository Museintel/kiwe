<?php
namespace DSA\Apps {
 final class Runtime_Registry { public static function installed($id){return $id==='mcp';}public static function owner($id){return self::class;}public static function enabled(){return true;} }
}
namespace DSA\MCP {
 final class Credentials {public static function actor_allowed($id){return $id===7;}}
 final class Guidance_Service {public static function text($v,$n){return substr(strip_tags($v),0,$n);}}
}
namespace DSA\Access {
 final class Task_Workspace_UI {public static function text($v,$n){return substr(strip_tags($v),0,$n);}}
 final class Template_Workspace_Service {public static function allowed(){return $GLOBALS['templates'];}public static function available(){return $GLOBALS['installed'];}}
 final class Page_Publishing_Service {public static function critical_reason($id){return $GLOBALS['critical']?'Critical native page':'';}}
 final class Trash_Workspace_Service {
  public static function fingerprint($p,$type){return hash('sha256',serialize([$p,$GLOBALS['meta']]));}
  public function mutate($a){$GLOBALS['writes'][]=$a;$GLOBALS['post']->post_status=$a['operation']==='trash'?'trash':'draft';return ['id'=>$a['item']['id'],'status'=>$GLOBALS['post']->post_status];}
 }
}
namespace DSA\Diagnostics {
 final class Cache_Maintenance_Service {public function report(){return ['pageCache'=>$GLOBALS['pageCache']];}public function purge($scope,$layers){$GLOBALS['cacheWrites'][]=[$scope,$layers];return $GLOBALS['cacheResult'];}}
}
namespace {
 define('ABSPATH',__DIR__);define('ARRAY_A','ARRAY_A');define('DSA_VERSION','8.0.0-rc.487');
 class WP_Post {public $ID=78,$post_type='page',$post_status='draft',$post_title='Exact sample',$post_content='',$post_excerpt='',$post_parent=0;}
 class WP_Query {public array $posts;function __construct($a){$GLOBALS['auditListQuery']=$a;$all=[];for($i=78;$i>=69;$i--){$p=new WP_Post();$p->ID=$i;$all[]=$p;}$offset=$a['offset']??((($a['paged']??1)-1)*$a['posts_per_page']);$this->posts=array_slice($all,$offset,$a['posts_per_page']);}}
 class Audit_DB {
  public $posts='wp_posts',$postmeta='wp_postmeta',$options='wp_options',$prefix='wp_',$last_error='';public array $queries=[];
  function esc_like($s){return addcslashes($s,'_%\\');}function prepare($sql,...$args){$i=0;return preg_replace_callback('/%[sd]/',static function($m)use(&$i,$args){$value=$args[$i++];return $m[0]==='%d'?(string)(int)$value:"'".str_replace("'","''",(string)$value)."'";},$sql);}
  function get_results($sql,$kind){$this->queries[]=$sql;$this->last_error=$GLOBALS['dbFailure']?'Failed':'';return str_contains($sql,'FROM wp_postmeta')?$GLOBALS['references']['metadata']:(str_contains($sql,'FROM wp_options')?$GLOBALS['references']['settings']:$GLOBALS['references']['content']);}
  function get_var($sql){return !empty($GLOBALS['foreignLock'])&&str_contains($sql,'GET_LOCK')&&str_contains($sql,$GLOBALS['foreignLock'])?'0':'1';}
 }
 $post=new WP_Post();$wpdb=new Audit_DB();$actor=7;$caps=['kiwe_manage_context','manage_options','read_post','edit_post','delete_post'];$templates=true;$installed=true;$critical=false;$comments=0;$meta=[];$options=[];$writes=[];$cacheWrites=[];$dbFailure=false;
 $references=['content'=>[],'metadata'=>[],'settings'=>[]];$pageCache=['adapters'=>[['id'=>'native','label'=>'Native','scopes'=>['all']]],'scopes'=>['all']];$cacheResult=['completed'=>['page_cache:native'=>true],'failed'=>[],'skipped'=>[]];
 function get_current_user_id(){return $GLOBALS['actor'];}function current_user_can($c,...$args){return in_array($c,$GLOBALS['caps'],true);}function get_post($id){if($id===78)return $GLOBALS['post'];if($id>=69&&$id<78){$p=new WP_Post();$p->ID=$id;return $p;}return null;}function get_permalink($id){return 'https://example.invalid/exact-sample/';}
 function get_post_meta($id,$key=null,$single=false){$data=$id===78?$GLOBALS['meta']:($GLOBALS['foreignMeta'][$id]??[]);return $key===null?$data:($data[$key]??'');}function get_option($key,$default=false){return $GLOBALS['options'][$key]??$default;}function get_comments($a){return $GLOBALS['comments'];}function admin_url($p){return 'https://example.invalid/wp-admin/'.$p;}
 function wp_json_encode($v,...$args){return json_encode($v,JSON_THROW_ON_ERROR);}function is_wp_error($v){return false;}
 function add_filter($hook,$fn,$priority=10,$args=1){$GLOBALS['temporaryFilters'][$hook]=$fn;}function remove_filter($hook,$fn,$priority=10){unset($GLOBALS['temporaryFilters'][$hook]);}
 $root=dirname(__DIR__,3).'/wp-content/mu-plugins/dsa/includes/';foreach(['Apps/Runtime_Contract','Apps/MCP_Provider_Contract','MCP/Schema','MCP/Operation_Registry','MCP/Authoring_Service','MCP/Audit_Service','MCP/Maintenance_Provider']as$c)require $root.$c.'.php';
 use DSA\MCP\{Audit_Service as Audit,Maintenance_Provider as Provider,Operation_Registry as Registry,Schema};
 $checks=0;$check=static function($ok,$why)use(&$checks){if(!$ok)throw new RuntimeException($why);$checks++;};$deny=static function($call,$why)use($check){$bad=false;try{$call();}catch(Throwable){$bad=true;}$check($bad,$why);};
 $a=new Audit();$r=$a->inspect('page',78);$check($r['cleanupEligible']&&$r['complete']&&strlen($r['expected'])===64,'An exact unreferenced native draft has a bounded current proof');
 $check(count($wpdb->queries)===3&&count($writes)===0,'Audit reads only the three declared native sources and performs no write');
 $first=json_decode($a->read(['area'=>'records','type'=>'page','page'=>1])['resultJson'],true)['data'];$second=json_decode($a->read(['area'=>'records','type'=>'page','page'=>2])['resultJson'],true)['data'];
 $check(array_column(array_merge($first['items'],$second['items']),'id')===range(78,69)&&$first['hasMore']&&!$second['hasMore'],'Audit pagination preserves the ninth record without duplicates or gaps');
 $args=['type'=>'page','id'=>78,'action'=>'trash','expected'=>$r['expected']];$plan=$a->plan($args);$check($plan['needsApproval']&&str_contains($plan['after'],'nothing is permanently deleted'),'Cleanup requires an exact recoverable native review');
 $evidence=serialize(['query'=>['post__in'=>[78]]]);$references['metadata']=[['id'=>'1','recordId'=>'999','sourceKey'=>'_bricks_page_content_2','evidence'=>$evidence,'evidenceBytes'=>strlen($evidence),'proof'=>str_repeat('a',64)]];$check(!$a->inspect('page',78)['cleanupEligible'],'A typed native metadata reference protects its dependency');
 $deny(fn()=>$a->plan($args),'A new reference invalidates an earlier selected proof');$references['metadata']=[];
 $evidence=serialize(['columns'=>78,'fontSize'=>178,'enabled'=>true]);$references['settings']=[['id'=>'2','sourceKey'=>'bricks_global_settings','evidence'=>$evidence,'evidenceBytes'=>strlen($evidence),'proof'=>str_repeat('c',64)]];$check($a->inspect('page',78)['cleanupEligible'],'Unrelated numeric presentation settings are not content references');
 $evidence=serialize(['pageId'=>78,'secret'=>'SYNTHETIC-PRIVATE']);$references['settings'][0]['evidence']=$evidence;$references['settings'][0]['evidenceBytes']=strlen($evidence);$v=$a->inspect('page',78);$check(!$v['cleanupEligible']&&!str_contains(json_encode($v),'SYNTHETIC-PRIVATE'),'Typed settings references protect content without exporting the evidence body');$references['settings']=[];
 $evidence=serialize(['templateConditions'=>[['main'=>'ids','ids'=>[78]]]]);$references['metadata']=[['id'=>'5','recordId'=>'999','sourceKey'=>'_bricks_template_settings','evidence'=>$evidence,'evidenceBytes'=>strlen($evidence),'proof'=>str_repeat('f',64)]];$check(!$a->inspect('page',78)['cleanupEligible'],'Native Bricks individual-post conditions protect their target');$references['metadata']=[];
 $foreignMeta[999]=['_menu_item_type'=>'taxonomy','_menu_item_object'=>'category'];$references['metadata']=[['id'=>'6','recordId'=>'999','sourceKey'=>'_menu_item_object_id','evidence'=>'78','evidenceBytes'=>2,'proof'=>str_repeat('a',64)]];$check($a->inspect('page',78)['cleanupEligible'],'A coincident taxonomy term ID is not a native page-menu reference');$foreignMeta[999]=['_menu_item_type'=>'post_type','_menu_item_object'=>'page'];$check(!$a->inspect('page',78)['cleanupEligible'],'An actual native page-menu object is protected');$references['metadata']=[];
 $evidence='O:8:"stdClass":0:{}';$references['metadata']=[['id'=>'3','recordId'=>'999','sourceKey'=>'_bricks_page_content_2','evidence'=>$evidence,'evidenceBytes'=>strlen($evidence),'proof'=>str_repeat('d',64)]];$check(!$a->inspect('page',78)['complete'],'Serialized objects are not instantiated or accepted as absence proof');$references['metadata']=[];
 $references['metadata']=[['id'=>'4','recordId'=>'999','sourceKey'=>'_bricks_page_content_2','evidence'=>'short','evidenceBytes'=>Audit::EVIDENCE_BYTES+1,'proof'=>str_repeat('e',64)]];$check(!$a->inspect('page',78)['complete'],'Oversized evidence is explicitly incomplete');$references['metadata']=[];
 $critical=true;$check(!$a->inspect('page',78)['cleanupEligible'],'Critical native pages cannot be cleaned');$critical=false;
 $post->post_status='private';$check(!$a->inspect('page',78)['cleanupEligible'],'Private content is not inferred to be obsolete');$post->post_status='future';$check(!$a->inspect('page',78)['cleanupEligible'],'Scheduled content keeps its native publishing workflow');$post->post_status='draft';
 $options['woocommerce_checkout_page_id']=78;$check(!$a->inspect('page',78)['cleanupEligible'],'Stored Woo routes stay protected even without an active Woo engine');$options=[];
 $comments=1;$check(!$a->inspect('page',78)['cleanupEligible'],'Visitor feedback history is protected');$comments=0;
 $meta['_edit_lock']=time().':8';$check(!$a->inspect('page',78)['cleanupEligible'],'A different active editor protects the record');$meta=[];
 $references['content']=array_fill(0,26,['id'=>'999','proof'=>str_repeat('b',64)]);$r=$a->inspect('page',78);$check(!$r['complete']&&!$r['cleanupEligible'],'A truncated match budget is never unused proof');$references['content']=[];
 $dbFailure=true;$deny(fn()=>$a->inspect('page',78),'SQL failure refuses proof rather than pretending no references');$dbFailure=false;
 $references['settings']=[['id'=>'2','proof'=>'not-a-digest']];$deny(fn()=>$a->inspect('page',78),'A missing native fingerprint refuses the audit');$references['settings']=[];
 $caps=array_values(array_diff($caps,['delete_post']));$deny(fn()=>$a->inspect('page',78),'Native object deletion permission remains required');$caps[]='delete_post';
 $actor=8;$deny(fn()=>$a->inspect('page',78),'An unadmitted account cannot inspect private cleanup evidence');$actor=7;
 $post->post_type='bricks_template';$post->post_status='publish';$check(!$a->inspect('bricks_template',78)['cleanupEligible'],'Published native fallback templates remain protected');$post->post_status='draft';$meta['_bricks_template_settings']=['conditions'=>[['main'=>'entireWebsite']]];$check(!$a->inspect('bricks_template',78)['cleanupEligible'],'Bricks conditions protect a draft independently of its title');$meta=[];$installed=false;$deny(fn()=>$a->inspect('bricks_template',78),'A missing Bricks installation cannot expose template cleanup');$installed=true;$post->post_type='page';
 $r=$a->inspect('page',78);$args['expected']=$r['expected'];$foreignLock='kiwe-mcp-author-'.substr(hash('sha256','wp_|content:page:78'),0,36);$deny(fn()=>$a->apply($args,$r['expected']),'Cleanup must share the existing content-authoring mutex');$foreignLock='';$result=$a->apply($args,$r['expected']);$check(json_decode($result['resultJson'],true)['status']==='trash'&&count($writes)===1&&$writes[0]['operation']==='trash','The exact writer is native recoverable Trash, not direct deletion');
 $r=$a->inspect('page',78);$args['action']='restore';$args['expected']=$r['expected'];$result=$a->apply($args,$r['expected']);$check($post->post_status==='draft'&&count($writes)===2,'Native restore does not publish or activate a route');$check(empty($GLOBALS['temporaryFilters']),'The exact restore-status filter is removed after the native writer');
 $registry=new Registry();$ops=Provider::operations();$registry->register('mcp',1,static fn()=>['schema'=>DSA\Apps\MCP_Provider_Contract::SCHEMA,'api'=>1,'app'=>'mcp','setup'=>['state'=>'ready'],'operations'=>$ops]);
 $scopes=[Provider::AUDIT_SCOPE,Provider::CLEANUP_SCOPE,Provider::CACHE_SCOPE];$check(count($registry->describe($scopes))===3,'All three operation/schema contracts are admitted by the real registry');
 $check($registry->describe($scopes,[])===[],'Older frozen grants cannot acquire audit or cleanup operations');
 $check(!Schema::value($args+['approve'=>true],$ops['mcp.cleanup.change']['inputSchema']),'A chat approval flag is outside the typed cleanup schema');
 $bad=$args;$bad['type']='product';$check(!Schema::value($bad,$ops['mcp.cleanup.change']['inputSchema']),'Product/order/account/media deletion is excluded from this content lane');
 $check(!Schema::value(['layer'=>'object_cache','expected'=>str_repeat('a',64)],$ops['mcp.maintenance.cache']['inputSchema']),'Private-state and object-cache cleanup is not exposed');
 $c=Provider::capabilities();$cache=['layer'=>'page_cache','expected'=>$c['expected']];$cp=($ops['mcp.maintenance.cache']['prepare'])($cache);$check($cp['needsApproval'],'Cache maintenance has its own exact native approval');
 $cr=($ops['mcp.maintenance.cache']['execute'])($cache,$cp['nativeExpected']);$check(json_decode($cr['resultJson'],true)['confirmed']&&$cacheWrites===[['all',['page_cache']]],'Only the native page-cache adapter is called');
 $cacheResult=['completed'=>['page_cache:native'=>false],'failed'=>[],'skipped'=>[]];$deny(fn()=>($ops['mcp.maintenance.cache']['execute'])($cache,$cp['nativeExpected']),'A false native completion is not reported as success');
 $cacheResult=['completed'=>[],'failed'=>['page_cache:native'=>'PRIVATE-ADAPTER-SECRET'],'skipped'=>[]];try{($ops['mcp.maintenance.cache']['execute'])($cache,$cp['nativeExpected']);throw new RuntimeException('No failure');}catch(Throwable $e){$check(!str_contains($e->getMessage(),'PRIVATE-ADAPTER-SECRET'),'Native adapter error bodies never escape through MCP');}
 $pageCache['adapters']=[];$check(!Provider::cache_available(),'A missing cache adapter is not offered as a working operation');$deny(fn()=>($ops['mcp.maintenance.cache']['prepare'])($cache),'Missing native cache cannot be bypassed through direct prepare');
 $caps=array_values(array_diff($caps,['manage_options']));$deny(fn()=>Provider::capabilities(),'Technical cache permission is separate from content audit authority');
 echo wp_json_encode(['passed'=>true,'checks'=>$checks,'nativeSql'=>false,'actualClient'=>false]).PHP_EOL;
}
