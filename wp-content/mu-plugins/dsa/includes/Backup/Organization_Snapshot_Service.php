<?php
namespace DSA\Backup;

use DSA\Access\Backup_Workspace_Service;
use DSA\Access\Navigation_Workspace_Service;
use DSA\Access\Tag_Group_Service;
use DSA\Access\User_Group_Service;

if ( ! defined( 'ABSPATH' ) ) exit;

/** Typed projections of existing organization owners, never options with these names. */
final class Organization_Snapshot_Service {
	public const TAGS = 'kiwe.organize.tags';
	public const AUDIENCES = 'kiwe.organize.audiences';
	public const NAVIGATION = 'kiwe.organize.navigation';
	public const MAX_RECORDS = 1000;
	private const LABELS = [ self::TAGS=>'Tag folders and assignments',self::AUDIENCES=>'User groups and memberships',self::NAVIGATION=>'Navigation visibility and order' ];

	public static function handles( string $name ): bool { return isset( self::LABELS[$name] ); }
	public static function labels(): array { return self::LABELS; }
	public static function groups(): array {
		$out = [];
		foreach ( [ 'Tags'=>self::TAGS,'User groups'=>self::AUDIENCES,'Navigation'=>self::NAVIGATION ] as $group=>$name ) if ( self::allowed( $name ) ) $out[$group] = [ $name ];
		return $out;
	}
	public static function descriptions(): array {
		return [ 'Tags'=>'Folder names and assignments of existing native tags.','User groups'=>'Custom group names and memberships of existing accounts. Consent and verification stay unchanged.','Navigation'=>'Visibility, order and link limits of existing menu groups. Menu links and theme placements are excluded.' ];
	}
	public static function allowed( string $name ): bool {
		if ( ! Backup_Workspace_Service::allowed() ) return false;
		if ( self::AUDIENCES === $name ) return User_Group_Service::allowed();
		if ( self::NAVIGATION === $name ) return current_user_can( 'kiwe_manage_navigation' );
		if ( self::TAGS === $name ) return ( new Tag_Group_Service() )->can_manage_kind( 'post' );
		return false;
	}
	private static function require_access( string $name ): void {
		if ( ! self::allowed( $name ) ) throw new Archive_Error( 'Your account cannot back up or restore this organization task.' );
	}
	private static function fail( string $message ): void { throw new Archive_Error( $message ); }
	private static function keys( $value, array $keys ): bool { return is_array( $value ) && count( $value ) === count( $keys ) && ! array_diff( array_keys( $value ),$keys ); }
	private static function list( $value, int $limit ): bool { return is_array( $value ) && array_is_list( $value ) && count( $value ) <= $limit; }
	private static function name( $value ): bool { return is_string( $value ) && '' !== trim( $value ) && sanitize_text_field( $value ) === $value && false !== ( $length = preg_match_all( '/./us',$value ) ) && $length <= 100; }
	private static function sort( array $rows ): array { usort( $rows,static fn( $a,$b )=>$a['id'] <=> $b['id'] ); return $rows; }
	private static function definitions( string $name ): array {
		$option = self::TAGS === $name ? Tag_Group_Service::OPTION : User_Group_Service::OPTION;
		$raw = get_option( $option,[] );
		self::validate_groups( $name,$raw );
		return $raw;
	}
	private static function validate_groups( string $name, $groups ): void {
		if ( ! self::list( $groups,self::TAGS === $name ? 100 : 50 ) ) self::fail( 'The saved organization groups exceed this recovery format.' );
		$ids = []; $names = [];
		foreach ( $groups as $group ) {
			$tag = self::TAGS === $name;
			if ( ! self::keys( $group,$tag ? [ 'id','kind','name' ] : [ 'id','name' ] ) || ! self::name( $group['name'] ) || ( $tag ? ! is_int( $group['id'] ) || $group['id'] < 1 || ! in_array( $group['kind'],[ 'post','product' ],true ) : ! is_string( $group['id'] ) || ! preg_match( '/^g-[a-f0-9]{16}$/D',$group['id'] ) ) ) self::fail( 'A saved organization group is unsupported.' );
			$key = ( $tag ? $group['kind'].'|' : '' ).strtolower( $group['name'] );
			if ( isset( $ids[$group['id']] ) || isset( $names[$key] ) || ( ! $tag && in_array( strtolower( $group['name'] ),[ 'sms','email','whatsapp','browser / pwa' ],true ) ) ) self::fail( 'Organization group identities or names conflict.' );
			if ( $tag && ! ( new Tag_Group_Service() )->can_manage_kind( $group['kind'] ) ) self::fail( 'A saved tag folder needs its connected taxonomy and native management permission.' );
			$ids[$group['id']] = true; $names[$key] = true;
		}
	}
	private static function tag_identity( int $id, string $kind ): string {
		$taxonomy = 'post' === $kind ? 'post_tag' : 'product_tag'; $term = get_term( $id,$taxonomy );
		if ( ! $term || is_wp_error( $term ) || ! ( new Tag_Group_Service() )->can_manage_kind( $kind ) || ! current_user_can( 'edit_term',$id ) ) self::fail( 'A referenced tag is missing or no longer editable. Recover its native tag first.' );
		return hash( 'sha256',(string) wp_json_encode( [ $id,(int) $term->term_taxonomy_id,$taxonomy,(string) $term->slug ] ) );
	}
	private static function user_identity( int $id ): string {
		$user = get_userdata( $id );
		if ( ! $user || ! ( new User_Group_Service() )->can_edit_account( $id ) ) self::fail( 'A referenced account is missing or no longer editable. Recover its native account first.' );
		$roles = array_values( $user->roles ); sort( $roles,SORT_STRING );
		return hash( 'sha256',(string) wp_json_encode( [ $id,(string) $user->user_login,(string) $user->user_registered,(string) $user->user_email,$roles ] ) );
	}
	private static function menu_reference( int $id ): array {
		$menu = wp_get_nav_menu_object( $id );
		$items = $menu ? self::menu_items( $id ) : false;
		if ( ! $menu || false === $items || is_wp_error( $items ) || count( $items ) > Navigation_Workspace_Service::ITEM_LIMIT ) self::fail( 'A referenced menu is missing or exceeds the supported link limit. Recover its native menu first.' );
		$rows = [];
		foreach ( $items as $item ) $rows[] = [ (int) $item->ID,(int) $item->menu_order,(int) $item->menu_item_parent,(string) $item->type,(string) $item->object,(int) $item->object_id,(string) $item->title,(string) $item->post_title,(string) $item->description,(string) $item->url,(string) $item->target,(string) $item->attr_title,(string) $item->xfn,array_values( $item->classes ) ];
		return [ 'id'=>$id,'identity'=>hash( 'sha256',(string) wp_json_encode( [ $id,(string) $menu->slug,(string) $menu->name,$rows ] ) ) ];
	}
	private static function menu_items( int $id ) { return wp_get_nav_menu_items( $id,[ 'output'=>'OBJECT','post_status'=>'any','nopaging'=>false,'posts_per_page'=>Navigation_Workspace_Service::ITEM_LIMIT+1,'numberposts'=>Navigation_Workspace_Service::ITEM_LIMIT+1,'orderby'=>'menu_order','order'=>'ASC' ] ); }

	/** Bounded native queries; an oversized collection fails rather than truncating it. */
	public static function read( string $name ): array {
		self::require_access( $name );
		if ( self::NAVIGATION === $name ) {
			$policy = ( new Navigation_Workspace_Service() )->backup_policy(); $refs = [];
			$ids = array_values( array_unique( [ ...$policy['ids'],...array_map( 'intval',array_keys( $policy['limits'] ) ) ] ) ); sort( $ids,SORT_NUMERIC );
			foreach ( $ids as $id ) $refs[] = self::menu_reference( $id );
			$value = [ 'schema'=>'kiwe.navigation-policy.v1',...$policy,'menus'=>$refs ];
		} else {
			$groups = self::definitions( $name ); $rows = [];
			if ( self::TAGS === $name ) {
				foreach ( [ 'post'=>'post_tag','product'=>'product_tag' ] as $kind=>$taxonomy ) {
					if ( ! taxonomy_exists( $taxonomy ) ) continue;
					$terms = get_terms( [ 'taxonomy'=>$taxonomy,'hide_empty'=>false,'number'=>self::MAX_RECORDS+1,'orderby'=>'term_id','order'=>'ASC','meta_query'=>[ [ 'key'=>Tag_Group_Service::META,'compare'=>'EXISTS' ] ] ] );
					if ( is_wp_error( $terms ) || count( $terms )+count( $rows ) > self::MAX_RECORDS ) self::fail( 'Tag assignments exceed the 1,000-record recovery limit or cannot be read.' );
					foreach ( $terms as $term ) {
						$values = get_term_meta( (int) $term->term_id,Tag_Group_Service::META,false );
						if ( count( $values ) !== 1 || ! is_scalar( $values[0] ) || ! preg_match( '/^[1-9][0-9]*$/D',(string) $values[0] ) ) self::fail( 'A native tag folder assignment is unsupported.' );
						$rows[] = [ 'id'=>(int) $term->term_id,'kind'=>$kind,'identity'=>self::tag_identity( (int) $term->term_id,$kind ),'group'=>(int) $values[0] ];
					}
				}
			} else {
				$ids = get_users( [ 'blog_id'=>get_current_blog_id(),'fields'=>'ID','number'=>self::MAX_RECORDS+1,'orderby'=>'ID','order'=>'ASC','count_total'=>false,'meta_key'=>User_Group_Service::META,'meta_compare'=>'EXISTS' ] );
				if ( count( $ids ) > self::MAX_RECORDS ) self::fail( 'User memberships exceed the 1,000-account recovery limit.' );
				if ( function_exists( 'cache_users' ) ) cache_users( $ids );
				if ( function_exists( 'update_meta_cache' ) ) update_meta_cache( 'user',$ids );
				foreach ( $ids as $id ) {
					$values = get_user_meta( (int) $id,User_Group_Service::META,false );
					if ( count( $values ) !== 1 || ! is_array( $values[0] ) ) self::fail( 'A native user group membership is unsupported.' );
					$rows[] = [ 'id'=>(int) $id,'identity'=>self::user_identity( (int) $id ),'groups'=>$values[0] ];
				}
			}
			$value = [ 'schema'=>self::TAGS === $name ? 'kiwe.tag-folders.v1' : 'kiwe.user-audiences.v1','groups'=>$groups,'records'=>self::sort( $rows ) ];
		}
		self::validate( $name,$value ); return $value;
	}

	public static function validate( string $name, $value ): void {
		self::require_access( $name );
		if ( self::NAVIGATION === $name ) {
			if ( ! self::keys( $value,[ 'schema','ids','limits','menus' ] ) || 'kiwe.navigation-policy.v1' !== $value['schema'] || ! self::list( $value['ids'],Navigation_Workspace_Service::GROUP_LIMIT ) || ! is_array( $value['limits'] ) || count( $value['limits'] ) > 100 || ! self::list( $value['menus'],100 ) || count( array_unique( $value['ids'] ) ) !== count( $value['ids'] ) ) self::fail( 'The saved navigation policy is unsupported.' );
			$ids = []; foreach ( $value['ids'] as $id ) { if ( ! is_int( $id ) || $id < 1 ) self::fail( 'The saved menu selection is invalid.' ); $ids[$id] = true; }
			foreach ( $value['limits'] as $id=>$limit ) { if ( ! ctype_digit( (string) $id ) || (int) $id < 1 || ! is_int( $limit ) || $limit < 1 || $limit > Navigation_Workspace_Service::MAX_VISIBLE_ITEMS ) self::fail( 'The saved menu link limits are invalid.' ); $ids[(int) $id] = true; }
			$seen = [];
			foreach ( $value['menus'] as $menu ) {
				if ( ! self::keys( $menu,[ 'id','identity' ] ) || ! is_int( $menu['id'] ) || ! isset( $ids[$menu['id']] ) || isset( $seen[$menu['id']] ) || ! is_string( $menu['identity'] ) || ! preg_match( '/^[a-f0-9]{64}$/D',$menu['identity'] ) || ! hash_equals( self::menu_reference( $menu['id'] )['identity'],$menu['identity'] ) ) self::fail( 'A menu changed since this snapshot. Recover or review the native menu before this policy.' );
				$seen[$menu['id']] = true;
				if ( isset( $value['limits'][$menu['id']] ) && count( self::menu_items( $menu['id'] ) ) > $value['limits'][$menu['id']] ) self::fail( 'A saved link limit would hide existing menu links.' );
			}
			if ( count( $seen ) !== count( $ids ) ) self::fail( 'The saved navigation dependency list is incomplete.' );
			return;
		}
		$tag = self::TAGS === $name;
		if ( ! self::keys( $value,[ 'schema','groups','records' ] ) || ( $tag ? 'kiwe.tag-folders.v1' : 'kiwe.user-audiences.v1' ) !== $value['schema'] || ! self::list( $value['records'],self::MAX_RECORDS ) ) self::fail( 'The saved organization state is unsupported.' );
		self::validate_groups( $name,$value['groups'] );
		$groups = array_column( $value['groups'],null,'id' ); $seen = [];
		foreach ( $value['records'] as $row ) {
			if ( ! self::keys( $row,$tag ? [ 'id','kind','identity','group' ] : [ 'id','identity','groups' ] ) || ! is_int( $row['id'] ) || $row['id'] < 1 || isset( $seen[$row['id']] ) || ! is_string( $row['identity'] ) || ! preg_match( '/^[a-f0-9]{64}$/D',$row['identity'] ) ) self::fail( 'A saved organization record is invalid.' );
			$seen[$row['id']] = true;
			if ( $tag ) {
				if ( ! in_array( $row['kind'],[ 'post','product' ],true ) || ! is_int( $row['group'] ) || ! isset( $groups[$row['group']] ) || $groups[$row['group']]['kind'] !== $row['kind'] || ! hash_equals( self::tag_identity( $row['id'],$row['kind'] ),$row['identity'] ) ) self::fail( 'A saved tag identity or folder dependency changed.' );
			} else {
				if ( ! self::list( $row['groups'],50 ) || count( array_unique( $row['groups'] ) ) !== count( $row['groups'] ) ) self::fail( 'A saved audience membership is invalid.' );
				foreach ( $row['groups'] as $id ) if ( ! is_string( $id ) || ! isset( $groups[$id] ) ) self::fail( 'A saved user group dependency is missing.' );
				if ( ! hash_equals( self::user_identity( $row['id'] ),$row['identity'] ) ) self::fail( 'A saved account identity or role changed. Review its native account before recovery.' );
			}
		}
	}

	/** Preserve groups and accounts added after capture; restore only saved assignments. */
	public static function merge( string $name, array $saved ): array {
		self::validate( $name,$saved );
		if ( self::NAVIGATION === $name ) return $saved;
		$current = self::read( $name ); $groups = array_column( $current['groups'],null,'id' ); $rows = array_column( $current['records'],null,'id' );
		foreach ( $saved['groups'] as $group ) {
			if ( self::TAGS === $name && isset( $groups[$group['id']] ) && $groups[$group['id']]['kind'] !== $group['kind'] ) self::fail( 'A saved folder ID now belongs to another taxonomy.' );
			$groups[$group['id']] = $group;
		}
		$saved_ids = array_column( $saved['groups'],'id' );
		foreach ( $saved['records'] as $row ) {
			if ( self::AUDIENCES === $name ) $row['groups'] = array_values( array_unique( [ ...array_diff( $rows[$row['id']]['groups'] ?? [],$saved_ids ),...$row['groups'] ] ) );
			$rows[$row['id']] = $row;
		}
		$next = [ 'schema'=>$saved['schema'],'groups'=>array_values( $groups ),'records'=>self::sort( array_values( $rows ) ) ];
		self::validate( $name,$next ); return $next;
	}
	public static function merge_options( array $options ): array {
		foreach ( $options as $name=>&$setting ) if ( self::handles( $name ) ) {
			if ( ! self::keys( $setting,[ 'exists','value' ] ) || true !== $setting['exists'] || ! is_array( $setting['value'] ) ) self::fail( 'Organization snapshots require their complete typed projection.' );
			$setting['value'] = self::merge( $name,$setting['value'] );
		}
		unset( $setting ); return $options;
	}
	public static function validate_options( array $options ): void { foreach ( $options as $name=>$setting ) if ( self::handles( $name ) ) { if ( true !== ( $setting['exists'] ?? null ) || ! is_array( $setting['value'] ?? null ) ) self::fail( 'The organization projection is missing.' ); self::validate( $name,$setting['value'] ); } }

	/** Authenticated review only: show every changed native assignment. */
	public static function review( array $before, array $next ): array {
		$out = [];
		foreach ( $next as $name=>$setting ) {
			if ( ! self::handles( $name ) ) continue;
			$old = $before[$name]['value']; $value = $setting['value']; $changes = [];
			if ( self::NAVIGATION === $name ) {
				$describe = static function ( array $state,int $id ): string { $position = array_search( $id,$state['ids'],true ); return ( false === $position ? 'Hidden' : 'Position '.( $position+1 ) ).' · '.( $state['limits'][$id] ?? 'Default' ).' links'; };
				foreach ( array_unique( [ ...$old['ids'],...$value['ids'],...array_map( 'intval',array_keys( $old['limits'] ) ),...array_map( 'intval',array_keys( $value['limits'] ) ) ] ) as $id ) {
					$from = $describe( $old,$id ); $to = $describe( $value,$id );
					if ( $from !== $to ) $changes[] = [ 'label'=>sanitize_text_field( (string) wp_get_nav_menu_object( $id )->name ),'before'=>$from,'after'=>$to ];
				}
			} else {
				$groups = array_column( $old['groups'],null,'id' ); $target_groups = array_column( $value['groups'],null,'id' );
				foreach ( $target_groups as $id=>$group ) if ( ! isset( $groups[$id] ) || $groups[$id]['name'] !== $group['name'] ) $changes[] = [ 'label'=>'Group','before'=>$groups[$id]['name'] ?? 'Not present','after'=>$group['name'] ];
				$rows = array_column( $old['records'],null,'id' );
				foreach ( $value['records'] as $row ) {
					$prior = $rows[$row['id']] ?? null;
					if ( wp_json_encode( $row ) === wp_json_encode( $prior ) ) continue;
					$tag = self::TAGS === $name; $from = $prior ? ( $tag ? [ $prior['group'] ] : $prior['groups'] ) : []; $to = $tag ? [ $row['group'] ] : $row['groups'];
					$labels = static fn( array $ids,array $definitions ): string=>implode( ', ',array_map( static fn( $id )=>$definitions[$id]['name'],$ids ) ) ?: 'None';
					$label = $tag ? get_term( $row['id'],'post' === $row['kind'] ? 'post_tag' : 'product_tag' )->name : get_userdata( $row['id'] )->display_name;
					$changes[] = [ 'label'=>sanitize_text_field( (string) $label ),'before'=>$labels( $from,$groups ),'after'=>$labels( $to,$target_groups ) ];
				}
			}
			$out[] = [ 'label'=>self::LABELS[$name],'changes'=>$changes ];
		}
		return $out;
	}

	/** Exact reviewed projection, also used by the parent's retained before-copy rollback. */
	public static function write( string $name, array $setting ): void {
		self::validate_options( [ $name=>$setting ] ); $value = $setting['value'];
		if ( self::NAVIGATION === $name ) {
			$native = new Navigation_Workspace_Service(); $result = $native->configure_groups( $value['ids'],$value['limits'],$native->group_expected() );
			if ( is_wp_error( $result ) || true !== $result ) self::fail( 'The native navigation policy could not be confirmed.' );
			return;
		}
		$tag = self::TAGS === $name; $old = [];
		// A failed native write can leave a dangling membership. Enumerate native
		// IDs without requiring that partial state to validate as a new backup.
		if ( $tag ) {
			foreach ( [ 'post'=>'post_tag','product'=>'product_tag' ] as $kind=>$taxonomy ) {
				if ( ! taxonomy_exists( $taxonomy ) ) continue;
				$terms = get_terms( [ 'taxonomy'=>$taxonomy,'hide_empty'=>false,'number'=>self::MAX_RECORDS+1,'orderby'=>'term_id','order'=>'ASC','meta_query'=>[ [ 'key'=>Tag_Group_Service::META,'compare'=>'EXISTS' ] ] ] );
				if ( is_wp_error( $terms ) || count( $terms )+count( $old ) > self::MAX_RECORDS ) self::fail( 'The native tag recovery inventory cannot be confirmed.' );
				foreach ( $terms as $term ) { $id = (int) $term->term_id; self::tag_identity( $id,$kind ); $old[$id] = [ 'kind'=>$kind ]; }
			}
		} else {
			$ids = get_users( [ 'blog_id'=>get_current_blog_id(),'fields'=>'ID','number'=>self::MAX_RECORDS+1,'orderby'=>'ID','order'=>'ASC','count_total'=>false,'meta_key'=>User_Group_Service::META,'meta_compare'=>'EXISTS' ] );
			if ( count( $ids ) > self::MAX_RECORDS ) self::fail( 'The native account recovery inventory cannot be confirmed.' );
			foreach ( $ids as $id ) { self::user_identity( (int) $id ); $old[(int) $id] = []; }
		}
		$option = $tag ? Tag_Group_Service::OPTION : User_Group_Service::OPTION;
		$groups = $value['groups'];
		if ( wp_json_encode( get_option( $option,[] ) ) !== wp_json_encode( $groups ) ) update_option( $option,$groups,false );
		if ( wp_json_encode( self::definitions( $name ) ) !== wp_json_encode( $groups ) ) self::fail( 'The native organization groups could not be confirmed.' );
		$target = array_column( $value['records'],null,'id' );
		foreach ( array_unique( [ ...array_keys( $old ),...array_keys( $target ) ] ) as $id ) {
			$row = $target[$id] ?? null;
			if ( $tag ) {
				self::tag_identity( $id,( $row ?? $old[$id] )['kind'] );
				if ( $row ) update_term_meta( $id,Tag_Group_Service::META,(string) $row['group'] ); else delete_term_meta( $id,Tag_Group_Service::META );
				$read = get_term_meta( $id,Tag_Group_Service::META,false );
				if ( $row ? count( $read ) !== 1 || (string) $read[0] !== (string) $row['group'] : [] !== $read ) self::fail( 'The native tag assignment could not be confirmed.' );
			} else {
				self::user_identity( $id );
				if ( $row ) update_user_meta( $id,User_Group_Service::META,$row['groups'] ); else delete_user_meta( $id,User_Group_Service::META );
				$read = get_user_meta( $id,User_Group_Service::META,false );
				if ( $row ? count( $read ) !== 1 || $read[0] !== $row['groups'] : [] !== $read ) self::fail( 'The native audience membership could not be confirmed.' );
			}
		}
	}
}
