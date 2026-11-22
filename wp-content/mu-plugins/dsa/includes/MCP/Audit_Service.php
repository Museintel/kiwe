<?php
namespace DSA\MCP;

use DSA\Access\Trash_Workspace_Service as Trash;
use DSA\Access\Template_Workspace_Service as Templates;
use DSA\Access\Page_Publishing_Service as Pages;

if ( ! defined( 'ABSPATH' ) ) exit;

/** Explicit private native reference inspection; no index, scheduled scan or business-data copy. */
final class Audit_Service {
 public const TYPES = [ 'post','page','bricks_template' ];
 public const MATCH_LIMIT = 25;
 public const EVIDENCE_BYTES = 1048576, TOTAL_BYTES = 4194304;
 private const ROUTE_OPTIONS = [ 'page_on_front','page_for_posts','wp_page_for_privacy_policy','woocommerce_shop_page_id','woocommerce_cart_page_id','woocommerce_checkout_page_id','woocommerce_myaccount_page_id','woocommerce_terms_page_id' ];
 public static function allowed(): bool {
  return Credentials::actor_allowed( get_current_user_id() ) && ( current_user_can( 'kiwe_manage_context' ) || current_user_can( 'manage_options' ) );
 }
 public static function can_record( string $type,int $id ): bool {
  if ( ! self::allowed() || ! in_array( $type,self::TYPES,true ) || ( $type === 'bricks_template' && ( ! Templates::available() || ! Templates::allowed() ) ) ) return false;
  $p = get_post( $id );
  return $p && $p->post_type === $type && current_user_can( 'read_post',$id ) && current_user_can( 'edit_post',$id ) && current_user_can( 'delete_post',$id );
 }
 private static function record( string $type,int $id ): \WP_Post {
  if ( ! self::can_record( $type,$id ) ) throw new \RuntimeException( 'Choose a native content record your current role can read, edit and move to Trash.' );
  return get_post( $id );
 }
 private static function digest( $value ): string { return hash( 'sha256',serialize( $value ) ); }
 private static function query( string $sql ): array {
  global $wpdb;
  $rows = $wpdb->get_results( $sql,ARRAY_A );
  if ( $wpdb->last_error || ! is_array( $rows ) ) throw new \RuntimeException( 'Reference inspection is unavailable. Nothing can be called unused or cleaned from this result.' );
  return $rows;
 }
 private static function url_reference( string $text,\WP_Post $post,string $path ): bool {
  $text = rawurldecode( html_entity_decode( str_replace( '\\/','/',$text ),ENT_QUOTES | ENT_HTML5,'UTF-8' ) );
  if ( strlen( $path ) > 1 && str_contains( strtolower( $text ),strtolower( rawurldecode( $path ) ) ) ) return true;
  return preg_match( '/[?&](?:p|page_id)=' . $post->ID . '(?:[^0-9]|$)/',$text ) === 1;
 }
 /** Never instantiate serialized objects or evaluate code found in stored evidence. */
 private static function decode_evidence( string $raw,bool &$complete ) {
  if ( preg_match( '/^(?:a|O|C|s|i|d|b|N)[:;]/',$raw ) ) {
   try { $value = @unserialize( $raw,[ 'allowed_classes'=>false,'max_depth'=>17 ] ); } catch ( \Throwable ) { $complete = false;return null; }
   if ( $value === false && $raw !== 'b:0;' ) $complete = false;
   return $value;
  }
  if ( str_starts_with( $raw,'{' ) || str_starts_with( $raw,'[' ) ) {
   try { return json_decode( $raw,true,17,JSON_THROW_ON_ERROR ); } catch ( \Throwable ) { $complete = false;return null; }
  }
  return $raw;
 }
 private static function structured_reference( $value,\WP_Post $post,bool &$complete,string $path,string $key = '',int $depth = 0,int &$nodes = 0 ): bool {
  if ( $depth > 16 || ++$nodes > 4096 ) { $complete = false;return false; }
  if ( is_object( $value ) ) { $complete = false;return false; }
  if ( is_string( $value ) && self::url_reference( $value,$post,$path ) ) return true;
  $name = strtolower( str_replace( [ '_','-' ],'',$key ) );
  $idKeys = [ 'postid','pageid','websitepageid','discussionpostid','postin','postnotin','postsinclude','postsexclude','postids','pageids','postparent','postparentin','postparentnotin','templatepreviewpostid' ];
  if ( $post->post_type === 'bricks_template' ) $idKeys = array_merge( $idKeys,[ 'template','templateid','templateids','popup','popupid','megamenutemplate' ] );
  if ( in_array( $name,$idKeys,true ) && ( is_int( $value ) || is_string( $value ) ) && (string) $value === (string) $post->ID ) return true;
  if ( is_array( $value ) ) {
   // Bricks distinguishes explicit post conditions from term/author IDs.
   if ( ( $value['main'] ?? '' ) === 'ids' && is_array( $value['ids'] ?? null ) ) foreach ( $value['ids'] as $id ) {
    if ( ! is_int( $id ) && ! is_string( $id ) ) { $complete = false;continue; }
    if ( (string) $id === (string) $post->ID ) return true;
   }
   $attribute = $value['name'] ?? $value['attribute'] ?? '';
   $target = $value['value'] ?? null;
   if ( is_string( $attribute ) && ( is_int( $target ) || is_string( $target ) ) && preg_match( '/^data-(?:kiwe|dsa)-[a-z-]*(?:id|post|target)$/D',$attribute ) && (string) $target === (string) $post->ID ) return true;
   foreach ( $value as $k=>$v ) if ( self::structured_reference( $v,$post,$complete,$path,is_int( $k ) ? $key : (string) $k,$depth + 1,$nodes ) ) return true;
  }
  return false;
 }
 private static function evidence_reference( string $kind,array $row,\WP_Post $post,bool &$complete,string $path ): bool {
  $raw = $row['evidence'];
  if ( self::url_reference( $raw,$post,$path ) ) return true;
  if ( $kind === 'content' ) return (int) ( $row['parentId'] ?? 0 ) === (int) $post->ID || preg_match( '/["\'](?:postId|post_id|pageId|page_id|templateId|template_id|ref)["\']\s*:\s*["\']?' . $post->ID . '(?:[^0-9]|$)/',$raw ) === 1 || preg_match( '/\[bricks_template\b[^\]]*\bid\s*=\s*["\']?' . $post->ID . '(?:[^0-9]|$)/',$raw ) === 1;
  if ( $kind === 'metadata' && ( $row['sourceKey'] ?? '' ) === '_menu_item_object_id' ) {
   $id = (int) $row['recordId'];return trim( $raw ) === (string) $post->ID && get_post_meta( $id,'_menu_item_type',true ) === 'post_type' && get_post_meta( $id,'_menu_item_object',true ) === $post->post_type;
  }
  if ( $kind === 'metadata' && ! str_starts_with( (string) ( $row['sourceKey'] ?? '' ),'_bricks_' ) ) return false;
  if ( $kind === 'settings' && in_array( $row['sourceKey'] ?? '',self::ROUTE_OPTIONS,true ) ) return trim( $raw ) === (string) $post->ID;
  $value = self::decode_evidence( $raw,$complete );$nodes = 0;
  return self::structured_reference( $value,$post,$complete,$path,'',0,$nodes );
 }
 /** SQL selects bounded potential matches. Only typed native fields/URLs become reference proof. */
 private static function matches( \WP_Post $post ): array {
  global $wpdb;
  foreach ( [ $wpdb->posts,$wpdb->postmeta,$wpdb->options ] as $table ) if ( ! preg_match( '/^[a-zA-Z0-9_]+$/D',$table ) ) throw new \RuntimeException( 'Native reference tables are unavailable.' );
  $needle = '%' . $wpdb->esc_like( (string) $post->ID ) . '%';
  $url = get_permalink( $post->ID );
  $nativePath = is_string( $url ) ? (string) parse_url( $url,PHP_URL_PATH ) : '';
  // A root URL is not a meaningful substring. Exact protected-route checks own it.
  $urlNeedle = strlen( $nativePath ) > 1 ? $nativePath : ( $post->post_type === 'page' ? 'page_id=' : 'p=' ) . $post->ID;
  $path = '%' . $wpdb->esc_like( $urlNeedle ) . '%';
  $limit = self::MATCH_LIMIT + 1;
  $cap = self::EVIDENCE_BYTES + 1;
  // Native Trash preserves the target ID and its own revisions. References
  // from another record's revision are still dependencies of this target.
  $queries = [
   'content'=>$wpdb->prepare( "SELECT ID AS id,post_parent AS parentId,SUBSTRING(CAST(CONCAT_WS(' ',post_content,post_excerpt) AS BINARY),1,%d) AS evidence,OCTET_LENGTH(CONCAT_WS(' ',post_content,post_excerpt)) AS evidenceBytes,SHA2(CONCAT_WS('|',post_type,post_status,post_title,post_content,post_excerpt,post_parent),256) AS proof FROM {$wpdb->posts} WHERE ID<>%d AND NOT (post_type='revision' AND post_parent=%d) AND (post_parent=%d OR post_content LIKE %s OR post_excerpt LIKE %s OR (%s<>'' AND (REPLACE(post_content,CHAR(92),'') LIKE %s OR REPLACE(post_excerpt,CHAR(92),'') LIKE %s))) ORDER BY ID LIMIT %d",$cap,$post->ID,$post->ID,$post->ID,$needle,$needle,$path,$path,$path,$limit ),
   'metadata'=>$wpdb->prepare( "SELECT meta_id AS id,post_id AS recordId,meta_key AS sourceKey,SUBSTRING(CAST(meta_value AS BINARY),1,%d) AS evidence,OCTET_LENGTH(meta_value) AS evidenceBytes,SHA2(CONCAT_WS('|',meta_key,meta_value),256) AS proof FROM {$wpdb->postmeta} WHERE post_id<>%d AND NOT EXISTS (SELECT 1 FROM {$wpdb->posts} AS own_revision WHERE own_revision.ID={$wpdb->postmeta}.post_id AND own_revision.post_type='revision' AND own_revision.post_parent=%d) AND (((meta_key LIKE %s OR meta_key='_menu_item_object_id') AND meta_value LIKE %s) OR (%s<>'' AND REPLACE(meta_value,CHAR(92),'') LIKE %s)) ORDER BY meta_id LIMIT %d",$cap,$post->ID,$post->ID,$wpdb->esc_like( '_bricks_' ) . '%',$needle,$path,$path,$limit ),
   'settings'=>$wpdb->prepare( "SELECT option_id AS id,option_name AS sourceKey,SUBSTRING(CAST(option_value AS BINARY),1,%d) AS evidence,OCTET_LENGTH(option_value) AS evidenceBytes,SHA2(CONCAT_WS('|',option_name,option_value),256) AS proof FROM {$wpdb->options} WHERE option_name NOT LIKE %s AND option_name NOT LIKE %s AND ((option_name IN ('bricks_global_settings','bricks_components','bricks_theme_styles','dsa_settings','kiwe_seam_design_context_v1','page_on_front','page_for_posts','wp_page_for_privacy_policy','woocommerce_shop_page_id','woocommerce_cart_page_id','woocommerce_checkout_page_id','woocommerce_myaccount_page_id','woocommerce_terms_page_id') AND option_value LIKE %s) OR (%s<>'' AND REPLACE(option_value,CHAR(92),'') LIKE %s)) ORDER BY option_id LIMIT %d",$cap,$wpdb->esc_like( '_transient_' ) . '%',$wpdb->esc_like( '_site_transient_' ) . '%',$needle,$path,$path,$limit ),
  ];
  $groups = [];$complete = true;$bytes = 0;
  foreach ( $queries as $kind=>$sql ) {
   $rows = self::query( $sql );$groups[$kind] = [];if ( count( $rows ) > self::MATCH_LIMIT ) $complete = false;
   foreach ( $rows as $row ) {
    if ( ! is_string( $row['proof'] ?? null ) || ! preg_match( '/^[a-f0-9]{64}$/D',$row['proof'] ) ) throw new \RuntimeException( 'Native reference fingerprints could not be verified.' );
    if ( ! is_string( $row['evidence'] ?? null ) || ! isset( $row['evidenceBytes'] ) || (int) $row['evidenceBytes'] > self::EVIDENCE_BYTES || ( $bytes += strlen( $row['evidence'] ) ) > self::TOTAL_BYTES ) { $complete = false;continue; }
    if ( self::evidence_reference( $kind,$row,$post,$complete,$nativePath ) ) $groups[$kind][] = [ 'id'=>(int) $row['id'],'proof'=>$row['proof'] ];
   }
   unset( $rows );
  }
  return [ 'groups'=>$groups,'complete'=>$complete ];
 }
 private static function protected_reasons( \WP_Post $post ): array {
  $out = [];$id = (int) $post->ID;
  if ( (string) ( $post->post_password ?? '' ) !== '' || in_array( $post->post_status,[ 'private','future' ],true ) ) $out[] = 'Password-protected, private or scheduled content needs its native publishing workflow.';
  if ( $post->post_type === 'page' && Pages::critical_reason( $id ) ) $out[] = 'This page owns an active WordPress or Store purpose.';
  // Preserve configured commerce routes even when Woo or Store is currently inactive.
  foreach ( self::ROUTE_OPTIONS as $key ) if ( $id === (int) get_option( $key,0 ) ) $out[] = 'A native website or commerce setting selects this record.';
  $lock = explode( ':',(string) get_post_meta( $id,'_edit_lock',true ) );
  if ( (int) ( $lock[0] ?? 0 ) > time() - 150 && (int) ( $lock[1] ?? 0 ) !== get_current_user_id() ) $out[] = 'Another person is currently editing this record.';
  if ( $post->post_type === 'bricks_template' ) {
   $settings = get_post_meta( $id,defined( 'BRICKS_DB_TEMPLATE_SETTINGS' ) ? BRICKS_DB_TEMPLATE_SETTINGS : '_bricks_template_settings',true );
   if ( is_array( $settings ) && ( ! empty( $settings['conditions'] ) || ! empty( $settings['templateConditions'] ) ) ) $out[] = 'This Bricks template has active template conditions.';
   if ( $post->post_status === 'publish' ) $out[] = 'Published Bricks templates may be selected by native fallback rules. Review them in the Templates workspace.';
  }
  if ( (int) get_comments( [ 'post_id'=>$id,'count'=>true,'status'=>'all' ] ) > 0 ) $out[] = 'This record has visitor feedback or review history.';
  return array_values( array_unique( $out ) );
 }
 public function inspect( string $type,int $id ): array {
  $post = self::record( $type,$id );$inspection = self::matches( $post );$groups = $inspection['groups'];$blocked = self::protected_reasons( $post );$counts = [];$complete = $inspection['complete'];
  foreach ( $groups as $kind=>$rows ) $counts[$kind] = count( $rows );
  $matches = array_sum( $counts );
  $expected = self::digest( [ (array) $post,get_post_meta( $id ),Trash::fingerprint( $post,$type ),$groups,$blocked ] );
  return [ 'id'=>$id,'type'=>$type,'title'=>Guidance_Service::text( $post->post_title,200 ),'status'=>$post->post_status,'expected'=>$expected,'nativeExpected'=>Trash::fingerprint( $post,$type ),'referenceMatches'=>$counts,'complete'=>$complete,'blockers'=>$blocked,
   'cleanupEligible'=>$post->post_status !== 'trash' && $complete && $matches === 0 && $blocked === [] && ( ! defined( 'EMPTY_TRASH_DAYS' ) || EMPTY_TRASH_DAYS > 0 ),
   'coverage'=>'Stored URLs, native menu objects, Bricks and WordPress fields, and configured business settings. Other records’ recovery revisions can block cleanup. This record’s own revisions stay attached in Trash. The audit covers these native sources only.',
   'guidance'=>! $complete ? 'A bounded source is truncated, oversized or unsupported. Use the native workspace to inspect dependencies; this result cannot authorize cleanup.' : ( $matches > 0 || $blocked !== [] ? 'Keep this record until its native dependencies are deliberately resolved.' : 'No matches were found in the declared native sources. This is not an automatic unused-content verdict. Use permitted authoring reads to assess the actual content and review the exact record before recoverable Trash.' ),
   'nativeUrl'=>admin_url( 'post.php?post=' . $id . '&action=edit' ) ];
 }
 public function read( array $a ): array {
  if ( ! self::allowed() ) throw new \RuntimeException( 'Website management permission is required for this private audit.' );
  $area = $a['area'];$data = [];
  if ( $area === 'record' ) {
   $data = $this->inspect( $a['type'],$a['id'] );unset( $data['nativeExpected'] );
  } elseif ( $area === 'records' ) {
   $type = $a['type'];if ( $type === 'bricks_template' && ( ! Templates::available() || ! Templates::allowed() ) ) throw new \RuntimeException( 'Native template permission is required.' );
   $page = $a['page'] ?? 1;$posts = ( new \WP_Query( [ 'post_type'=>$type,'post_status'=>[ 'draft','pending','publish','private','future','trash' ],'posts_per_page'=>9,'offset'=>( $page - 1 ) * 8,'orderby'=>'ID','order'=>'DESC','no_found_rows'=>true,'update_post_term_cache'=>false ] ) )->posts;
   $items = [];foreach ( array_slice( $posts,0,8 ) as $p ) if ( self::can_record( $type,(int) $p->ID ) ) $items[] = [ 'id'=>(int) $p->ID,'title'=>Guidance_Service::text( $p->post_title,160 ),'type'=>$type,'status'=>$p->post_status ];
   $data = [ 'items'=>$items,'page'=>$page,'hasMore'=>count( $posts ) > 8,'guidance'=>'These are editable records, not inferred deletion targets. Inspect the exact record and its references before proposing cleanup.' ];
  } elseif ( $area === 'maintenance' ) $data = Maintenance_Provider::capabilities();
  else {
   foreach ( self::TYPES as $type ) { if ( $type === 'bricks_template' && ( ! Templates::available() || ! Templates::allowed() ) ) continue;$counts = wp_count_posts( $type,'readable' );$data[$type] = array_intersect_key( (array) $counts,array_flip( [ 'publish','draft','pending','private','future','trash' ] ) ); }
   $data['limits'] = [ 'permanentDeletion'=>false,'mediaOrProductDeletion'=>false,'accountsOrdersOrContacts'=>false,'hostingAuthority'=>false,'scanOnOrdinaryPage'=>false ];
  }
  return [ 'resultJson'=>Authoring_Service::json( [ 'schema'=>'kiwe.mcp-audit.v1','area'=>$area,'data'=>$data ],10000 ) ];
 }
 public function plan( array $a ): array {
  $r = $this->inspect( $a['type'],$a['id'] );
  if ( ! hash_equals( $r['expected'],$a['expected'] ) ) throw new \RuntimeException( 'The record or its native references changed. Read the exact audit again.' );
  if ( $a['action'] === 'trash' && ! $r['cleanupEligible'] ) throw new \RuntimeException( 'Native dependencies, publication, recovery history or incomplete proof prevent this cleanup. Nothing was changed.' );
  if ( $a['action'] === 'restore' && $r['status'] !== 'trash' ) throw new \RuntimeException( 'Only a currently trashed record can be restored.' );
  $after = $a['action'] === 'trash' ? 'Move only this exact record to native Trash. Published content stops serving. It can be restored from Organize → Trash; nothing is permanently deleted.' : 'Restore only this exact native record as a draft for review. No page or template is published or activated by this review.';
  return Authoring_Service::plan( $a['action'] === 'trash' ? 'Review one recoverable content cleanup' : 'Review restoring one native content record',$r['expected'],[ 'id'=>$r['id'],'type'=>$r['type'],'title'=>$r['title'],'status'=>$r['status'],'referenceMatches'=>$r['referenceMatches'],'coverage'=>$r['coverage'] ],$after,true );
 }
 public function apply( array $a,string $expected ): array {
  return Authoring_Service::mutate( 'content:' . $a['type'] . ':' . $a['id'],function() use ( $a,$expected ): array {
   if ( function_exists( 'clean_post_cache' ) ) clean_post_cache( $a['id'] );
   $plan = $this->plan( $a );if ( ! hash_equals( $expected,$plan['nativeExpected'] ) ) throw new \RuntimeException( 'The reviewed native cleanup state changed.' );
   $p = self::record( $a['type'],$a['id'] );
   // Recovery permission does not grant publication. Keep the hook native,
   // exact-record scoped and temporary, even if another plugin restores publish.
   $draft = static fn( $status,$id ) => (int) $id === $a['id'] ? 'draft' : $status;
   if ( $a['action'] === 'restore' ) add_filter( 'wp_untrash_post_status',$draft,PHP_INT_MAX,2 );
   try { $result = Authoring_Service::native( ( new Trash() )->mutate( [ 'operation'=>$a['action'],'type'=>$a['type'],'item'=>[ 'id'=>$a['id'],'expected'=>Trash::fingerprint( $p,$a['type'] ) ] ] ) ); }
   finally { if ( $a['action'] === 'restore' ) remove_filter( 'wp_untrash_post_status',$draft,PHP_INT_MAX ); }
   if ( $a['action'] === 'restore' && ( $result['status'] ?? '' ) !== 'draft' ) throw new \RuntimeException( 'The native restored status needs review. This operation cannot publish content.' );
   return [ 'resultJson'=>Authoring_Service::json( $result ) ];
  } );
 }
}
