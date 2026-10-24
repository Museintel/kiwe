<?php
namespace DSA\Access;

use DSA\PhoneKey\PhoneKey_Bridge;
use DSA\Notifications\Push_Service;
use DSA\Utilities\Origin_Checker;

if ( ! defined( 'ABSPATH' ) ) exit;

/** Named audiences around native users. Membership never writes contact, role or consent. */
final class User_Group_Service {
	public const OPTION = 'kiwe_user_groups_v1';
	public const META = '_kiwe_campaign_groups';
	public const LIMIT = 500;
	private const SMART_SCAN_LIMIT = 5000;
	private const LOCK = 'kiwe_user_groups_lock';
	public static function allowed(): bool { return current_user_can( 'kiwe_manage_team' ) && current_user_can( 'list_users' ) && current_user_can( 'edit_users' ); }
	public function register(): void { add_action( 'rest_api_init', [ $this, 'routes' ] ); }
	public function permission( \WP_REST_Request $r ): bool {
		return Team_App_Service::enabled() && is_user_logged_in() && self::allowed() && in_array( $r->get_method(), [ 'GET','POST' ], true ) && wp_verify_nonce( (string) $r->get_header( 'X-WP-Nonce' ), 'wp_rest' ) && Origin_Checker::is_same_site_request() && ( 'GET' === $r->get_method() || true === Origin_Checker::mutation_allowed( $r ) );
	}
	public function routes(): void {
		if ( ! Team_App_Service::enabled() ) return;
		register_rest_route( 'dsa/v1', '/workspace/user-groups', [ [ 'methods'=>'GET','callback'=>[ $this,'index' ],'permission_callback'=>[ $this,'permission' ] ], [ 'methods'=>'POST','callback'=>[ $this,'create' ],'permission_callback'=>[ $this,'permission' ] ] ] );
		register_rest_route( 'dsa/v1', '/workspace/user-groups/people', [ 'methods'=>'GET','callback'=>[ $this,'people' ],'permission_callback'=>[ $this,'permission' ] ] );
		register_rest_route( 'dsa/v1', '/workspace/user-groups/(?P<id>(?:g-[a-f0-9]{16}|sms|email|whatsapp|app))', [ [ 'methods'=>'GET','callback'=>[ $this,'detail' ],'permission_callback'=>[ $this,'permission' ] ], [ 'methods'=>'POST','callback'=>[ $this,'change' ],'permission_callback'=>[ $this,'permission' ] ] ] );
	}
	private function response( $value ): \WP_REST_Response { return is_wp_error( $value ) ? Task_Workspace_UI::failure( $value, (int) ( $value->get_error_data()['status'] ?? 400 ) ) : Task_Workspace_UI::response( $value ); }
	private function error( string $message, int $status = 400 ): \WP_Error { return new \WP_Error( 'kiwe_user_group', $message, [ 'status'=>$status ] ); }
	private function definitions(): array {
		$stored = get_option( self::OPTION, [] );
		return is_array( $stored ) ? array_slice( array_values( array_filter( $stored, static fn( $g ) => is_array( $g ) && preg_match( '/^g-[a-f0-9]{16}$/', (string) ( $g['id'] ?? '' ) ) && is_string( $g['name'] ?? null ) ) ), 0, 50 ) : [];
	}
	public function catalog(): array {
		$groups = [];
		foreach ( [ 'sms'=>'SMS', 'email'=>'Email', 'whatsapp'=>'WhatsApp', 'app'=>'Browser / PWA' ] as $id=>$name ) $groups[] = [ 'id'=>$id,'name'=>$name,'smart'=>true,'description'=>'app' === $id ? 'People with their own browser permission and active device enrollment.' : ( 'email' === $id ? 'Verified email with an email notification choice.' : 'Verified phone with a ' . $name . ' notification choice.' ),'expected'=>'' ];
		foreach ( $this->definitions() as $group ) $groups[] = [ ...$group,'smart'=>false,'description'=>'Your selected WordPress accounts. Each campaign checks their current consent.','expected'=>$this->definition_proof( $group ) ];
		return $groups;
	}
	public function find( string $id ) { foreach ( $this->catalog() as $g ) if ( $g['id'] === $id ) return $g; return $this->error( 'This user group no longer exists. Refresh the groups.', 409 ); }
	private function definition_proof( array $g ): string { return hash( 'sha256', wp_json_encode( $g ) ); }
	public static function memberships( int $id ): array { $raw = get_user_meta( $id, self::META, true ); return is_array( $raw ) ? array_values( array_unique( array_filter( $raw, static fn( $g ) => is_string( $g ) && preg_match( '/^g-[a-f0-9]{16}$/', $g ) ) ) ) : []; }
	public static function member_proof( int $id ): string { return hash_hmac( 'sha256', wp_json_encode( [ $id,self::memberships( $id ) ] ), wp_salt( 'auth' ) ); }
	private function eligible_user( int $id ): bool { return get_userdata( $id ) && ( ! is_multisite() || is_user_member_of_blog( $id ) ) && ( ! Site_Owner_Service::is_owner( $id ) || Site_Owner_Service::is_owner() ) && current_user_can( 'edit_user', $id ); }
	/** Backups never widen the ordinary custom-group account boundary. */
	public function can_edit_account( int $id ): bool { return self::allowed() && $this->eligible_user( $id ); }
	private function has_channel_choice( int $id, string $channel ): bool {
		$p = get_user_meta( $id, 'dsa_notification_preferences', true );
		if ( ! is_array( $p ) ) return false;
		if ( in_array( $channel, (array) ( $p['channels'] ?? [] ), true ) ) return true;
		foreach ( (array) ( $p['topicChannels'] ?? [] ) as $channels ) if ( is_array( $channels ) && in_array( $channel, $channels, true ) ) return true;
		return false;
	}
	public function smart_member( int $id, string $channel, array $push_ids = [] ): bool {
		if ( ! $this->has_channel_choice( $id, $channel ) ) return false;
		if ( 'app' === $channel ) { $p = get_user_meta( $id, 'dsa_notification_preferences', true ); return 'granted' === ( $p['browserPermission'] ?? '' ) && in_array( $id, $push_ids, true ); }
		return '' !== PhoneKey_Bridge::campaign_contact( $id, $channel );
	}
	/** Native cursor/limit; an oversized audience is refused, never silently truncated for sending. */
	public function candidates( string $group, string $search = '', string $role = '' ): array {
		$g = $this->find( $group ); if ( is_wp_error( $g ) ) return [ 'ids'=>[],'limited'=>false ];
		$args = [ 'number'=>self::LIMIT+1,'fields'=>'ID','orderby'=>'ID','order'=>'ASC','count_total'=>false,'blog_id'=>get_current_blog_id() ];
		if ( $g['smart'] ) { $args['meta_key']='dsa_notification_preferences'; $args['meta_compare']='EXISTS'; }
		else $args['meta_query'] = [ [ 'key'=>self::META,'value'=>'"' . $group . '"','compare'=>'LIKE' ] ];
		if ( '' !== $search ) { $args['search']='*' . str_replace( '*','',sanitize_text_field( $search ) ) . '*'; $args['search_columns']=[ 'display_name','user_login' ]; }
		if ( $role && isset( wp_roles()->roles[ $role ] ) ) $args['role']=$role;
		if ( ! $g['smart'] ) {
			$raw = array_map( 'intval', get_users( $args ) );
			return [ 'ids'=>array_slice( $raw,0,self::LIMIT ),'limited'=>count( $raw ) > self::LIMIT ];
		}
		// A verified smart audience may be sparse among people who saved preferences.
		// Scan bounded pages rather than truncating before applying verification.
		$ids=[]; $scanned=0; $complete=false; $batch_size=100;
		while ( $scanned < self::SMART_SCAN_LIMIT && count( $ids ) <= self::LIMIT ) {
			$args['number']=$batch_size; $args['offset']=$scanned;
			$batch=array_map( 'intval', get_users( $args ) );
			if ( ! $batch ) { $complete=true; break; }
			update_meta_cache( 'user',$batch );
			$push='app' === $group ? ( new Push_Service() )->active_user_ids( $batch ) : [];
			foreach ( $batch as $id ) if ( $this->smart_member( $id,$group,$push ) ) $ids[]=$id;
			$scanned+=count( $batch );
			if ( count( $batch ) < $batch_size ) { $complete=true; break; }
		}
		return [ 'ids'=>array_slice( $ids,0,self::LIMIT ),'limited'=>count( $ids ) > self::LIMIT || ! $complete ];
	}
	private function project_person( int $id ): array { $u = get_userdata( $id ); return [ 'id'=>$id,'name'=>$u ? $u->display_name : '', 'roles'=>$u ? array_values( $u->roles ) : [],'groups'=>self::memberships( $id ),'expected'=>self::member_proof( $id ) ]; }
	public function index( \WP_REST_Request $r ): \WP_REST_Response { if ( ! $this->permission( $r ) ) return $this->response( $this->error( 'User groups are unavailable.',403 ) ); return $this->response( [ 'groups'=>$this->catalog(),'limit'=>self::LIMIT ] ); }
	public function people( \WP_REST_Request $r ): \WP_REST_Response {
		if ( ! $this->permission( $r ) ) return $this->response( $this->error( 'User groups are unavailable.',403 ) );
		$q = $r->get_query_params(); $page=max( 1,min( 500,absint( $q['page'] ?? 1 ) ) );
		$args=[ 'number'=>21,'offset'=>( $page-1 )*20,'orderby'=>[ 'display_name'=>'ASC','ID'=>'ASC' ],'count_total'=>false,'blog_id'=>get_current_blog_id() ];
		$search=substr( sanitize_text_field( (string) ( $q['search'] ?? '' ) ),0,80 ); if ( $search ) { $args['search']='*'.str_replace('*','',$search).'*'; $args['search_columns']=[ 'display_name','user_login' ]; }
		$role=sanitize_key( (string) ( $q['role'] ?? '' ) ); if ( $role && isset( wp_roles()->roles[$role] ) ) $args['role']=$role;
		if ( Site_Owner_Service::owner_id() && ! Site_Owner_Service::is_owner() ) $args['exclude']=[ Site_Owner_Service::owner_id() ];
		$users=get_users( $args ); $items=[]; foreach ( array_slice($users,0,20) as $u ) if ( $this->eligible_user( (int)$u->ID ) ) $items[]=$this->project_person( (int)$u->ID );
		return $this->response( [ 'items'=>$items,'page'=>$page,'hasNext'=>count($users)>20,'roles'=>Team_Workspace_Service::role_catalog() ] );
	}
	public function detail( \WP_REST_Request $r ): \WP_REST_Response {
		if ( ! $this->permission( $r ) ) return $this->response( $this->error( 'User groups are unavailable.',403 ) );
		$group=$this->find( (string)$r['id'] ); if ( is_wp_error($group) ) return $this->response($group);
		$q=$r->get_query_params(); $page=max(1,min(25,absint($q['page']??1))); $set=$this->candidates($group['id'],substr(sanitize_text_field((string)($q['search']??'')),0,80),sanitize_key((string)($q['role']??'')));
		$ids=array_values(array_filter($set['ids'],fn($id)=>$this->eligible_user($id))); $items=array_map([$this,'project_person'],array_slice($ids,($page-1)*20,20));
		return $this->response([ 'group'=>$group,'items'=>$items,'page'=>$page,'hasNext'=>count($ids)>$page*20,'count'=>count($ids),'limited'=>$set['limited'],'groups'=>$this->catalog() ]);
	}
	private function locked( callable $work ) {
		if ( ! add_option(self::LOCK,time()+30,'',false) ) { if ((int)get_option(self::LOCK,0)>time())return $this->error('Another group change is saving. Try again.',409);delete_option(self::LOCK);if(!add_option(self::LOCK,time()+30,'',false))return $this->error('Another group change is saving.',409); }
		try { return $work(); } finally { delete_option(self::LOCK); }
	}
	public function create( \WP_REST_Request $r ): \WP_REST_Response {
		if(! $this->permission($r))return $this->response($this->error('User groups are unavailable.',403));$body=$r->get_json_params();$name=is_array($body)&&is_string($body['name']??null)?trim(sanitize_text_field($body['name'])):'';
		if(! $name||preg_match_all('/./us',$name)>100)return $this->response($this->error('Enter a group name up to 100 characters.'));
		return $this->response($this->locked(function()use($name){$groups=$this->definitions();if(count($groups)>=50)return $this->error('Use an existing group; this workspace has 50 custom groups.');foreach($this->catalog()as$g)if(0===strcasecmp($g['name'],$name))return $this->error('This group name already exists.');$group=['id'=>'g-'.substr(str_replace('-','',wp_generate_uuid4()),0,16),'name'=>$name];$groups[]=$group;if(!update_option(self::OPTION,$groups,false))return $this->error('The new group could not be confirmed. Refresh before retrying.',409);return $this->find($group['id']);}));
	}
	public function change( \WP_REST_Request $r ): \WP_REST_Response {
		if(! $this->permission($r))return $this->response($this->error('User groups are unavailable.',403));$body=$r->get_json_params();if(!is_array($body))return $this->response($this->error('Choose a group task.'));$id=(string)$r['id'];
		return $this->response($this->locked(function()use($id,$body){$g=$this->find($id);if(is_wp_error($g))return $g;if($g['smart'])return $this->error('This audience follows each person’s verification and notification choices. It cannot be edited.',403);if(!is_string($body['expected']??null)||!hash_equals($g['expected'],$body['expected']))return $this->error('The group changed. Refresh before editing.',409);
			if('rename'===($body['operation']??'')){$name=is_string($body['name']??null)?trim(sanitize_text_field($body['name'])):'';if(!$name||preg_match_all('/./us',$name)>100)return $this->error('Enter a group name up to 100 characters.');$groups=$this->definitions();foreach($this->catalog()as$item)if($item['id']!==$id&&0===strcasecmp($item['name'],$name))return $this->error('This group name already exists.');foreach($groups as&$item)if($item['id']===$id)$item['name']=$name;unset($item);update_option(self::OPTION,$groups,false);$after=$this->find($id);return !is_wp_error($after)&&$after['name']===$name?$after:$this->error('Group rename could not be confirmed.',409);}
			$op=$body['operation']??'';$items=$body['people']??[];if(!in_array($op,['add','remove','move'],true)||!is_array($items)||!count($items)||count($items)>20)return $this->error('Select 1 to 20 people from this page.');$target=null;if('move'===$op){$target=$this->find((string)($body['target']??''));if(is_wp_error($target)||$target['smart']||$target['id']===$id||!hash_equals($target['expected'],(string)($body['targetExpected']??'')))return $this->error('Choose a current custom destination group.',409);}
			$before=[];foreach($items as$item){$uid=is_array($item)&&is_int($item['id']??null)?$item['id']:0;if(!$this->eligible_user($uid)||isset($before[$uid]))return $this->error('An account is unavailable. Refresh the group.',403);if(!is_string($item['expected']??null)||!hash_equals(self::member_proof($uid),$item['expected']))return $this->error('Someone’s group membership changed. Refresh before saving.',409);$members=self::memberships($uid);if('add'!==$op&&!in_array($id,$members,true))return $this->error('Someone left this group. Refresh before moving them.',409);if('add'===$op&&!in_array($id,$members,true)&&count($members)>=20)return $this->error('An account already belongs to 20 groups. Remove an old membership first.');$before[$uid]=$members;}
			$done=[];foreach($before as$uid=>$members){$next='add'===$op?array_values(array_unique([...$members,$id])):array_values(array_diff($members,[$id]));if($target)$next=array_values(array_unique([...$next,$target['id']]));if($next!==$members)update_user_meta($uid,self::META,$next);if(self::memberships($uid)!==$next){update_user_meta($uid,self::META,$members);foreach($done as$restore=>$old)update_user_meta($restore,self::META,$old);return $this->error('The membership save could not be confirmed. Refresh all selected people.',409);}$done[$uid]=$members;}
			return ['ok'=>true,'count'=>count($done),'group'=>$this->find($id)];
		}));
	}
}
