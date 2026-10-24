<?php
define('ABSPATH',__DIR__.'/');
$options=['woocommerce_weight_unit'=>'kg'];$tests=[];$lookup=0;
function check_nc($ok,$name){global$tests;$tests[]=['passed'=>(bool)$ok,'label'=>$name];if(!$ok)throw new RuntimeException($name);}
function get_option($key,$default=false){return $GLOBALS['options'][$key]??$default;}
function __($s,$domain=''){return $s;}
function wc_get_weight($v,$to,$from){return $to==='g'?$v*1000:$v;}
function sanitize_key($s){return preg_replace('/[^a-z0-9_\-]/','',strtolower($s));}
function esc_url_raw($s,$protocols=null){return preg_match('~^https?://~i',$s)?$s:'';}
function esc_url($s){return htmlspecialchars(esc_url_raw($s),ENT_QUOTES);}
function wp_parse_url($s,$component=-1){return parse_url($s,$component);}
function home_url(){return 'https://example.test';}
function attachment_url_to_postid($s){return str_contains($s,'private')?2:1;}
function is_wp_error($v){return false;}
function taxonomy_exists($s){return in_array($s,['product_cat','private_tax']);}
function is_taxonomy_viewable($s){return $s==='product_cat';}
function get_term($id,$tax){$GLOBALS['lookup']++;return $id===99?false:(object)['term_id'=>$id,'taxonomy'=>$tax];}
function get_term_by($by,$v,$tax){return get_term($v==='private'?2:($v==='missing'?99:1),$tax);}
function get_term_meta($id,$key,$single){return $key==='thumbnail_id'?$id:($key==='custom_image'?['id'=>$id]:'javascript:alert(1)');}
function get_post($id){return new WP_Post($id);}
function wp_attachment_is_image($id){return $id<3;}
function wp_get_attachment_image_url($id,$size){return 'https://example.test/'.($id===2?'private':'public').'.jpg';}
function bricks_render_dynamic_data($tag,$term,$context){return $tag==='{private}'?'https://example.test/private.jpg':['id'=>$term];}
class WP_Post {public $post_type='attachment',$post_status='inherit',$post_password='',$post_parent=0;public function __construct(public $ID){if($ID===2)$this->post_status='private';}}
class WC_Product {public function __construct(private $weight){}public function get_weight(){return $this->weight;}}
class WC_Shipping_Rate {public function __construct(private $method='flat_rate',private $instance=3,private $cost=90,private $taxes=[],public $label='Flat rate'){}public function get_method_id(){return $this->method;}public function get_instance_id(){return $this->instance;}public function get_cost(){return $this->cost;}public function set_cost($v){$this->cost=$v;}public function get_taxes(){return $this->taxes;}public function set_taxes($v){$this->taxes=$v;}public function set_label($v){$this->label=$v;}}
require dirname(__DIR__,2).'/wp-content/mu-plugins/dsa/includes/Onboarding/Context_Selection_Service.php';
require dirname(__DIR__,2).'/wp-content/mu-plugins/dsa/includes/Commerce/Weight_Shipping_Service.php';
require dirname(__DIR__,2).'/wp-content/mu-plugins/dsa/includes/Bricks/Filter_Image_Service.php';
$service=new DSA\Commerce\Weight_Shipping_Service();
$options['woocommerce_flat_rate_3_settings']=['increment_weight'=>'0.75','custom'=>'keep'];
foreach([[0.25,1,90],[0.75,1,90],[0.76,1,180],[0.5,3,180],[0,2,180]]as[$weight,$qty,$want]){
 $rate=new WC_Shipping_Rate();$out=$service->rates(['flat'=>$rate],['contents'=>[['data'=>new WC_Product($weight),'quantity'=>$qty]]]);check_nc($out['flat']->get_cost()===$want*1.0,'Weight increments preserve native configured charge '.$weight.' x '.$qty);
}
$rate=new WC_Shipping_Rate('flat_rate',3,150,[7=>27]);$service->rates([$rate],['contents'=>[['data'=>new WC_Product(1),'quantity'=>1]]]);check_nc($rate->get_cost()===300.0&&$rate->get_taxes()===[7=>54.0],'Scaled costs retain matching native tax IDs and values');
foreach(['',0,-1,'bad','INF']as$increment){$options['woocommerce_flat_rate_3_settings']['increment_weight']=$increment;$rate=new WC_Shipping_Rate();$service->rates([$rate],['contents'=>[['data'=>new WC_Product(1),'quantity'=>1]]]);check_nc($rate->get_cost()===90,'Unset or invalid increments do not adopt the rule');}
$options['woocommerce_flat_rate_3_settings']['increment_weight']='0.75';
foreach(['free_shipping','local_pickup','custom']as$method){$rate=new WC_Shipping_Rate($method);$service->rates([$rate],['contents'=>[['data'=>new WC_Product(1),'quantity'=>2]]]);check_nc($rate->get_cost()===90,'Other shipping method unchanged: '.$method);}
$rate=new WC_Shipping_Rate('flat_rate',4);$service->rates([$rate],['contents'=>[['data'=>new WC_Product(1),'quantity'=>2]]]);check_nc($rate->get_cost()===90,'Unconfigured native flat-rate instance unchanged');
$options['woocommerce_weight_unit']='g';$options['woocommerce_flat_rate_3_settings']['increment_weight']='750';$rate=new WC_Shipping_Rate();$service->rates([$rate],['contents'=>[['data'=>new WC_Product(''),'quantity'=>2]]]);check_nc($rate->get_cost()===180.0&&str_contains($rate->label,'1000.00 g'),'Missing weights convert from kg to native grams');
$options['woocommerce_weight_unit']='kg';$options['woocommerce_flat_rate_3_settings']['increment_weight']='0.1';$rate=new WC_Shipping_Rate();$service->rates([$rate],['contents'=>[['data'=>new WC_Product(0.1),'quantity'=>3]]]);check_nc($rate->get_cost()===270.0,'Decimal boundary does not create spurious extra increment');
$before=$options;check_nc($service->rates([],[])===[]&&$options===$before,'No rates cause no writes');check_nc($service->fields(['cost'=>['type'=>'text']])['increment_weight']['default']==='','New native field defaults to ordinary flat-rate delivery');
$images=new DSA\Bricks\Filter_Image_Service();$element=(object)['name'=>'filter-radio','settings'=>['catImgEnable'=>true,'filterTaxonomy'=>'product_cat','catImgSourceType'=>'dynamic_tag']];
$label=static fn($value)=>'<label class="option"><input type="radio" name="category" value="'.$value.'"><span class="brx-input-indicator"></span><span class="brx-option-text">Chikki<span class="brx-option-count">(4)</span></span></label>';
$html='<ul class="brxe-filter-radio">'.$label('all').$label('private').$label('missing').'</ul>';
$actual=$images->render($html,$element);check_nc(substr_count($actual,'<img')===1&&str_contains($actual,'data-catimg-enable="1"'),'Only public category image is rendered');check_nc(str_contains($actual,'alt=""')&&str_contains($actual,'Chikki<span class="brx-option-count">(4)</span>'),'Decorative image preserves accessible native label and count');
check_nc($images->render($actual,$element)===$actual,'Repeated native filter render is idempotent');
$element->settings['catImgEnable']=false;check_nc($images->render($html,$element)===$html,'Disabled image feature preserves exact native HTML');$element->settings['catImgEnable']=true;
$element->settings['filterTaxonomy']='private_tax';check_nc($images->render($html,$element)===$html,'Private taxonomy images are not exposed');$element->settings['filterTaxonomy']='product_cat';
$element->settings['catImgSourceType']='meta_key';$element->settings['catImgMetaKey']='custom_image';check_nc(substr_count($images->render($html,$element),'<img')===1,'Native custom image IDs retain public visibility');
$element->settings['catImgSourceType']='dynamic_tag';$element->settings['catImgDynamicTag']='{private}';$onlyPrivate='<ul>'.$label('private').'</ul>';check_nc(!str_contains($images->render($onlyPrivate,$element),'<img'),'Private dynamic local image URL is denied');
$element->settings['catImgDynamicTag']='{term_image}';check_nc(substr_count($images->render($html,$element),'<img')===1,'Bricks image tag output resolves public attachment IDs');
$controls=$images->controls([],$element);foreach(['catImgEnable','catImgPosition','catImgSize','catImgSize:mobile_portrait','catImgGap','catImgBorder']as$key){if(str_contains($key,':'))continue;check_nc(isset($controls[$key]),'Existing authored control remains available: '.$key);}
$other=(object)['name'=>'filter-select','settings'=>$element->settings];check_nc($images->controls(['native'=>1],$other)===['native'=>1]&&$images->render($html,$other)===$html,'Other native filter types are unaffected');
check_nc($images->render(str_repeat('x',262145),$element)===str_repeat('x',262145),'Unexpected large markup is left untouched');
echo json_encode(['passed'=>true,'cleanup'=>true,'checks'=>$tests],JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
