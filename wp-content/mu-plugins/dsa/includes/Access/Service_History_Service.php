<?php
namespace DSA\Access;

use DSA\Onboarding\Design_Context_Profile_Service as Profile;

if ( ! defined( 'ABSPATH' ) ) exit;

/** Native Service writing history. WordPress owns every revision and actor autosave. */
final class Service_History_Service {
	private function error( string $message, int $status=409 ): \WP_Error { return new \WP_Error( 'kiwe_service_history',$message,[ 'status'=>$status ] ); }
	private function source(): string {
		$profile=get_option(Profile::OPTION_PROFILE,[]);
		$source=is_array($profile) ? sanitize_key((string)($profile['services']['sourcePostType'] ?? '')) : '';
		return $source && isset((new Profile())->service_post_types()[$source]) ? $source : '';
	}
	public function can_manage( int $id ): bool {
		$post=$id ? get_post($id) : null; $source=$this->source();
		return $post instanceof \WP_Post && $source && $post->post_type===$source && in_array($post->post_status,['draft','pending','publish'],true) && post_type_supports($source,'revisions') && current_user_can('kiwe_manage_context') && current_user_can('edit_post',$id);
	}
	private function state( int $id ): array {
		global $wpdb;
		$post=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->posts} WHERE ID=%d",$id),ARRAY_A);
		$meta=$wpdb->get_results($wpdb->prepare("SELECT meta_id,meta_key,meta_value FROM {$wpdb->postmeta} WHERE post_id=%d ORDER BY meta_id LIMIT 1001",$id),ARRAY_A);
		$terms=$wpdb->get_results($wpdb->prepare("SELECT term_taxonomy_id,term_order FROM {$wpdb->term_relationships} WHERE object_id=%d ORDER BY term_taxonomy_id LIMIT 201",$id),ARRAY_A);
		$profile=get_option(Profile::OPTION_PROFILE,[]);
		if ( ! $post || ! is_array($meta) || ! is_array($terms) || count($meta)>1000 || count($terms)>200 || strlen(wp_json_encode([$post,$meta,$terms,$profile]))>2097152 ) throw new \RuntimeException('Unavailable native history state');
		return [ 'post'=>$post,'meta'=>$meta,'terms'=>$terms,'source'=>$this->source(),'profile'=>$profile,'conversion'=>hash('sha256',wp_json_encode(get_option(\DSA\Onboarding\Native_Service_Conversion_Service::OPTION,false))) ];
	}
	private static function hash( array $state ): string { return hash('sha256',wp_json_encode($state)); }
	public function fingerprint( int $id ): string { return self::hash($this->state($id)); }
	private function own_autosave( int $id ) {
		$post=wp_get_post_autosave($id,get_current_user_id());
		return $post instanceof \WP_Post && (int)$post->post_parent===$id && (int)$post->post_author===get_current_user_id() && (int)wp_is_post_autosave($post)===$id ? $post : null;
	}
	private static function summary( \WP_Post $revision ): array {
		$user=get_userdata((int)$revision->post_author);
		return [ 'id'=>(int)$revision->ID,'title'=>sanitize_text_field($revision->post_title),'excerpt'=>wp_html_excerpt(wp_strip_all_tags($revision->post_content),140,'…'),'readerExcerpt'=>wp_html_excerpt(wp_strip_all_tags($revision->post_excerpt),400,'…'),'modifiedGmt'=>$revision->post_modified_gmt,'author'=>$user ? $user->display_name : __('Unknown author','dsa'),'expected'=>Task_Workspace_UI::revision_fingerprint($revision) ];
	}
	public function revisions( int $id ) {
		if ( ! $this->can_manage($id) ) return $this->error('History is unavailable for this Service.',403);
		try {
			$items=[]; $post=get_post($id);
			foreach ( wp_get_post_revisions($id,['posts_per_page'=>9,'orderby'=>'date','order'=>'DESC','check_enabled'=>false]) as $revision ) {
				if ( wp_is_post_autosave($revision) ) continue;
				$items[]=self::summary($revision); if (count($items)===8) break;
			}
			$autosave=$this->own_autosave($id);
			return [ 'serviceId'=>$id,'expected'=>$this->fingerprint($id),'autosave'=>$autosave && ! Task_Workspace_UI::revision_text_matches($autosave,$post) ? self::summary($autosave) : null,'revisions'=>$items,'canSaveRecovery'=>true,'canRestore'=>wp_revisions_enabled($post) ];
		} catch (\Throwable $error) { return $this->error('Service history could not be read. Reload and review.'); }
	}
	/** Full writing comparison uses the same shape as the shared Timeline dialog. */
	public function version( int $revision_id ) {
		$revision=wp_get_post_revision($revision_id); $id=$revision ? (int)$revision->post_parent : 0;
		if ( ! $revision || ! $this->can_manage($id) ) return $this->error('This Service version is unavailable.',403);
		$recovery=(bool)wp_is_post_autosave($revision); $own=(int)$revision->post_author===get_current_user_id(); $post=get_post($id);
		$type=get_post_type_object($post->post_type);
		if ($recovery && ! $own && (empty($type->cap->edit_others_posts) || ! current_user_can($type->cap->edit_others_posts))) return $this->error('This recovery copy is private to its writer.',403);
		try {
			return [ 'id'=>$revision_id,'recordId'=>$id,'type'=>$post->post_type,'typeLabel'=>(string)$type->labels->singular_name,'recovery'=>$recovery,'title'=>$revision->post_title,'contentHtml'=>wp_kses_post($revision->post_content),'excerptHtml'=>wp_kses_post($revision->post_excerpt),'currentTitle'=>$post->post_title,'currentHtml'=>wp_kses_post($post->post_content),'currentExcerptHtml'=>wp_kses_post($post->post_excerpt),'when'=>mysql_to_rfc3339($revision->post_modified_gmt),'expected'=>Task_Workspace_UI::revision_fingerprint($revision),'recordExpected'=>$this->fingerprint($id),'canRestore'=>(!$recovery || $own) && wp_revisions_enabled($post),'canDiscard'=>$recovery && $own ];
		} catch (\Throwable $error) { return $this->error('This Service version could not be read.'); }
	}
	private function inputs( array $input, array $keys ): bool {
		$actual=array_keys($input); sort($actual); sort($keys);
		if ($actual!==$keys) return false;
		foreach($keys as $key) if(!is_string($input[$key])) return false;
		return preg_match('/^[a-f0-9]{64}$/D',$input['expected'])===1;
	}
	/** Never updates the parent draft: core's revision-only writer also works for published Services. */
	public function autosave( int $id, array $input ) {
		if ( ! $this->can_manage($id) ) return $this->error('Your role cannot save recovery for this Service.',403);
		if ( ! $this->inputs($input,['expected','autosaveExpected','title','content','excerpt']) || strlen($input['title'])>800 || strlen($input['content'])>400000 || strlen($input['excerpt'])>64000 || false===preg_match('//u',$input['title'].$input['content'].$input['excerpt']) ) return $this->error('Choose complete, bounded Service writing.',400);
		$title=sanitize_text_field($input['title']); $content=wp_kses_post($input['content']); $excerpt=sanitize_textarea_field($input['excerpt']);
		if (preg_match_all('/./us',$title)>200 || preg_match_all('/./us',$content)>100000 || preg_match_all('/./us',$excerpt)>16000) return $this->error('Service writing exceeds the supported size.',400);
		return $this->change($id,$input['expected'],function(array $before)use($id,$input,$title,$content,$excerpt){
			$post=get_post($id); $current=$this->own_autosave($id); $proof=$current ? Task_Workspace_UI::revision_fingerprint($current) : '';
			$recovery=$current && ! Task_Workspace_UI::revision_text_matches($current,$post);
			if ( ($recovery || $input['autosaveExpected']!=='') && ! ($input['autosaveExpected']!=='' && hash_equals($proof,$input['autosaveExpected'])) ) return $this->error('A recovery copy changed. Review it before replacing anything.');
			if ( ! function_exists('wp_autosave_post_revisioned_meta_fields') ) require_once ABSPATH.'wp-admin/includes/post.php';
			$controller=new \WP_REST_Autosaves_Controller($post->post_type);
			$saved=$controller->create_post_autosave(['ID'=>$id,'post_title'=>$title,'post_content'=>$content,'post_excerpt'=>$excerpt]); $after=$this->own_autosave($id);
			if (is_wp_error($saved) || !$saved || !$after || (int)$after->ID!==(int)$saved || $after->post_title!==$title || $after->post_content!==$content || $after->post_excerpt!==$excerpt || self::hash($before)!==$this->fingerprint($id)) return $this->error('WordPress did not confirm the recovery copy. No recovery change was kept.');
			return ['serviceId'=>$id,'expected'=>self::hash($before),'autosave'=>self::summary($after)];
		});
	}
	public function discard_autosave( int $id, int $revision_id, array $input ) {
		if (!$this->can_manage($id)) return $this->error('Recovery is unavailable for this Service.',403);
		if (!$this->inputs($input,['expected','recoveryExpected'])) return $this->error('Review the current recovery copy first.',400);
		return $this->change($id,$input['expected'],function(array $before)use($id,$revision_id,$input){
			$copy=$this->own_autosave($id);
			if (!$copy || (int)$copy->ID!==$revision_id || !hash_equals(Task_Workspace_UI::revision_fingerprint($copy),$input['recoveryExpected'])) return $this->error('This recovery copy changed or belongs to another writer.');
			if (!wp_delete_post_revision($revision_id) || $this->own_autosave($id) || self::hash($before)!==$this->fingerprint($id)) return $this->error('WordPress did not confirm the discard. No change was kept.');
			return ['serviceId'=>$id,'expected'=>self::hash($before),'autosave'=>null];
		});
	}
	public function restore( int $id, int $revision_id, array $input ) {
		if (!$this->can_manage($id)) return $this->error('History is unavailable for this Service.',403);
		if (!$this->inputs($input,['expected','revisionExpected'])) return $this->error('Review the current Service version first.',400);
		return $this->change($id,$input['expected'],function(array $before)use($id,$revision_id,$input){
			$post=get_post($id); $revision=wp_get_post_revision($revision_id);
			if (!$revision || (int)$revision->post_parent!==$id || (wp_is_post_autosave($revision) && (int)$revision->post_author!==get_current_user_id()) || !hash_equals(Task_Workspace_UI::revision_fingerprint($revision),$input['revisionExpected'])) return $this->error('This reviewed Service version changed or is unavailable.');
			if (!wp_revisions_enabled($post)) return $this->error('Enable WordPress saved history before restoring this Service.',403);
			wp_save_post_revision($id);
			$result=Task_Workspace_UI::restore_revision_writing($post,$revision_id); $after=get_post($id);
			if (is_wp_error($result) || (int)$result!==$id || !$after || !Task_Workspace_UI::revision_text_matches($revision,$after) || $this->protected_state($before)!==$this->protected_state($this->state($id)) || !(new \DSA\Element_Registry())->snapshot_matches_post($after)) return $this->error('Restoration could not preserve the complete Service settings. No restore was kept.');
			$kept=false;
			foreach(wp_get_post_revisions($id,['posts_per_page'=>250,'check_enabled'=>false]) as $old) if(!wp_is_post_autosave($old) && Task_Workspace_UI::revision_text_matches($old,$post)) {$kept=true;break;}
			if (!$kept) return $this->error('WordPress retention could not keep the previous writing. No restore was kept.');
			return ['serviceId'=>$id,'expected'=>$this->fingerprint($id),'restored'=>true];
		});
	}
	private function protected_state( array $state ): string {
		foreach(['post_title','post_content','post_excerpt','post_modified','post_modified_gmt'] as $key) unset($state['post'][$key]);
		$state['meta']=array_values(array_filter($state['meta'],static fn($row)=>!in_array($row['meta_key'],['_edit_last',\DSA\Element_Registry::SNAPSHOT_META_KEY],true)));
		return self::hash($state);
	}
	/** Row-locked native writes roll back failed hooks/readback; external side effects remain plugin-owned. */
	private function change( int $id, string $expected, callable $write ) {
		global $wpdb; $lock='kiwe-service-history-'.substr(hash('sha256',$wpdb->prefix.'|'.$id),0,32);
		if ((string)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,0)',$lock))!=='1') return $this->error('Another Service history change is running. Reload before trying again.');
		$transaction=false; $ids=[$id];
		try {
			if ((string)$wpdb->get_var('SELECT @@session.in_transaction')!=='0') return $this->error('An available native transaction is required.');
			foreach([$wpdb->posts,$wpdb->postmeta,$wpdb->term_relationships,$wpdb->options] as $table) {
				$row=$wpdb->get_row($wpdb->prepare('SHOW TABLE STATUS WHERE Name=%s',$table),ARRAY_A);
				if (!$row || strcasecmp($row['Engine'] ?? '','InnoDB')!==0) return $this->error('Safe Service history needs native InnoDB tables.');
			}
			if ($wpdb->query('SET TRANSACTION ISOLATION LEVEL SERIALIZABLE')===false || $wpdb->query('START TRANSACTION')===false) return $this->error('The native history transaction is unavailable.');
			$transaction=true;
			$ids=array_map('intval',$wpdb->get_col($wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE ID=%d OR (post_type='revision' AND post_parent=%d) ORDER BY ID LIMIT 1002 FOR UPDATE",$id,$id)));
			if($wpdb->last_error) return $this->error('Service rows could not be locked safely.');
			if(count($ids)>1001) return $this->error('This Service has too much history for one safe change. Ask your designer to review retention.');
			$wpdb->get_col($wpdb->prepare("SELECT meta_id FROM {$wpdb->postmeta} WHERE post_id=%d FOR UPDATE",$id));
			if($wpdb->last_error) return $this->error('Service metadata could not be locked safely.');
			$wpdb->get_col($wpdb->prepare("SELECT term_taxonomy_id FROM {$wpdb->term_relationships} WHERE object_id=%d FOR UPDATE",$id));
			if($wpdb->last_error) return $this->error('Service terms could not be locked safely.');
			$wpdb->get_col($wpdb->prepare("SELECT option_id FROM {$wpdb->options} WHERE option_name=%s FOR UPDATE",Profile::OPTION_PROFILE));
			if($wpdb->last_error) return $this->error('The Service configuration could not be locked safely.');
			foreach($ids as $record_id) clean_post_cache($record_id); $this->forget_options();
			if (!$this->can_manage($id)) return $this->error('This Service or your access changed.',403);
			$before=$this->state($id);
			if(!hash_equals(self::hash($before),$expected)) return $this->error('This Service changed elsewhere. Reopen its history before continuing.');
			$result=$write($before); if(is_wp_error($result)) return $result;
			if ($wpdb->query('COMMIT')===false) return $this->error('The Service history commit could not be confirmed. Reload and review.');
			$transaction=false; return $result;
		} catch(\Throwable $error) { return $this->error('WordPress could not confirm this history change. Reload and review before retrying.'); }
		finally {
			try {$ids=array_merge($ids,array_map('intval',$wpdb->get_col($wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE post_type='revision' AND post_parent=%d",$id))));} catch(\Throwable $ignored) {}
			if($transaction) $wpdb->query('ROLLBACK');
			foreach(array_unique($ids) as $record_id) clean_post_cache($record_id);
			$this->forget_options();
			$wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock));
		}
	}
	private function forget_options(): void {
		foreach([Profile::OPTION_PROFILE,\DSA\Onboarding\Native_Service_Conversion_Service::OPTION,'alloptions','notoptions'] as $key) wp_cache_delete($key,'options');
	}
}
