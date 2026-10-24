<?php

namespace DSA\SEO;

use DSA\Schema\Schema_Template_Service as Templates;
use DSA\Access\Task_Workspace_UI as UI;
use DSA\Utilities\Origin_Checker;

if ( ! defined( 'ABSPATH' ) ) exit;

/** Per-object reviewed imports. Source plugins/settings remain untouched. */
final class SEO_Structured_Import_Service {
	public const JOURNAL = '_kiwe_schema_import_v1';
	public function register(): void {
		add_action('rest_api_init',[$this,'routes']);(new SEO_Redirect_Service())->register();
	}
	public function routes(): void {
		register_rest_route('dsa/v1','/workspace/ai/seo-structured',[
			['methods'=>'GET','callback'=>[$this,'read'],'permission_callback'=>[$this,'permission']],
			['methods'=>'POST','callback'=>[$this,'mutate'],'permission_callback'=>[$this,'permission']],
		]);
	}
	public function permission( \WP_REST_Request $request ): bool {
		if(!current_user_can('manage_options')||!in_array($request->get_method(),['GET','POST'],true)||!wp_verify_nonce((string)$request->get_header('X-WP-Nonce'),'wp_rest')||!Origin_Checker::is_same_site_request())return false;
		return $request->get_method()==='GET'||true===Origin_Checker::mutation_allowed($request);
	}
	public static function schema_review( int $id, bool $undo = false ) {
		global $wpdb;
		if(!$id||!get_post($id)||get_post_type($id)==='revision')return new \WP_Error('kiwe_schema_object','Choose an existing content record.');
		if($undo){
			$size=$wpdb->get_row($wpdb->prepare("SELECT COUNT(*) n,COALESCE(SUM(OCTET_LENGTH(meta_value)),0) bytes FROM {$wpdb->postmeta} WHERE post_id=%d AND meta_key IN (%s,%s)",$id,Templates::META,self::JOURNAL),ARRAY_A);
			if($wpdb->last_error||!$size||(int)$size['n']>2||(int)$size['bytes']>196608)return new \WP_Error('kiwe_schema_journal','Schema undo exceeds its journal boundary.');
			$target=get_post_meta($id,Templates::META,true);$exists=metadata_exists('post',$id,Templates::META);$journal=get_post_meta($id,self::JOURNAL,true);
			$valid=is_array($journal)&&($journal['schema']??null)===1&&isset($journal['graph'])&&!is_wp_error(Templates::validate($journal['graph']))&&($journal['importedSha']??'')===hash('sha256',(string)wp_json_encode($journal['graph']));
			return ['schema'=>'kiwe.schema-import.review.v1','kind'=>'schema','operation'=>'undo','objectId'=>$id,'sourceSha'=>'','targetExists'=>$exists,'targetSha'=>hash('sha256',(string)wp_json_encode([$exists,$target,$journal])),'journal'=>$journal,'canImport'=>false,'canUndo'=>$valid&&(!$exists||($journal['importedSha']??'')===hash('sha256',(string)wp_json_encode($target)))];
		}
		$like=$wpdb->esc_like('rank_math_schema').'%';
		$size=$wpdb->get_row($wpdb->prepare("SELECT COUNT(*) n,COALESCE(SUM(OCTET_LENGTH(meta_value)),0) bytes,COALESCE(MAX(OCTET_LENGTH(meta_value)),0) largest FROM {$wpdb->postmeta} WHERE post_id=%d AND meta_key LIKE %s",$id,$like),ARRAY_A);
		if($wpdb->last_error||!is_array($size))return new \WP_Error('kiwe_schema_read','Source schema inventory is unavailable.');
		if(!$size['n'])return new \WP_Error('kiwe_schema_empty','No saved source schema exists for this record.');
		if((int)$size['n']>16||(int)$size['bytes']>Templates::BYTES||(int)$size['largest']>Templates::BYTES)return new \WP_Error('kiwe_schema_size','Source schema exceeds the reviewed materialization budget.');
		$rows=$wpdb->get_results($wpdb->prepare("SELECT meta_id,meta_key,meta_value FROM {$wpdb->postmeta} WHERE post_id=%d AND meta_key LIKE %s ORDER BY meta_id LIMIT 16",$id,$like),ARRAY_A);
		if($wpdb->last_error||count($rows)!==(int)$size['n'])return new \WP_Error('kiwe_schema_read','Source schema changed while it was read.');
		$graph=[];foreach($rows as$row){$nodes=Templates::source_node($row['meta_value']);if(is_wp_error($nodes))return$nodes;$graph=array_merge($graph,$nodes);}
		$graph=Templates::validate($graph);if(is_wp_error($graph))return$graph;
		$target=get_post_meta($id,Templates::META,true);$exists=metadata_exists('post',$id,Templates::META);$journal=get_post_meta($id,self::JOURNAL,true);
		return ['schema'=>'kiwe.schema-import.review.v1','kind'=>'schema','objectId'=>$id,'sourceSha'=>hash('sha256',(string)wp_json_encode($rows)),'graph'=>$graph,'targetExists'=>$exists,'targetSha'=>hash('sha256',(string)wp_json_encode([$exists,$target,$journal])),'journal'=>$journal,'canImport'=>!$exists,'canUndo'=>is_array($journal)&&isset($journal['graph'])&&($journal['importedSha']??'')===hash('sha256',(string)wp_json_encode($journal['graph']))&&(!$exists||($journal['importedSha']??'')===hash('sha256',(string)wp_json_encode($target)))];
	}
	public static function redirect_review( int $id, bool $undo = false ) {
		global $wpdb;$table=$wpdb->prefix.'rank_math_redirections';if(!$id)return new \WP_Error('kiwe_redirect_object','Choose a source redirect.');
		if($undo){
			if(1!==(int)(get_option(SEO_Redirect_Service::OPTION,[])['schema']??0))return new \WP_Error('kiwe_redirect_journal','No prepared redirect journal exists.');
			$journal=$wpdb->get_results($wpdb->prepare('SELECT * FROM '.SEO_Redirect_Service::table().' WHERE source_id=%d ORDER BY id LIMIT 17',$id),ARRAY_A);
			if($wpdb->last_error||count($journal)>16)return new \WP_Error('kiwe_redirect_read','Native redirect journal is unavailable.');
			return ['schema'=>'kiwe.redirect-import.review.v1','kind'=>'redirect','operation'=>'undo','objectId'=>$id,'sourceSha'=>'','targetSha'=>hash('sha256',(string)wp_json_encode($journal)),'canImport'=>false,'canUndo'=>!empty($journal),'storeReady'=>true];
		}
		$prior=$wpdb->suppress_errors(true);
		try{
			$size=$wpdb->get_row($wpdb->prepare("SELECT OCTET_LENGTH(sources) bytes,OCTET_LENGTH(url_to) target FROM {$table} WHERE id=%d",$id),ARRAY_A);
			if($wpdb->last_error||!$size)return new \WP_Error('kiwe_redirect_read','Source redirect is unavailable.');
			if((int)$size['bytes']>32768||(int)$size['target']>2048)return new \WP_Error('kiwe_redirect_size','Source redirect exceeds its materialization budget.');
			$row=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id=%d",$id),ARRAY_A);
			if($wpdb->last_error||!is_array($row))return new \WP_Error('kiwe_redirect_read','Source redirect read failed.');
		}finally{$wpdb->suppress_errors($prior);}
		if(array_diff(array_keys($row),['id','sources','url_to','header_code','hits','status','created','updated','last_accessed']))return new \WP_Error('kiwe_redirect_unsupported','Extended source behavior needs explicit support before handover.');
		if($row['status']!=='active')return new \WP_Error('kiwe_redirect_inactive','Only active source rules can become active Kiwe rules.');
		$sources=Templates::decode($row['sources']);if(is_wp_error($sources)||!is_array($sources)||!array_is_list($sources)||!$sources||count($sources)>16)return new \WP_Error('kiwe_redirect_source','Source patterns exceed the supported import boundary.');
		$rules=[];foreach($sources as$source){
			if(!is_array($source)||array_diff(array_keys($source),['pattern','comparison','ignore'])||($source['comparison']??'')!=='exact'||!is_string($source['pattern']??null)||!in_array($source['ignore']??'',['','case'],true))return new \WP_Error('kiwe_redirect_unsupported','Non-exact or extended source matching must be supported before handover.');
			$rule=SEO_Redirect_Service::rule($source['pattern'],(string)$row['url_to'],(int)$row['header_code'],($source['ignore']??'')==='case');if(is_wp_error($rule))return$rule;if(isset($rules[$rule['source_hash']]))return new \WP_Error('kiwe_redirect_duplicate','Duplicate source patterns require review.');$rules[$rule['source_hash']]=$rule;
		}
		$settings=get_option(SEO_Redirect_Service::OPTION,[]);$journal=[];
		if(1===(int)($settings['schema']??0)){$journal=$wpdb->get_results($wpdb->prepare('SELECT * FROM '.SEO_Redirect_Service::table().' WHERE source_id=%d ORDER BY id LIMIT 17',$id),ARRAY_A);if($wpdb->last_error||count($journal)>16)return new \WP_Error('kiwe_redirect_read','Native redirect journal is unavailable.');}
		return ['schema'=>'kiwe.redirect-import.review.v1','kind'=>'redirect','objectId'=>$id,'sourceSha'=>hash('sha256',(string)wp_json_encode($row)),'rules'=>array_values($rules),'targetSha'=>hash('sha256',(string)wp_json_encode($journal)),'canImport'=>true,'canUndo'=>!empty($journal),'storeReady'=>1===(int)($settings['schema']??0)];
	}
	public static function expected( array $review ): string { return hash('sha256',(string)wp_json_encode([$review['kind'],$review['operation']??'import',$review['objectId'],$review['sourceSha'],$review['targetSha']])); }
	public function read( \WP_REST_Request $request ): \WP_REST_Response {
		if(!$this->permission($request))return UI::failure(new \WP_Error('kiwe_structured_forbidden','Source handover is available only to the website manager.'),403);
		$kind=$request->get_param('kind');$id=absint($request->get_param('objectId'));
		$undo=$request->get_param('operation')==='undo';
		$result=match($kind){'schema'=>self::schema_review($id,$undo),'redirect'=>self::redirect_review($id,$undo),default=>new \WP_Error('kiwe_structured_kind','Choose schemas or redirects.')};
		if(is_wp_error($result))return UI::failure($result,409);$result['expected']=self::expected($result);unset($result['graph'],$result['rules'],$result['journal']);return UI::response($result);
	}
	public function mutate( \WP_REST_Request $request ): \WP_REST_Response {
		if(!$this->permission($request))return UI::failure(new \WP_Error('kiwe_structured_forbidden','Source handover is available only to the website manager.'),403);
		$body=$request->get_json_params();if(!is_array($body)||($body['confirmed']??null)!==true||!in_array($body['kind']??null,['schema','redirect'],true)||!in_array($body['operation']??null,['import','undo'],true)||!is_int($body['objectId']??null)||$body['objectId']<=0||!is_string($body['expected']??null)||!preg_match('/^[a-f0-9]{64}$/D',$body['expected']))return UI::failure(new \WP_Error('kiwe_structured_request','Review and confirm an existing source record.'),400);
		$id=$body['objectId'];$undo=$body['operation']==='undo';$review=$body['kind']==='schema'?self::schema_review($id,$undo):self::redirect_review($id,$undo);
		if(is_wp_error($review))return UI::failure($review,409);
		if(!hash_equals(self::expected($review),$body['expected']))return UI::failure(new \WP_Error('kiwe_structured_changed','Source or destination changed. Refresh the review.'),409);
		if($body['kind']==='schema'){
			global $wpdb; $lock='kiwe_schema_'.substr(hash('sha256',$wpdb->prefix.'|'.$id),0,32);
			if('1'!==(string)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,0)',$lock)))return UI::failure(new \WP_Error('kiwe_schema_busy','Another schema change is in progress.'),409);
			try{wp_cache_delete($id,'post_meta');$fresh=self::schema_review($id,$undo);$result=is_wp_error($fresh)?$fresh:(!hash_equals(self::expected($fresh),$body['expected'])?new \WP_Error('kiwe_structured_changed','Source or destination changed. Refresh the review.'):($body['operation']==='import'?$this->import_schema($fresh):$this->undo_schema($fresh)));}
			finally{$wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock));}
		}
		else{$result=SEO_Redirect_Service::prepare_store();if(!is_wp_error($result))$result=$body['operation']==='import'?SEO_Redirect_Service::stage($review['rules'],$id,$review['sourceSha'],$review['targetSha']):SEO_Redirect_Service::undo($id,$review['targetSha']);}
		return is_wp_error($result)?UI::failure($result,409):UI::response(['saved'=>true,'kind'=>$body['kind'],'operation'=>$body['operation'],'objectId'=>$id,'sourcePreserved'=>true,'providerRemovalReady'=>false]);
	}
	private function import_schema( array $r ) {
		$id=$r['objectId'];if(!$r['canImport'])return new \WP_Error('kiwe_schema_owned','Existing Kiwe schema stays authoritative.');
		$journal=['schema'=>1,'sourceSha'=>$r['sourceSha'],'importedSha'=>hash('sha256',(string)wp_json_encode($r['graph'])),'graph'=>$r['graph']];
		$previous=get_post_meta($id,self::JOURNAL,true);
		if($previous&&$previous!==$journal)return new \WP_Error('kiwe_schema_journal','An earlier schema import needs review.');
		if(!$previous&&!add_post_meta($id,self::JOURNAL,wp_slash($journal),true))return new \WP_Error('kiwe_schema_journal','Schema journal could not be saved.');
		if(get_post_meta($id,self::JOURNAL,true)!==$journal)return new \WP_Error('kiwe_schema_journal','Schema journal readback failed.');
		if(!add_post_meta($id,Templates::META,wp_slash($r['graph']),true))return new \WP_Error('kiwe_schema_write','Import paused with its journal; refresh and resume.');
		return get_post_meta($id,Templates::META,true)===$r['graph']?true:new \WP_Error('kiwe_schema_write','Schema readback failed.');
	}
	private function undo_schema( array $r ) {
		$id=$r['objectId'];$j=$r['journal'];if(!$r['canUndo']||!is_array($j))return new \WP_Error('kiwe_schema_conflict','Newer schema edits are protected.');
		if($r['targetExists']&&!delete_post_meta($id,Templates::META,$j['graph']))return new \WP_Error('kiwe_schema_undo','Undo needs native readback.');
		if(metadata_exists('post',$id,Templates::META))return new \WP_Error('kiwe_schema_undo','Schema undo is incomplete.');
		if(!delete_post_meta($id,self::JOURNAL,$j)||metadata_exists('post',$id,self::JOURNAL))return new \WP_Error('kiwe_schema_undo','Schema journal retirement needs review.');
		return true;
	}
}
