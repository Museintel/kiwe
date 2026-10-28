<?php
namespace DSA\Bricks;

use DSA\Onboarding\Context_Selection_Service as Selection;

if ( ! defined('ABSPATH') ) exit;

/** Bounded native public comment projection. Selection/approval stay in the existing Feedback owner. */
final class Context_Collection_Service {
	private static ?array $items = null;
	private static bool $purged = false;
	public static function invalidate(): void { self::$items = null; }
	private static function items(): array { do_action('litespeed_tag_add','kiwe_testimonials'); return self::$items ??= array_column(Selection::testimonials(100),null,'id'); }
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
		add_action('kiwe_discussion_reaction_changed',[$this,'reaction_changed'],30,2);
		foreach(['added_option','updated_option','deleted_option']as$hook)add_action($hook,[$this,'option_changed'],20,3);
		add_action('set_user_role',[self::class,'public_changed'],30,0);
		add_action('profile_update',[self::class,'public_changed'],30,0);
		foreach(['added_user_meta','updated_user_meta','deleted_user_meta']as$hook)add_action($hook,[$this,'user_meta_changed'],30,4);
		add_action('deleted_user',[self::class,'public_changed'],30,0);
		add_action('kiwe_discussion_privacy_changed',[self::class,'public_changed'],30,0);
		foreach(['div','block','container','section']as$element)add_filter('bricks/elements/'.$element.'/controls',[$this,'controls'],25);
	}
	public static function public_changed(): void {
		self::invalidate();if(self::$purged)return;self::$purged=true;
		// Only pages that rendered this collection expire; a like cannot flush the whole site.
		do_action('kiwe_public_context_changed','testimonials');do_action('litespeed_purge','kiwe_testimonials');
	}
	public function option_changed($name,$old=null,$new=null): void {
		if(in_array($name,[\DSA\Onboarding\Design_Context_Profile_Service::OPTION_PROFILE,\DSA\Apps\Store_App_Service::OPTION,'woocommerce_enable_reviews','woocommerce_enable_review_rating'],true))self::public_changed();
	}
	public function user_meta_changed($meta_id,$user_id,$key,$value=null): void {
		global $wpdb;
		if($key===$wpdb->prefix.'capabilities')self::public_changed();
	}
	public function reaction_changed($id,$kind): void {
		if(in_array($kind,['heart','pin'],true)||($kind==='like'&&(Selection::testimonial_sources()['popular']||Selection::testimonial_candidate((int)$id)))) {
			// Metadata hooks can precede commit. Refresh derived context again after the native transaction.
			if(self::$purged){self::invalidate();do_action('kiwe_public_context_changed','testimonials');}else self::public_changed();
		}
	}
	public function comment_meta_changed($meta_id,$comment_id,$key,$value=null): void {
		if(in_array($key,[Selection::TESTIMONIAL_META,\DSA\Discussion\Discussion_Reactions::HEART,\DSA\Discussion\Discussion_Reactions::PIN,'rating'],true))self::public_changed();
	}
	public function comment_changed($id): void { self::public_changed(); }
	public function comment_status_changed($new,$old,$comment): void { if($new!==$old&&is_object($comment))$this->comment_changed((int)$comment->comment_ID); }
	private function parent_changed($post): void {
		if(!is_object($post)||empty($post->ID))return;
		self::public_changed();
	}
	public function post_status_changed($new,$old,$post): void { if($new!==$old&&in_array('publish',[$new,$old],true))$this->parent_changed($post); }
	public function post_updated($id,$after,$before): void { if(is_object($after)&&is_object($before)&&($after->post_password!==$before->post_password||$after->post_title!==$before->post_title||$after->post_name!==$before->post_name))$this->parent_changed($after); }
	public static function fields(): array {
		$out=[];foreach(['id'=>'number','name'=>'text','quote'=>'text','url'=>'link','rating'=>'number','target_id'=>'number','source_type'=>'text','source_title'=>'text','likes'=>'number','pinned'=>'boolean','hearted'=>'boolean']as$key=>$kind)$out['testimonials.item.'.$key]=['tag'=>'{kiwe_testimonial_'.$key.'}','kind'=>$kind,'owner'=>'selected-approved-native-comment','scope'=>'current-public-testimonial'];
		$out['testimonials.available']=['tag'=>'{kiwe_testimonials_available}','kind'=>'boolean','owner'=>'selected-approved-native-comments','scope'=>'site'];
		$out['testimonials.count']=['tag'=>'{kiwe_testimonials_count}','kind'=>'number','owner'=>'selected-approved-native-comments','scope'=>'site','limit'=>100];
		return$out;
	}
	public function definitions(): array { $out=[];foreach(self::fields()as$key=>$field)$out[trim($field['tag'],'{}')]=__('Community testimonial','dsa').' · '.substr($key,13);return$out; }
	public function query_options(array $options): array { $options['queryTypes']['kiwe_testimonials']=__('Kiwe community testimonials','dsa');return$options; }
	public function controls(array $controls): array {
		$controls['kiweTestimonialLimit']=['tab'=>'content','type'=>'number','label'=>__('Testimonials to show','dsa'),'min'=>1,'max'=>100,'default'=>20,'required'=>['query.objectType','=','kiwe_testimonials']];
		return$controls;
	}
	public function run_query($result,$query) {
		if(!is_object($query)||($query->object_type??'')!=='kiwe_testimonials')return$result;
		$limit=max(1,min(100,(int)($query->settings['kiweTestimonialLimit']??$query->settings['query']['posts_per_page']??20)));$out=[];
		foreach(array_slice(array_keys(self::items()),0,$limit)as$id){$comment=get_comment($id);if($comment&&Selection::public_testimonial($comment))$out[]=array_intersect_key(self::items()[$id],array_flip(['id','name','quote','url','rating','isExcerpt','source','target_id','source_type','source_title','likes','pinned','hearted']))+['_kiweContextObject'=>'testimonial'];}
		return$out;
	}
	public function loop_object_id($id,$object,$query_id='') { return is_array($object)&&($object['_kiweContextObject']??'')==='testimonial'&&isset(self::items()[(int)($object['id']??0)])?(int)$object['id']:$id; }
	public function render(string $name,string $context='text') {
		if(!in_array($name,['kiwe_testimonials_available','kiwe_testimonials_count'],true)&&!preg_match('/^kiwe_testimonial_(id|name|quote|url|rating|target_id|source_type|source_title|likes|pinned|hearted)$/D',$name,$match))return null;
		if(in_array($context,['image','media'],true))return[];
		if($name==='kiwe_testimonials_available')return$context==='link'?'':(self::items()?'1':'');
		if($name==='kiwe_testimonials_count')return$context==='link'?'':(string)count(self::items());
		if(!class_exists('\\Bricks\\Query')||!\Bricks\Query::is_looping())return'';
		$object=\Bricks\Query::get_loop_object();
		if(!is_array($object)||($object['_kiweContextObject']??'')!=='testimonial')return'';
		$id=absint($object['id']??0);$comment=$id?get_comment($id):null;
		if(!$comment||!isset(self::items()[$id])||!Selection::public_testimonial($comment))return'';
		$item=self::items()[$id]??[];$field=$match[1];
		if($context==='link'&&$field!=='url')return'';
		if($field==='url')return esc_url_raw((string)($item['url']??''));
		if(in_array($field,['id','rating','target_id','likes'],true))return isset($item[$field])?(string)absint($item[$field]):'';
		if(in_array($field,['pinned','hearted'],true))return!empty($item[$field])?'1':'';
		return esc_html((string)($item[$field]??''));
	}
}
