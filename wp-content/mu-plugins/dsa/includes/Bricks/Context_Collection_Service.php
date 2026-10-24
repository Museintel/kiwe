<?php
namespace DSA\Bricks;

use DSA\Onboarding\Context_Selection_Service as Selection;

if ( ! defined('ABSPATH') ) exit;

/** Bounded native public comment projection. Selection/approval stay in the existing Feedback owner. */
final class Context_Collection_Service {
	private static ?array $items = null;
	private static bool $purged = false;
	public static function invalidate(): void { self::$items = null; }
	private static function items(): array { return self::$items ??= array_column(Selection::testimonials(100),null,'id'); }
	public function register(): void {
		add_filter('bricks/setup/control_options',[$this,'query_options']);
		add_filter('bricks/query/run',[$this,'run_query'],20,2);
		add_filter('bricks/query/loop_object_id',[$this,'loop_object_id'],20,3);
		foreach(['added_comment_meta','updated_comment_meta','deleted_comment_meta','edit_comment','transition_comment_status','delete_comment','save_post','deleted_post']as$hook)add_action($hook,[self::class,'invalidate'],10,0);
		foreach(['added_comment_meta','updated_comment_meta','deleted_comment_meta']as$hook)add_action($hook,[$this,'comment_meta_changed'],20,4);
		foreach(['edit_comment','delete_comment']as$hook)add_action($hook,[$this,'comment_changed'],20,1);
		add_action('transition_comment_status',[$this,'comment_status_changed'],20,3);
		add_action('transition_post_status',[$this,'post_status_changed'],20,3);
		add_action('post_updated',[$this,'post_updated'],20,3);
		foreach(['div','block','container','section']as$element)add_filter('bricks/elements/'.$element.'/controls',[$this,'controls'],25);
	}
	private static function public_changed(): void {
		self::invalidate();if(self::$purged)return;self::$purged=true;
		// Cross-page curated content needs the native page-cache owner, on writes only.
		do_action('kiwe_public_context_changed','testimonials');do_action('litespeed_purge_all');
	}
	public function comment_meta_changed($meta_id,$comment_id,$key,$value=null): void {
		if($key===Selection::TESTIMONIAL_META||($key==='rating'&&Selection::selected('testimonial',(int)$comment_id)))self::public_changed();
	}
	public function comment_changed($id): void { if(Selection::selected('testimonial',(int)$id))self::public_changed(); }
	public function comment_status_changed($new,$old,$comment): void { if($new!==$old&&is_object($comment))$this->comment_changed((int)$comment->comment_ID); }
	private function parent_changed($post): void {
		if(!is_object($post)||empty($post->ID))return;
		$selected=get_comments(['post_id'=>(int)$post->ID,'status'=>'all','meta_key'=>Selection::TESTIMONIAL_META,'meta_value'=>'1','number'=>1,'fields'=>'ids','no_found_rows'=>true]);
		if($selected)self::public_changed();
	}
	public function post_status_changed($new,$old,$post): void { if($new!==$old&&in_array('publish',[$new,$old],true))$this->parent_changed($post); }
	public function post_updated($id,$after,$before): void { if(is_object($after)&&is_object($before)&&$after->post_password!==$before->post_password)$this->parent_changed($after); }
	public static function fields(): array {
		$out=[];foreach(['id'=>'number','name'=>'text','quote'=>'text','url'=>'link','rating'=>'number']as$key=>$kind)$out['testimonials.item.'.$key]=['tag'=>'{kiwe_testimonial_'.$key.'}','kind'=>$kind,'owner'=>'selected-approved-native-comment','scope'=>'current-public-testimonial'];
		$out['testimonials.available']=['tag'=>'{kiwe_testimonials_available}','kind'=>'boolean','owner'=>'selected-approved-native-comments','scope'=>'site'];
		$out['testimonials.count']=['tag'=>'{kiwe_testimonials_count}','kind'=>'number','owner'=>'selected-approved-native-comments','scope'=>'site','limit'=>100];
		return$out;
	}
	public function definitions(): array { $out=[];foreach(self::fields()as$key=>$field)$out[trim($field['tag'],'{}')]=__('Selected testimonial','dsa').' · '.substr($key,13);return$out; }
	public function query_options(array $options): array { $options['queryTypes']['kiwe_testimonials']=__('Kiwe selected testimonials','dsa');return$options; }
	public function controls(array $controls): array {
		$controls['kiweTestimonialLimit']=['tab'=>'content','type'=>'number','label'=>__('Testimonials to show','dsa'),'min'=>1,'max'=>100,'default'=>20,'required'=>['query.objectType','=','kiwe_testimonials']];
		return$controls;
	}
	public function run_query($result,$query) {
		if(!is_object($query)||($query->object_type??'')!=='kiwe_testimonials')return$result;
		$limit=max(1,min(100,(int)($query->settings['kiweTestimonialLimit']??20)));$out=[];
		foreach(array_slice(array_keys(self::items()),0,$limit)as$id){$comment=get_comment($id);if($comment&&Selection::selected('testimonial',(int)$id)&&Selection::public_testimonial($comment))$out[]=array_intersect_key(self::items()[$id],array_flip(['id','name','quote','url','rating','isExcerpt','source']))+['_kiweContextObject'=>'testimonial'];}
		return$out;
	}
	public function loop_object_id($id,$object,$query_id='') { return is_array($object)&&($object['_kiweContextObject']??'')==='testimonial'&&isset(self::items()[(int)($object['id']??0)])?(int)$object['id']:$id; }
	public function render(string $name,string $context='text') {
		if(!in_array($name,['kiwe_testimonials_available','kiwe_testimonials_count'],true)&&!preg_match('/^kiwe_testimonial_(id|name|quote|url|rating)$/D',$name,$match))return null;
		if(in_array($context,['image','media'],true))return[];
		if($name==='kiwe_testimonials_available')return$context==='link'?'':(self::items()?'1':'');
		if($name==='kiwe_testimonials_count')return$context==='link'?'':(string)count(self::items());
		if(!class_exists('\\Bricks\\Query')||!\Bricks\Query::is_looping())return'';
		$object=\Bricks\Query::get_loop_object();
		if(!is_array($object)||($object['_kiweContextObject']??'')!=='testimonial')return'';
		$id=absint($object['id']??0);$comment=$id?get_comment($id):null;
		if(!$comment||!Selection::selected('testimonial',$id)||!Selection::public_testimonial($comment))return'';
		$item=self::items()[$id]??[];$field=$match[1];
		if($context==='link'&&$field!=='url')return'';
		if($field==='url')return esc_url_raw((string)($item['url']??''));
		if(in_array($field,['id','rating'],true))return isset($item[$field])?(string)absint($item[$field]):'';
		return esc_html((string)($item[$field]??''));
	}
}
