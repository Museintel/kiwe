<?php
if(PHP_SAPI!=='cli'||!defined('KIWE_WORKSPACE_TEST_SITE')||!KIWE_WORKSPACE_TEST_SITE||home_url()!=='https://plum-giraffe-539524.hostingersite.com')throw new RuntimeException('Named lab required');
$out=['schema'=>'kiwe.national-native-compatibility.v1','passed'=>false,'cleanup'=>false,'checks'=>[]];
$check=static function($ok,$label)use(&$out){$out['checks'][]=['passed'=>(bool)$ok,'label'=>$label];if(!$ok)throw new RuntimeException($label);};
$opts=[];$originalWeight=get_option('woocommerce_weight_unit','kg');$fixtureTerm=0;$fixtureSlug='';
try{
 $check(DSA_VERSION===KIWE_WORKSPACE_EXPECTED_VERSION&&class_exists('WC_Product_Simple'),'Exact lab candidate and native WooCommerce');
 $increment=(string)wc_get_weight(0.75,$originalWeight,'kg');
 foreach([9000831,9000832]as$id){$key='woocommerce_flat_rate_'.$id.'_settings';$before=get_option($key,null);$check($before===null,'Synthetic native rate key absent');$opts[$key]=$before;update_option($key,['increment_weight'=>$increment,'cost'=>$id===9000831?'90':'150'],false);}
 $product=new WC_Product_Simple();$product->set_weight((string)wc_get_weight(1,$originalWeight,'kg'));$package=['contents'=>[['data'=>$product,'quantity'=>1]]];
 $rates=[new WC_Shipping_Rate('flat_rate:9000831','Flat',90,[7=>16.2],'flat_rate',9000831),new WC_Shipping_Rate('flat_rate:9000832','Flat',150,[],'flat_rate',9000832),new WC_Shipping_Rate('free_shipping:9','Free',0,[],'free_shipping',9)];
 $result=apply_filters('woocommerce_package_rates',$rates,$package);
 $check((float)$result[0]->get_cost()===180.0&&(float)$result[1]->get_cost()===300.0,'Real native Woo rate objects use both preserved weight charges');
 $check(abs((float)$result[0]->get_taxes()[7]-32.4)<0.000001&&(float)$result[2]->get_cost()===0.0,'Native tax IDs and free delivery stay correct');
 $method=new WC_Shipping_Flat_Rate(9000831);$check(isset($method->get_instance_form_fields()['increment_weight']),'Real native flat-rate form contains the same weight control');
 $product->set_weight($increment);$one=new WC_Shipping_Rate('flat_rate:9000831','Flat',90,[],'flat_rate',9000831);$check((float)apply_filters('woocommerce_package_rates',[$one],['contents'=>[['data'=>$product,'quantity'=>1]]])[0]->get_cost()===90.0,'Exact 0.75 kg boundary stays one increment');
 $attachment=0;foreach(get_posts(['post_type'=>'attachment','post_status'=>'inherit','posts_per_page'=>100,'orderby'=>'ID','order'=>'DESC'])as$image){if(DSA\Onboarding\Context_Selection_Service::public_media($image)&&wp_attachment_is_image($image->ID)){$attachment=(int)$image->ID;break;}}
 $check($attachment>0,'Existing public lab image supplies native image fixture');
 $fixtureSlug='kiwe-nc83-'.bin2hex(random_bytes(6));$created=wp_insert_term('Kiwe native filter fixture','product_cat',['slug'=>$fixtureSlug]);$check(!is_wp_error($created)&&!empty($created['term_id']),'Isolated native filter category created');$fixtureTerm=(int)$created['term_id'];update_term_meta($fixtureTerm,'thumbnail_id',$attachment);$term=get_term($fixtureTerm,'product_cat');
 $check((int)get_term_meta($fixtureTerm,'thumbnail_id',true)===$attachment,'Actual native category thumbnail saved');
 $controls=[];$nativeHtml='';$css='';$savedCss=Bricks\Assets::$inline_css??null;
 foreach(['filter-radio','filter-checkbox']as$i=>$name){
  if(!class_exists('Bricks\\Filter_Element'))require_once get_template_directory().'/includes/elements/filter-base.php';
  $class=$name==='filter-radio'?'Bricks\\Filter_Radio':'Bricks\\Filter_Checkbox';if(!class_exists($class))require_once get_template_directory().'/includes/elements/'.$name.'.php';
  $id='nc83f'.($i+1);$data=['id'=>$id,'name'=>$name,'settings'=>['catImgEnable'=>true,'catImgPosition'=>'column','catImgSize'=>'80','catImgSize:mobile_portrait'=>'40','catImgGap'=>'8','filterTaxonomy'=>'product_cat','filterSource'=>'taxonomy','filterHideCount'=>true,'displayMode'=>'button']];
  $element=new $class($data);$element->load();$element->input_name='nc83category'.$i;$element->populated_options=[['value'=>$term->slug,'text'=>$term->name,'class'=>'bricks-button','count'=>1,'is_all'=>false,'is_placeholder'=>false]];
  $check(isset($element->controls['catImgEnable'],$element->controls['catImgSize']),'Native '.$name.' exposes retained image and responsive style controls');
  $raw='<ul id="brxe-'.$id.'" class="brxe-'.$name.'">'.$element->get_options_html().'</ul>';
  $html=apply_filters('bricks/frontend/render_element',$raw,$element);$check(substr_count($html,'class="brx-term-thumb"')===1&&str_contains($html,'value="'.esc_attr($term->slug).'"'),'Native '.$name.' rendering retains image and unchanged option identity');
  $check(apply_filters('bricks/frontend/render_element',$html,$element)===$html,'Repeated native '.$name.' rendering is stable');
  $nativeHtml.='<section><h2>'.esc_html($name).'</h2>'.$html.'</section>';
  Bricks\Assets::generate_inline_css_from_element($data,$element->controls,'page');
 }
 // Capture actual generated native CSS, rather than recreating the editor's styles in the fixture.
 $css=json_encode(Bricks\Assets::$inline_css??[]);
 $check(str_contains($css,'brx-term-thumb')&&str_contains($css,'80')&&str_contains($css,'40'),'Native Bricks CSS generation includes authored desktop and phone image sizes');
 $out['nativeCss']=$css;$out['nativeHtml']=$nativeHtml;
 $out['passed']=true;
}catch(Throwable$e){$out['error']=$e->getMessage();}finally{
 foreach($opts as$key=>$value){if($value===null)delete_option($key);else update_option($key,$value,false);}
 $clean=get_option('woocommerce_weight_unit')===$originalWeight;foreach($opts as$key=>$value)$clean=$clean&&get_option($key,null)===$value;
 if($fixtureTerm){$current=get_term($fixtureTerm,'product_cat');if($current&&!is_wp_error($current)&&$current->slug===$fixtureSlug)wp_delete_term($fixtureTerm,'product_cat');$clean=$clean&&!term_exists($fixtureTerm,'product_cat');}
 $out['cleanup']=$clean;if(!$clean)$out['passed']=false;
}
echo json_encode($out,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
