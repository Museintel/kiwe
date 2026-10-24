<?php
declare(strict_types=1);
namespace DSA\Onboarding {
 final class Design_Context_Profile_Service { public function current(): array { return [ 'services'=>[ 'sourcePostType'=>'' ] ]; } }
}
namespace DSA\Access {
	final class Service_History_Service { public function can_manage(int $id): bool { return false; } }
 final class Post_Workspace_Service {
  public static function fingerprint( \WP_Post $post ): string { return hash('sha256',(string)$post->ID.'|'.$post->post_content); }
  public function revisions(int $id): array { return ['expected'=>self::fingerprint(\get_post($id))]; }
  public function restore_revision(int $id,int $revision_id,array $input): array { if(!hash_equals(Task_Workspace_UI::revision_fingerprint(\get_post($revision_id)),$input['revisionExpected']))throw new \RuntimeException('Native restore proof mismatch');$GLOBALS['timelineAction']=['restore',$id,$revision_id,$input];return ['id'=>$id]; }
  public function discard_autosave(int $id,int $revision_id,array $input): array { if(!hash_equals(Task_Workspace_UI::revision_fingerprint(\get_post($revision_id)),$input['recoveryExpected']))throw new \RuntimeException('Native discard proof mismatch');$GLOBALS['timelineAction']=['discard',$id,$revision_id,$input];return ['id'=>$id]; }
 }
 final class Page_Publishing_Service {
  public const CAP_RESTORE='kiwe_restore_page_versions';
  public function can_restore(int $id): bool { return !empty($GLOBALS['pageRestore'])&&!in_array($id,$GLOBALS['deniedRestorePages'],true); }
  public static function fingerprint( \WP_Post $post ): string { return hash('sha256',(string)$post->ID.'|'.$post->post_content); }
  public function revisions(int $id): array { return ['expected'=>self::fingerprint(\get_post($id))]; }
  public function restore(int $id,int $revision_id,array $input): bool { if(!hash_equals(Task_Workspace_UI::revision_fingerprint(\get_post($revision_id)),$input['revisionExpected']))throw new \RuntimeException('Native page restore proof mismatch');$GLOBALS['timelineAction']=['restore',$id,$revision_id,$input];return true; }
  public function discard_autosave(int $id,int $revision_id,array $input): bool { if(!hash_equals(Task_Workspace_UI::revision_fingerprint(\get_post($revision_id)),$input['recoveryExpected']))throw new \RuntimeException('Native page discard proof mismatch');$GLOBALS['timelineAction']=['discard',$id,$revision_id,$input];return true; }
 }
}
namespace {
 define( 'ABSPATH', __DIR__ . '/' );
require_once __DIR__.'/fixtures/app-runtime-autoloader.php';
require_once dirname(__DIR__,2).'/wp-content/mu-plugins/dsa/includes/Apps/Store_App_Service.php';
 class WP_Post {
  public int $ID=0,$post_parent=0,$post_author=0;
  public string $post_type='',$post_title='',$post_content='',$post_excerpt='',$post_name='',$post_modified_gmt='';
  public function __construct(array $fields){foreach($fields as $key=>$value)$this->{$key}=$value;}
 }
 class WP_Error { public function __construct(private string $code, private string $message, private array $data=[]) {} public function get_error_code(): string { return $this->code; } }
 class WP_REST_Request { public function __construct(private array $params=[]) {} public function get_param(string $key) { return $this->params[$key] ?? null; } }
 class TimelineDatabase {
  public string $posts='wp_posts';
  public string $lastSql='';
  public array $lastBindings=[];
  public function prepare(string $sql, ...$bindings): string { $this->lastSql=$sql;$this->lastBindings=$bindings;return $sql; }
  public function esc_like(string $search): string { return addcslashes($search,'%_'); }
  public function get_col(string $sql): array { return $GLOBALS['revisionIds']; }
 }
 function __(string $text, string $domain=''): string { return $text; }
 function admin_url(string $path): string { return 'https://example.test/wp-admin/'.$path; }
 function sanitize_key($value): string { return preg_replace('/[^a-z0-9_-]/','',strtolower((string)$value)); }
 function sanitize_text_field($value): string { return trim(strip_tags((string)$value)); }
 function absint($value): int { return abs((int)$value); }
	function current_user_can(string $cap, ...$args): bool { if($cap==='kiwe_restore_page_versions')return !empty($GLOBALS['pageRestore']);return $cap==='edit_post' ? !in_array((int)$args[0],$GLOBALS['deniedParents'],true) : in_array($cap,['read','edit_posts','edit_pages','edit_others_posts'],true); }
 function get_post_type_object(string $type) { return in_array($type,['post','page'],true) ? (object)['cap'=>(object)['edit_posts'=>$type==='post'?'edit_posts':'edit_pages'],'labels'=>(object)['singular_name'=>ucfirst($type)]] : null; }
 function get_post(int $id) { return $GLOBALS['records'][$id] ?? null; }
 function wp_get_post_revision(int $id) { $record=get_post($id);return $record&&$record->post_type==='revision'?$record:null; }
 function wp_is_post_autosave($revision): bool { return str_contains((string)$revision->post_name,'autosave'); }
 function get_current_user_id(): int { return 4; }
 function wp_kses_post(string $value): string { return strip_tags($value,'<p><strong><em>'); }
 function wp_json_encode($value): string { return json_encode($value); }
 function is_wp_error($value): bool { return $value instanceof WP_Error; }
 function get_userdata(int $id) { return (object)['display_name'=>$id===4?'Editor Ada':'Client Ben']; }
 function get_the_title($post): string { return (string)$post->post_title; }
 function mysql_to_rfc3339(string $date): string { return str_replace(' ','T',$date).'Z'; }
 function get_users(array $args): array { return [(object)['ID'=>4,'display_name'=>'Editor Ada']]; }
 function wp_get_current_user() { return (object)['ID'=>4,'display_name'=>'Editor Ada']; }
 function check(string $label, bool $pass): void { if(!$pass)throw new \RuntimeException($label);echo "PASS $label\n"; }
 require dirname(__DIR__,2).'/wp-content/mu-plugins/dsa/includes/Access/Task_Workspace_UI.php';
 require dirname(__DIR__,2).'/wp-content/mu-plugins/dsa/includes/Access/Continuity_App_Service.php';
 $GLOBALS['wpdb']=new TimelineDatabase();
 $GLOBALS['deniedParents']=[202];
 $GLOBALS['deniedRestorePages']=[];
 $GLOBALS['pageRestore']=false;
 $GLOBALS['records']=[
  201=>new WP_Post(['ID'=>201,'post_type'=>'post','post_title'=>'A useful story','post_content'=>'<p>Current writing</p>']),
  202=>new WP_Post(['ID'=>202,'post_type'=>'page','post_title'=>'Private page','post_content'=>'Private writing']),
  301=>new WP_Post(['ID'=>301,'post_type'=>'revision','post_parent'=>201,'post_author'=>4,'post_name'=>'201-revision-v1','post_title'=>'Earlier story','post_content'=>'<p>Previous <strong>writing</strong></p>','post_modified_gmt'=>'2026-09-28 11:00:00']),
  302=>new WP_Post(['ID'=>302,'post_type'=>'revision','post_parent'=>202,'post_author'=>5,'post_name'=>'202-revision-v1','post_title'=>'Private','post_content'=>'Private writing','post_modified_gmt'=>'2026-09-28 10:00:00']),
  303=>new WP_Post(['ID'=>303,'post_type'=>'revision','post_parent'=>201,'post_author'=>4,'post_name'=>'201-autosave-v1','post_title'=>'Draft story','post_content'=>'<p>Unsaved writing</p>','post_excerpt'=>'<p>Unsaved summary</p>','post_modified_gmt'=>'2026-09-28 09:00:00']),
 ];
 $GLOBALS['revisionIds']=[301,302,303];
 $service=new \DSA\Access\Continuity_App_Service();
 $timeline=(new \ReflectionMethod($service,'timeline'))->invoke($service,new WP_REST_Request(['type'=>'all','sort'=>'date_desc','page'=>1]));
 check('timeline includes authorized saved and unsaved recovery versions',count($timeline['items'])===2&&$timeline['items'][0]['recordId']===201&&$timeline['items'][0]['author']==='Editor Ada'&&$timeline['items'][0]['when']==='2026-09-28T11:00:00Z'&&$timeline['items'][1]['recovery']===true);
 $preview=(new \ReflectionMethod($service,'timeline_version'))->invoke($service,303);
 check('review opens full sanitized recovery beside current saved content',$preview['contentHtml']==='<p>Unsaved writing</p>'&&$preview['excerptHtml']==='<p>Unsaved summary</p>'&&$preview['currentHtml']==='<p>Current writing</p>'&&$preview['canRestore']&&$preview['canDiscard']);
 $action=(new \ReflectionMethod($service,'timeline_action'))->invoke($service,['operation'=>'discard','id'=>303,'expected'=>$preview['expected'],'recordExpected'=>$preview['recordExpected']]);
 check('reviewed own recovery copy delegates discard to native post writer',$action['ok']===true&&$GLOBALS['timelineAction'][0]==='discard'&&$GLOBALS['timelineAction'][2]===303);
 $GLOBALS['records'][303]->post_excerpt='<p>Newer unsaved summary</p>';
 $changedExcerpt=(new \ReflectionMethod($service,'timeline_action'))->invoke($service,['operation'=>'discard','id'=>303,'expected'=>$preview['expected'],'recordExpected'=>$preview['recordExpected']]);
 check('a changed excerpt invalidates the full revision review proof',$changedExcerpt instanceof WP_Error);
 $stale=(new \ReflectionMethod($service,'timeline_action'))->invoke($service,['operation'=>'restore','id'=>301,'expected'=>str_repeat('0',64),'recordExpected'=>$preview['recordExpected']]);
 check('timeline refuses a stale version fingerprint',$stale instanceof WP_Error);
 check('revision query joins native parents and uses bounded prepared paging',str_contains($wpdb->lastSql,'INNER JOIN')&&str_contains($wpdb->lastSql,'LIMIT %d OFFSET %d')&&array_slice($wpdb->lastBindings,-2)===[41,0]);
 $filtered=(new \ReflectionMethod($service,'timeline'))->invoke($service,new WP_REST_Request(['type'=>'page','author'=>5,'search'=>'Private','from'=>'2026-09-01','to'=>'2026-09-28']));
 check('timeline type author search and dates are passed as prepared native filters',str_contains($wpdb->lastSql,'p.post_title LIKE %s')&&str_contains($wpdb->lastSql,'r.post_author = %d')&&in_array('page',$wpdb->lastBindings,true)&&in_array(5,$wpdb->lastBindings,true));
 $invalid=(new \ReflectionMethod($service,'timeline'))->invoke($service,new WP_REST_Request(['type'=>'attachment']));
 check('timeline refuses unrelated record types', $invalid instanceof WP_Error);
 $GLOBALS['pageRestore']=true;
 $pagePreview=(new \ReflectionMethod($service,'timeline_version'))->invoke($service,302);
 check('Page recovery authority opens full Timeline preview without generic page-edit access',is_array($pagePreview)&&$pagePreview['canRestore']&&$pagePreview['contentHtml']==='Private writing'&&!current_user_can('edit_post',202));
 $pageTimeline=(new \ReflectionMethod($service,'timeline'))->invoke($service,new WP_REST_Request(['type'=>'page']));
 check('Page recovery authority also admits authorized versions to the Timeline list',count(array_filter($pageTimeline['items'],static fn($item)=>$item['recordId']===202))===1);
 $pageAction=(new \ReflectionMethod($service,'timeline_action'))->invoke($service,['operation'=>'restore','id'=>302,'expected'=>$pagePreview['expected'],'recordExpected'=>$pagePreview['recordExpected']]);
 check('Timeline delegates Page restore with the same native review proof',$pageAction['ok']===true&&$GLOBALS['timelineAction'][0]==='restore'&&$GLOBALS['timelineAction'][1]===202);
 $GLOBALS['deniedRestorePages']=[202];
 $deniedPage=(new \ReflectionMethod($service,'timeline_version'))->invoke($service,302);
 check('Page versions remain private when both record-edit and Page recovery authority deny access',$deniedPage instanceof WP_Error);
}
