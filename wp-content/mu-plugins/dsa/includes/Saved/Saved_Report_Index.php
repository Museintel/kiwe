<?php
namespace DSA\Saved;

use DSA\Apps\Store_App_Service;
use DSA\Utilities\Content_Visibility;

if ( ! defined( 'ABSPATH' ) ) exit;

/** Disposable, private projections. Native user metadata remains authoritative. */
final class Saved_Report_Index {
	private const STATE = 0;
	private const USER = 1;
	private const OBJECT = 2;
	private const REFERENCE = 3;
	private const DIRTY = 4;
	private const ACK = 5;
	private const PAGE = 20;
	private const MAX_ITEMS = 1000;
	private string $table;
	private string $scope;
	private bool $store;
	private ?Saved_DB_Session $owner = null;
	private array $native = [];
	private array $sources = [];

	public function __construct() {
		global $wpdb;
		$this->table = $wpdb->prefix . 'dsa_saved_report_v2';
		$this->store = Store_App_Service::enabled();
		$actor = wp_get_current_user();
		$caps = (array) $actor->allcaps; ksort( $caps );
		$this->scope = hash( 'sha256', serialize( [ get_current_blog_id(), $wpdb->usermeta, (int) $actor->ID, $caps, $this->store ] ) );
	}

	/** Explicit privileged preparation only; never called from a public request. */
	public function prepare(): bool {
		if ( ! current_user_can( 'manage_options' ) ) return false;
		global $wpdb;
		$sql = "CREATE TABLE IF NOT EXISTS {$this->table} (
			scope char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
			kind tinyint unsigned NOT NULL,
			owner bigint unsigned NOT NULL DEFAULT 0,
			object_key char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
			rank_value bigint NOT NULL DEFAULT 0,
			native_id bigint unsigned NOT NULL DEFAULT 0,
			parent_id bigint unsigned NOT NULL DEFAULT 0,
			data longtext NOT NULL,
			PRIMARY KEY (scope,kind,owner,object_key),
			KEY ranked (scope,kind,rank_value,owner,object_key),
			KEY object_refs (scope,kind,object_key,owner),
			KEY native_refs (scope,kind,native_id,owner),
			KEY parent_refs (scope,kind,parent_id,owner)
			,KEY stale_scopes (kind,rank_value,scope)
		) ENGINE=InnoDB " . $wpdb->get_charset_collate();
		if ( $wpdb->query( $sql ) === false ) return false;
		$ddl = $wpdb->get_row( "SHOW CREATE TABLE {$this->table}", ARRAY_A );
		$definition = is_array( $ddl ) ? implode( ' ', $ddl ) : '';
		foreach ( [ 'ENGINE=InnoDB', 'object_refs', 'native_refs', 'parent_refs', 'stale_scopes', 'rank_value', 'PRIMARY KEY' ] as $required ) if ( stripos( $definition, $required ) === false ) return false;
		return Saved_Report_Journal::put( Saved_Report_Journal::READY, '1' );
	}

	public function refresh(): bool {
		if ( ! current_user_can( 'manage_options' ) || ! $this->prepare() ) return false;
		$claim = Saved_DB_Session::claim( 'report:' . $this->scope );
		if ( empty( $claim['owner'] ) ) return false;
		$this->owner = $claim['owner'];
		try { $state = $this->initial(); $state['phase'] = 'clear'; $this->save_state( $state ); }
		catch ( \Throwable $error ) { return false; }
		finally { $this->owner->release(); $this->owner = null; }
		$this->schedule();
		return true;
	}

	public function snapshot(): array {
		if ( ! current_user_can( 'manage_options' ) ) return [ 'status'=>'unavailable' ];
		if ( ! Saved_Report_Journal::enabled() ) return [ 'status'=>'needs_preparation' ];
		$result = $this->step( 0.2 );
		if ( $result['status'] !== 'ready' ) {
			if ( $result['status'] === 'updating' ) $this->schedule();
			return $result;
		}
		try {
			$state = $this->state();
			if ( ! $state || $state['phase'] !== 'ready' || Saved_Report_Journal::value( Saved_Report_Journal::EPOCH ) !== $state['epoch'] ) { $this->schedule(); return [ 'status'=>'updating' ]; }
			$objects = [];
			foreach ( $state['top'] as $ranked ) {
				$item = $this->display_object( $ranked, true );
				if ( ! $item ) { $this->schedule(); return [ 'status'=>'updating' ]; }
				$objects[] = $item + [ 'user_count'=>$ranked['user_count'] ];
			}
			global $wpdb;
			$rows = $this->rows( $wpdb->prepare( "SELECT r.owner,r.rank_value,r.native_id,r.parent_id FROM {$this->table} r INNER JOIN {$wpdb->users} u ON u.ID=r.owner WHERE r.scope=%s AND r.kind=%d AND (r.native_id>0 OR r.parent_id>0) ORDER BY r.rank_value ASC,r.owner ASC LIMIT 250", $this->scope, self::USER ) );
			$users = [];
			foreach ( $rows as $row ) {
				$user = get_userdata( (int) $row['owner'] );
				if ( ! $user ) continue;
				$users[] = [ 'id'=>(int) $user->ID, 'name'=>$user->display_name ?: $user->user_login, 'email'=>$user->user_email, 'wishlist'=>(int) $row['native_id'], 'bookmark'=>(int) $row['parent_id'], 'latest'=>-(int) $row['rank_value'] ];
			}
			if ( Saved_Report_Journal::value( Saved_Report_Journal::EPOCH ) !== $state['epoch'] ) { $this->schedule(); return [ 'status'=>'updating' ]; }
			return [ 'status'=>'ready', 'objects'=>$objects, 'users'=>$users, 'totals'=>$state['totals'], 'asOf'=>$state['completed'] ];
		} catch ( \Throwable $error ) { return [ 'status'=>'unavailable' ]; }
	}

	/** Each pass caps work units and checks time between native operations, not inside SQL. */
	public function step( float $seconds = 1.0 ): array {
		if ( ! current_user_can( 'manage_options' ) ) return [ 'status'=>'unavailable' ];
		if ( ! Saved_Report_Journal::enabled() ) return [ 'status'=>'needs_preparation' ];
		$claim = Saved_DB_Session::claim( 'report:' . $this->scope );
		if ( empty( $claim['owner'] ) ) return [ 'status'=>$claim['status'] === 409 ? 'updating' : 'unavailable' ];
		$this->owner = $claim['owner'];
		$start = microtime( true ); $units = 0;
		try {
			$state = $this->state() ?? $this->initial();
			if ( ! empty( $state['error'] ) ) return [ 'status'=>'unavailable' ];
			while ( ++$units <= self::PAGE && microtime( true ) - $start < max( 0.01, min( 1.0, $seconds ) ) ) {
				if ( ! $this->owner->owns() ) throw new \RuntimeException( 'Report ownership expired.' );
				$this->native = []; $this->sources = [];
				if ( $state['phase'] === 'ready' ) {
					if ( $state['epoch'] === Saved_Report_Journal::value( Saved_Report_Journal::EPOCH ) ) { $this->save_state( $state ); break; }
					$this->begin_journal( $state ); $this->save_state( $state );
				} elseif ( ! $this->unit( $state ) ) break;
			}
			$this->retire_old_scope();
			return [ 'status'=>$state['phase'] === 'ready' ? 'ready' : 'updating', 'phase'=>$state['phase'], 'units'=>min( $units, self::PAGE ) ];
		} catch ( \Throwable $error ) {
			global $wpdb; $sql_error = $wpdb->last_error; $wpdb->query( 'ROLLBACK' );
			do_action( 'dsa_saved_report_failed', $error, $sql_error );
			return [ 'status'=>'unavailable' ];
		} finally { $this->owner->release(); $this->owner = null; }
	}

	public static function scheduled_step( int $actor_id, int $store ): void {
		$previous = get_current_user_id();
		wp_set_current_user( $actor_id );
		try {
			if ( ! current_user_can( 'manage_options' ) || Store_App_Service::enabled() !== (bool) $store ) return;
			$report = new self(); $result = $report->step();
			if ( $result['status'] === 'updating' ) $report->schedule();
		} finally { wp_set_current_user( $previous ); }
	}
	private function schedule(): void {
		if ( ! current_user_can( 'manage_options' ) ) return;
		$args = [ get_current_user_id(), (int) $this->store ];
		if ( ! wp_next_scheduled( 'dsa_saved_report_step_v2', $args ) ) wp_schedule_single_event( time() + 5, 'dsa_saved_report_step_v2', $args );
	}

	/** Privileged demand only: discard at most 500 rows from one unused actor cache. */
	private function retire_old_scope(): void {
		global $wpdb;
		$cutoff = time() - 7 * DAY_IN_SECONDS;
		$rows = $this->rows( $wpdb->prepare( "SELECT scope FROM {$this->table} WHERE kind=%d AND owner=0 AND object_key='' AND rank_value<%d AND scope<>%s ORDER BY rank_value ASC LIMIT 1", self::STATE, $cutoff, $this->scope ) );
		if ( ! $rows ) return;
		$scope = $rows[0]['scope']; $claim = Saved_DB_Session::claim( 'report:' . $scope );
		if ( empty( $claim['owner'] ) ) return;
		$owner = $claim['owner'];
		try {
			$rows = $this->rows( $wpdb->prepare( "SELECT rank_value FROM {$this->table} WHERE scope=%s AND kind=%d AND owner=0 AND object_key='' LIMIT 1", $scope, self::STATE ) );
			if ( ! $rows || (int) $rows[0]['rank_value'] >= $cutoff ) return;
			// A returning actor must rebuild a retired projection, never trust partial counters.
			$reset = $this->initial(); $reset['phase'] = 'clear';
			$sql = $wpdb->prepare( "UPDATE {$this->table} SET data=%s WHERE scope=%s AND kind=%d AND owner=0 AND object_key='' AND " . $owner->fence(), wp_json_encode( $reset ), $scope, self::STATE );
			if ( $wpdb->query( $sql ) === false || ! $owner->owns() ) return;
			$n = $wpdb->query( $wpdb->prepare( "DELETE FROM {$this->table} WHERE scope=%s AND kind<>%d AND " . $owner->fence() . ' LIMIT 500', $scope, self::STATE ) );
			if ( $n !== false && $n < 500 ) $wpdb->query( $wpdb->prepare( "DELETE FROM {$this->table} WHERE scope=%s AND kind=%d AND owner=0 AND object_key='' AND " . $owner->fence(), $scope, self::STATE ) );
		} finally { $owner->release(); }
	}

	private function initial(): array {
		return [ 'phase'=>'scan', 'cursor'=>0, 'journal'=>0, 'epoch'=>Saved_Report_Journal::value( Saved_Report_Journal::EPOCH ) ?? '', 'totals'=>[ 'users'=>0, 'wishlist_users'=>0, 'bookmark_users'=>0, 'objects'=>0 ], 'rankCursor'=>'', 'heap'=>[], 'top'=>[], 'completed'=>0 ];
	}
	private function begin_journal( array &$state ): void {
		$epoch = Saved_Report_Journal::value( Saved_Report_Journal::EPOCH );
		if ( $epoch === null ) throw new \RuntimeException( 'Report invalidation unavailable.' );
		$state['phase'] = 'journal'; $state['journal'] = 0; $state['epoch'] = $epoch; $state['heap'] = []; $state['top'] = [];
	}
	private function unit( array &$state ): bool {
		global $wpdb;
		if ( $state['phase'] === 'clear' ) {
			$count = $this->execute( $wpdb->prepare( "DELETE FROM {$this->table} WHERE scope=%s AND kind<>%d AND " . $this->owner->fence() . ' LIMIT 500', $this->scope, self::STATE ) );
			if ( $count < 500 ) { $state = $this->initial(); $this->save_state( $state ); }
			return true;
		}
		if ( $state['phase'] === 'scan' ) {
			$rows = $this->rows( $wpdb->prepare( "SELECT umeta_id,user_id FROM {$wpdb->usermeta} WHERE meta_key=%s AND umeta_id>%d ORDER BY umeta_id ASC LIMIT 1", Saved_Items_State::META_KEY, $state['cursor'] ) );
			if ( ! $rows ) { $this->begin_journal( $state ); $this->save_state( $state ); return true; }
			$next = $state; $next['cursor'] = (int) $rows[0]['umeta_id'];
			return $this->project( (int) $rows[0]['user_id'], $state, $next );
		}
		if ( $state['phase'] === 'journal' ) return $this->journal_unit( $state );
		if ( $state['phase'] === 'dirty' ) {
			$rows = $this->rows( $wpdb->prepare( "SELECT owner FROM {$this->table} WHERE scope=%s AND kind=%d ORDER BY owner ASC LIMIT 1", $this->scope, self::DIRTY ) );
			if ( $rows ) return $this->project( (int) $rows[0]['owner'], $state, $state );
			if ( $state['epoch'] !== Saved_Report_Journal::value( Saved_Report_Journal::EPOCH ) ) $this->begin_journal( $state );
			else { $state['phase'] = 'rank'; $state['rankCursor'] = ''; $state['heap'] = []; }
			$this->save_state( $state ); return true;
		}
		if ( $state['phase'] === 'rank' ) {
			$rows = $this->rows( $wpdb->prepare( "SELECT object_key,rank_value,data FROM {$this->table} WHERE scope=%s AND kind=%d AND object_key>%s ORDER BY object_key ASC LIMIT %d", $this->scope, self::OBJECT, $state['rankCursor'], self::PAGE ) );
			foreach ( $rows as $row ) {
				$identity = $this->decode( $row['data'] );
				$item = $this->display_object( $identity );
				if ( ! $item ) { $this->begin_journal( $state ); $this->save_state( $state ); return false; }
				$item['user_count'] = -(int) $row['rank_value'];
				self::rank_item( $state['heap'], $item ); $state['rankCursor'] = $row['object_key'];
			}
			if ( count( $rows ) < self::PAGE ) {
				if ( $state['epoch'] !== Saved_Report_Journal::value( Saved_Report_Journal::EPOCH ) ) $this->begin_journal( $state );
				else { $state['top'] = $state['heap']; usort( $state['top'], [ self::class, 'compare' ] ); $state['heap'] = []; $state['phase'] = 'ready'; $state['completed'] = time(); }
			}
			$this->save_state( $state ); return true;
		}
		throw new \RuntimeException( 'Unknown report phase.' );
	}

	private function journal_unit( array &$state ): bool {
		global $wpdb;
		$journal = Saved_Report_Journal::table();
		$rows = $this->rows( $wpdb->prepare( "SELECT option_id,option_name,option_value FROM {$journal} WHERE option_name LIKE %s AND option_id>%d ORDER BY option_id ASC LIMIT 1", $wpdb->esc_like( Saved_Report_Journal::PREFIX ) . '%', $state['journal'] ) );
		if ( ! $rows ) {
			if ( $state['epoch'] !== Saved_Report_Journal::value( Saved_Report_Journal::EPOCH ) ) $this->begin_journal( $state );
			else $state['phase'] = 'dirty';
			$this->save_state( $state ); return true;
		}
		$row = $rows[0]; $next = $state; $next['journal'] = (int) $row['option_id'];
		$suffix = substr( $row['option_name'], strlen( Saved_Report_Journal::PREFIX ) );
		if ( preg_match( '/^u_([1-9][0-9]*)$/D', $suffix, $match ) ) {
			$uid = (int) $match[1]; $previous = $this->record( self::USER, $uid );
			if ( ! $previous || ( $previous['marker'] ?? '' ) !== $row['option_value'] ) return $this->project( $uid, $state, $next );
		} elseif ( preg_match( '/^p_([1-9][0-9]*)_([1-9][0-9]*)$/D', $suffix, $match ) && (int) $match[1] === get_current_blog_id() ) {
			$id = (int) $match[2]; $ack = $this->record( self::ACK, $id ) ?? [];
			if ( ( $ack['token'] ?? '' ) !== $row['option_value'] ) $ack = [ 'token'=>$row['option_value'], 'cursor'=>0, 'done'=>false ];
			if ( ! $ack['done'] ) {
				$affected = [];
				foreach ( [ 'native_id', 'parent_id' ] as $column ) {
					foreach ( $this->rows( $wpdb->prepare( "SELECT DISTINCT owner FROM {$this->table} WHERE scope=%s AND kind=%d AND {$column}=%d AND owner>%d ORDER BY owner ASC LIMIT %d", $this->scope, self::REFERENCE, $id, $ack['cursor'], self::PAGE ) ) as $ref ) $affected[(int) $ref['owner']] = true;
				}
				$ids = array_keys( $affected ); sort( $ids, SORT_NUMERIC ); $ids = array_slice( $ids, 0, self::PAGE );
				foreach ( $ids as $uid ) { $this->put( self::DIRTY, $uid, '', [] ); $ack['cursor'] = $uid; }
				$ack['done'] = count( $ids ) < self::PAGE; $this->put( self::ACK, $id, '', $ack );
				if ( ! $ack['done'] ) return true;
			}
		}
		$state = $next; $this->save_state( $state ); return true;
	}

	private function project( int $uid, array &$state, array $next ): bool {
		$claim = Saved_DB_Session::claim( 'user:' . $uid );
		if ( empty( $claim['owner'] ) ) {
			if ( $claim['status'] !== 409 ) throw new \RuntimeException( 'Saved source unavailable.' );
			return false;
		}
		$source_owner = $claim['owner'];
		global $wpdb;
		try {
			$marker = Saved_Report_Journal::value( Saved_Report_Journal::user_key( $uid ) );
			$source = Saved_Items_State::read( $uid );
			if ( $marker === null || ! $source || count( $source['items'] ) > self::MAX_ITEMS ) throw new \RuntimeException( 'Saved source needs review.' );
			$projection = $this->classify( $source['items'] );
			$projection['marker'] = $marker; $projection['hash'] = hash( 'sha256', (string) $source['raw'] );
			$previous = $this->record( self::USER, $uid ) ?? [ 'items'=>[], 'refs'=>[], 'wishlist'=>0, 'bookmark'=>0 ];
			$updated = $next;
			foreach ( [ 'users'=>'all', 'wishlist_users'=>'wishlist', 'bookmark_users'=>'bookmark' ] as $total => $kind ) {
				$has = static fn( array $p ): int => (int) ( $kind === 'all' ? $p['wishlist'] + $p['bookmark'] > 0 : $p[$kind] > 0 );
				$updated['totals'][$total] += $has( $projection ) - $has( $previous );
			}
			if ( ! $this->owner->owns() || ! $source_owner->owns() ) throw new \RuntimeException( 'Report source ownership expired.' );
			$this->execute( 'START TRANSACTION' );
			$keys = array_unique( array_merge( array_keys( $previous['items'] ), array_keys( $projection['items'] ) ) );
			$counts = [];
			if ( $keys ) {
				$hashes = array_map( static fn( $key ) => hash( 'sha256', $key ), $keys );
				$in = implode( ',', array_fill( 0, count( $hashes ), '%s' ) );
				foreach ( $this->rows( $wpdb->prepare( "SELECT object_key,rank_value FROM {$this->table} WHERE scope=%s AND kind=%d AND owner=0 AND object_key IN ({$in})", $this->scope, self::OBJECT, ...$hashes ) ) as $row ) $counts[$row['object_key']] = -(int) $row['rank_value'];
			}
			$objects = []; $removed_objects = []; $refs = []; $removed_refs = [];
			foreach ( $keys as $key ) {
				$delta = (int) isset( $projection['items'][$key] ) - (int) isset( $previous['items'][$key] );
				if ( ! $delta ) continue;
				$hash = hash( 'sha256', $key );
				$old = isset( $counts[$hash] );
				$count = ( $counts[$hash] ?? 0 ) + $delta;
				if ( $count < 0 ) throw new \RuntimeException( 'Invalid derived count.' );
				if ( ! $old && $count ) ++$updated['totals']['objects'];
				if ( $old && ! $count ) --$updated['totals']['objects'];
				if ( ! $count ) $removed_objects[] = $hash;
				else $objects[] = [ self::OBJECT, 0, $hash, $projection['items'][$key] ?? $previous['items'][$key], -$count, 0, 0 ];
			}
			foreach ( array_unique( array_merge( array_keys( $previous['refs'] ), array_keys( $projection['refs'] ) ) ) as $key ) {
				$hash = hash( 'sha256', $key );
				if ( ! isset( $projection['refs'][$key] ) ) $removed_refs[] = $hash;
				else {
					$ref = $projection['refs'][$key]; $active = isset( $projection['items'][$key] ) ? 1 : 0;
					if ( ( $previous['refs'][$key] ?? null ) !== $ref || (int) isset( $previous['items'][$key] ) !== $active ) $refs[] = [ self::REFERENCE, $uid, $hash, [], $active, $ref['id'], $ref['parent'] ];
				}
			}
			$this->put_many( $objects ); $this->remove_many( self::OBJECT, 0, $removed_objects );
			$this->put_many( $refs ); $this->remove_many( self::REFERENCE, $uid, $removed_refs );
			$this->put( self::USER, $uid, '', $projection, -$projection['latest'], $projection['wishlist'], $projection['bookmark'] );
			$this->remove( self::DIRTY, $uid, '' );
			$verify = Saved_Items_State::read( $uid );
			if ( ! $source_owner->owns() ) throw new \RuntimeException( 'Source ownership expired.' );
			if ( ! $verify || $verify['raw'] !== $source['raw'] || Saved_Report_Journal::value( Saved_Report_Journal::user_key( $uid ) ) !== $marker ) return false;
			$this->save_state( $updated );
			$this->execute( 'COMMIT' );
			$stored = $this->state();
			if ( ! $stored || $stored['receipt'] !== $updated['receipt'] ) throw new \RuntimeException( 'Derived commit not confirmed.' );
			$state = $stored; return true;
		} finally { $wpdb->query( 'ROLLBACK' ); $source_owner->release(); }
	}

	/** Minimal identity/visibility work: no Woo product, price, image or terms hydration. */
	private function classify( array $items ): array {
		$result = [ 'items'=>[], 'refs'=>[], 'wishlist'=>0, 'bookmark'=>0, 'latest'=>0 ];
		foreach ( $items as $raw ) {
			if ( ! is_array( $raw ) ) continue;
			$id = absint( $raw['id'] ?? 0 ); $type = sanitize_key( (string) ( $raw['type'] ?? '' ) ) === 'wishlist' ? 'wishlist' : 'bookmark';
			$key = Saved_Items_Service::item_key( [ 'id'=>$id, 'type'=>$type, 'url'=>$raw['url'] ?? '' ] );
			$post = $id ? get_post( $id ) : null;
			$result['refs'][$key] = [ 'id'=>$id, 'parent'=>(int) ( $post->post_parent ?? 0 ) ];
			if ( ! $this->store && ( $type === 'wishlist' || ( $post && in_array( $post->post_type, [ 'product', 'product_variation' ], true ) ) ) ) continue;
			if ( $id ) {
				if ( ! isset( $this->native[$id] ) ) $this->native[$id] = Content_Visibility::can_read( $id ) && sanitize_text_field( get_the_title( $id ) ) !== '' && esc_url_raw( get_permalink( $id ) ) !== '';
				if ( ! $this->native[$id] ) continue;
			} elseif ( sanitize_text_field( (string) ( $raw['title'] ?? '' ) ) === '' || esc_url_raw( (string) ( $raw['url'] ?? '' ) ) === '' ) continue;
			$result['items'][$key] = [ 'key'=>$key, 'id'=>$id, 'type'=>$type ];
			++$result[$type]; $result['latest'] = max( $result['latest'], absint( $raw['savedAt'] ?? time() ) );
		}
		return $result;
	}

	private function display_object( array $identity, bool $verify = false ): array {
		$id = (int) $identity['id']; $key = (string) $identity['key'];
		if ( $id ) {
			if ( ! Content_Visibility::can_read( $id ) ) { Saved_Report_Journal::mark_post( $id ); return []; }
			$title = sanitize_text_field( get_the_title( $id ) ); $url = esc_url_raw( get_permalink( $id ) );
		} else {
			global $wpdb;
			$refs = $this->rows( $wpdb->prepare( "SELECT owner FROM {$this->table} WHERE scope=%s AND kind=%d AND object_key=%s AND rank_value=1 ORDER BY owner ASC LIMIT 1", $this->scope, self::REFERENCE, hash( 'sha256', $key ) ) );
			if ( ! $refs ) throw new \RuntimeException( 'Saved reference missing.' );
			$uid = (int) $refs[0]['owner'];
			if ( ! isset( $this->sources[$uid] ) ) $this->sources[$uid] = Saved_Items_State::read( $uid );
			$source = $this->sources[$uid]; $user = $this->record( self::USER, $uid );
			if ( ! $source || ! $user || ! hash_equals( $user['hash'], hash( 'sha256', (string) $source['raw'] ) ) ) { Saved_Report_Journal::mark_user( $uid ); return []; }
			$title = ''; $url = '';
			foreach ( $source['items'] as $raw ) {
				if ( is_array( $raw ) && Saved_Items_Service::item_key( $raw ) === $key ) { $title = sanitize_text_field( (string) ( $raw['title'] ?? '' ) ); $url = esc_url_raw( (string) ( $raw['url'] ?? '' ) ); break; }
			}
		}
		if ( $title === '' || $url === '' || strlen( $title ) > 8192 || strlen( $url ) > 8192 ) throw new \RuntimeException( 'Saved display needs review.' );
		if ( $verify && ( $identity['title'] !== $title || $identity['url'] !== $url ) ) {
			if ( $id ) Saved_Report_Journal::mark_post( $id );
			return [];
		}
		return [ 'key'=>$key, 'id'=>$id, 'type'=>$identity['type'], 'title'=>$title, 'url'=>$url ];
	}

	/** Root is the worst of at most 250 candidates; ties retain native byte ordering. */
	private static function rank_item( array &$heap, array $item ): void {
		$count = count( $heap );
		if ( $count < 250 ) {
			$heap[] = $item; $at = $count;
			while ( $at > 0 ) { $parent = intdiv( $at - 1, 2 ); if ( self::compare( $heap[$at], $heap[$parent] ) <= 0 ) break; [ $heap[$at], $heap[$parent] ] = [ $heap[$parent], $heap[$at] ]; $at = $parent; }
			return;
		}
		if ( self::compare( $item, $heap[0] ) >= 0 ) return;
		$heap[0] = $item; $at = 0;
		while ( $at * 2 + 1 < $count ) {
			$child = $at * 2 + 1;
			if ( $child + 1 < $count && self::compare( $heap[$child + 1], $heap[$child] ) > 0 ) ++$child;
			if ( self::compare( $heap[$child], $heap[$at] ) <= 0 ) break;
			[ $heap[$at], $heap[$child] ] = [ $heap[$child], $heap[$at] ]; $at = $child;
		}
	}
	private static function compare( array $a, array $b ): int {
		return ( $b['user_count'] <=> $a['user_count'] ) ?: strcasecmp( $a['title'], $b['title'] ) ?: strcmp( $a['key'], $b['key'] );
	}

	private function state(): ?array { return $this->record( self::STATE, 0 ); }
	private function save_state( array &$state ): void {
		$state['receipt'] = bin2hex( random_bytes( 12 ) );
		$this->put( self::STATE, 0, '', $state, time() );
	}
	private function record( int $kind, int $owner ): ?array {
		global $wpdb;
		$rows = $this->rows( $wpdb->prepare( "SELECT data FROM {$this->table} WHERE scope=%s AND kind=%d AND owner=%d AND object_key='' LIMIT 1", $this->scope, $kind, $owner ) );
		return $rows ? $this->decode( $rows[0]['data'] ) : null;
	}
	private function put( int $kind, int $owner, string $key, array $data, int $rank = 0, int $id = 0, int $parent = 0 ): void {
		$this->put_many( [ [ $kind, $owner, $key, $data, $rank, $id, $parent ] ] );
	}
	private function put_many( array $records ): void {
		global $wpdb;
		$fence = $this->owner->fence();
		foreach ( array_chunk( $records, 100 ) as $chunk ) {
			$parts = [];
			foreach ( $chunk as [ $kind, $owner, $key, $data, $rank, $id, $parent ] ) {
				$raw = wp_json_encode( $data );
				if ( ! is_string( $raw ) || strlen( $raw ) > Saved_Items_State::MAX_BYTES ) throw new \RuntimeException( 'Report record too large.' );
				$parts[] = $wpdb->prepare( "SELECT %s AS scope,%d AS kind,%d AS owner,%s AS object_key,%d AS rank_value,%d AS native_id,%d AS parent_id,%s AS data", $this->scope, $kind, $owner, $key, $rank, $id, $parent, $raw );
			}
			$this->execute( "INSERT INTO {$this->table} (scope,kind,owner,object_key,rank_value,native_id,parent_id,data) SELECT * FROM (" . implode( ' UNION ALL ', $parts ) . ") AS batch WHERE {$fence} ON DUPLICATE KEY UPDATE rank_value=IF({$fence},VALUES(rank_value),{$this->table}.rank_value),native_id=IF({$fence},VALUES(native_id),{$this->table}.native_id),parent_id=IF({$fence},VALUES(parent_id),{$this->table}.parent_id),data=IF({$fence},VALUES(data),{$this->table}.data)" );
		}
	}
	private function remove( int $kind, int $owner, string $key ): void {
		$this->remove_many( $kind, $owner, [ $key ] );
	}
	private function remove_many( int $kind, int $owner, array $keys ): void {
		global $wpdb;
		foreach ( array_chunk( $keys, 100 ) as $chunk ) {
			$in = implode( ',', array_fill( 0, count( $chunk ), '%s' ) );
			$this->execute( $wpdb->prepare( "DELETE FROM {$this->table} WHERE scope=%s AND kind=%d AND owner=%d AND object_key IN ({$in}) AND " . $this->owner->fence(), $this->scope, $kind, $owner, ...$chunk ) );
		}
	}
	private function execute( string $sql ): int {
		global $wpdb;
		$result = $wpdb->query( $sql );
		if ( $result === false || ! $this->owner->owns() ) throw new \RuntimeException( 'Private report write unavailable.' );
		return (int) $result;
	}
	private function rows( string $sql ): array {
		global $wpdb;
		$rows = $wpdb->get_results( $sql, ARRAY_A );
		if ( $wpdb->last_error || ! is_array( $rows ) ) throw new \RuntimeException( 'Private report read unavailable.' );
		return $rows;
	}
	private function decode( string $raw ): array {
		if ( strlen( $raw ) > Saved_Items_State::MAX_BYTES ) throw new \RuntimeException( 'Private report record too large.' );
		$data = json_decode( $raw, true, 32 );
		if ( ! is_array( $data ) ) throw new \RuntimeException( 'Private report record invalid.' );
		return $data;
	}
}
