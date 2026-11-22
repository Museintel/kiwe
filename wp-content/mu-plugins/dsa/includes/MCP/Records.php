<?php
namespace DSA\MCP;
if (!defined('ABSPATH')) exit;

/** Intent-only, bounded delegated-access state. Never a second business-data store. */
final class Records {
 public const SCHEMA=1;
 private const KINDS=['client','pending','code','grant','session','intent'];
 private static bool $transaction=false;
 public static function table(): string { global $wpdb;return$wpdb->prefix.'kiwe_mcp_records'; }
 public static function ready(): bool { return in_array(get_option('kiwe_mcp_storage_v1',0),[self::SCHEMA,(string)self::SCHEMA],true); }
 public static function install(): void {
  if(!\DSA\Apps\MCP_App_Service::allowed()||is_multisite())throw new \RuntimeException('Website management permission on a supported individual site is required.');
  if(self::ready())return;
  global $wpdb;$table=self::table();$collate=$wpdb->get_charset_collate();
  require_once ABSPATH.'wp-admin/includes/upgrade.php';
  dbDelta("CREATE TABLE {$table} (
   id varchar(80) NOT NULL,
   kind varchar(12) NOT NULL,
   user_id bigint(20) unsigned NOT NULL DEFAULT 0,
   client_hash char(64) NOT NULL DEFAULT '',
   expires_at bigint(20) unsigned NOT NULL,
   last_used_at bigint(20) unsigned NOT NULL DEFAULT 0,
   payload longtext NOT NULL,
   PRIMARY KEY  (id),
   KEY expiry (kind,expires_at),
   KEY actor (user_id,kind),
   KEY client (client_hash,kind)
  ) ENGINE=InnoDB {$collate};");
  $status=$wpdb->get_row($wpdb->prepare('SHOW TABLE STATUS LIKE %s',$wpdb->esc_like($table)),ARRAY_A);
  if(!is_array($status)||strtolower($status['Engine']??'')!=='innodb')throw new \RuntimeException('MCP needs a transactional native database table.');
  if(!is_string(get_option('kiwe_mcp_revocation_epoch_v1',null)))update_option('kiwe_mcp_revocation_epoch_v1',wp_generate_uuid4(),false);
  update_option('kiwe_mcp_storage_v1',self::SCHEMA,false);
 }
 private static function identity(string $kind,string $id): void {
  if(!in_array($kind,self::KINDS,true)||preg_match('/^[a-z0-9_:-]{1,80}$/D',$id)!==1)throw new \InvalidArgumentException('Unsupported MCP record.');
 }
 public static function get(string $kind,string $id,bool $lock=false): ?array {
  self::identity($kind,$id);if(!self::ready())throw new \RuntimeException('Open MCP settings to finish connection storage setup.');
  if($lock&&!self::$transaction)throw new \LogicException('An admitted transaction is required.');
  global $wpdb;$sql=$wpdb->prepare('SELECT * FROM '.self::table().' WHERE id=%s AND kind=%s',$id,$kind).($lock?' FOR UPDATE':'');
  $row=$wpdb->get_row($sql,ARRAY_A);
  if($wpdb->last_error)throw new \RuntimeException('MCP connection storage is unavailable.');
  if(!$row)return null;
  $payload=json_decode((string)$row['payload'],true,32);
  if(!is_array($payload))throw new \RuntimeException('MCP connection state could not be verified.');
  return['id'=>$row['id'],'kind'=>$kind,'userId'=>(int)$row['user_id'],'expiresAt'=>(int)$row['expires_at'],'lastUsedAt'=>(int)$row['last_used_at'],'data'=>$payload];
 }
 public static function put(string $kind,string $id,array $data,int $expires,int $user=0,string $client=''): void {
  self::identity($kind,$id);if(!self::$transaction)throw new \LogicException('An admitted transaction is required.');
  $payload=wp_json_encode($data);if(!is_string($payload)||strlen($payload)>32768||$expires<1||$user<0)throw new \RuntimeException('MCP state exceeds its storage budget.');
  global $wpdb;$ok=$wpdb->replace(self::table(),['id'=>$id,'kind'=>$kind,'user_id'=>$user,'client_hash'=>$client!==''?hash('sha256',$client):'','expires_at'=>$expires,'payload'=>$payload],['%s','%s','%d','%s','%d','%s']);
  if($ok===false)throw new \RuntimeException('MCP state could not be saved.');
 }
 public static function delete(string $kind,string $id): void {
  self::identity($kind,$id);if(!self::$transaction)throw new \LogicException('An admitted transaction is required.');
  global $wpdb;if($wpdb->delete(self::table(),['id'=>$id,'kind'=>$kind],['%s','%s'])===false)throw new \RuntimeException('MCP state could not be retired.');
 }
 public static function count(string $kind,?int $user=null): int {
  if(!in_array($kind,self::KINDS,true))throw new \InvalidArgumentException('Unsupported record kind.');
  global $wpdb;$sql=$wpdb->prepare('SELECT COUNT(*) FROM '.self::table().' WHERE kind=%s AND expires_at>%d',$kind,time());
  if($user!==null)$sql.=$wpdb->prepare(' AND user_id=%d',$user);
  $n=$wpdb->get_var($sql);if($wpdb->last_error)throw new \RuntimeException('MCP state is unavailable.');return(int)$n;
 }
 /** Keep only an actor's bindings whose handles still exist in the native adapter. */
 public static function reconcile_sessions( int $actor,array $native_ids ): int {
  if ( ! self::$transaction || $actor < 1 ) throw new \LogicException( 'An admitted actor session transaction is required.' );
  global $wpdb;
  $table = self::table();
  if ( $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE kind='session' AND user_id=%d AND expires_at<=%d LIMIT 64",$actor,time() ) ) === false ) {
   throw new \RuntimeException( 'Expired session bindings could not be retired.' );
  }
  $rows = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$table} WHERE kind='session' AND user_id=%d AND expires_at>%d ORDER BY id LIMIT 33 FOR UPDATE",$actor,time() ) );
  if ( $wpdb->last_error || ! is_array( $rows ) || count( $rows ) > 32 ) throw new \RuntimeException( 'The session binding inventory is unavailable.' );
  $remaining = 0;
  foreach ( $rows as $id ) {
   if ( isset( $native_ids[$id] ) ) ++$remaining;
   else self::delete( 'session',$id );
  }
  return $remaining;
 }
 /** Other writers remain zero-wait. A repeated native consent may wait one second for its decision. */
 public static function atomic(callable $work,bool $wait_for_consent=false) {
  return self::transaction( $work,'kiwe-mcp-' . substr( hash( 'sha256',self::table() ),0,32 ),$wait_for_consent ? 1 : 0 );
 }
 /** Legacy initialization only. Keep the short native allocation and its binding together. */
 public static function session_transaction( int $actor,callable $work ) {
  if ( $actor < 1 ) throw new \LogicException( 'A native session actor is required.' );
  $lock = 'kiwe-mcp-session-' . substr( hash( 'sha256',self::table() . '|' . $actor ),0,32 );
  return self::transaction( $work,$lock,0 );
 }
 private static function transaction( callable $work,string $lock,int $wait ) {
  if(self::$transaction||!self::ready())throw new \RuntimeException('MCP connection storage is not ready.');
  global $wpdb;
  if((string)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,%d)',$lock,$wait))!=='1')throw new \RuntimeException('Another connection action is finishing. Try again shortly.');
  $begun=false;$committed=false;
  try{
   $prior=$wpdb->suppress_errors(true);$admitted=$wpdb->query('SET TRANSACTION ISOLATION LEVEL READ COMMITTED')!==false;$wpdb->suppress_errors($prior);
   if(!$admitted)throw new \RuntimeException('Finish the current native transaction before linking an AI.');
   $begun=$wpdb->query('START TRANSACTION')!==false;if(!$begun)throw new \RuntimeException('Connection transaction unavailable.');self::$transaction=true;
   $result=$work();if($wpdb->query('COMMIT')===false)throw new \RuntimeException('The connection change could not be confirmed.');$committed=true;return$result;
  }finally{if($begun&&!$committed)$wpdb->query('ROLLBACK');self::$transaction=false;$wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock));}
 }
 public static function purge(): void {
  global $wpdb;$wpdb->query($wpdb->prepare('DELETE FROM '.self::table().' WHERE expires_at<%d LIMIT 25',time()));
 }
 public static function connections(int $user): array {
  if(!self::ready())return[];global $wpdb;
  $rows=$wpdb->get_results($wpdb->prepare('SELECT id FROM '.self::table().' WHERE kind=%s AND user_id=%d AND expires_at>%d ORDER BY last_used_at DESC,id LIMIT 20','grant',$user,time()),ARRAY_A);
  if($wpdb->last_error)throw new \RuntimeException('Connection history is unavailable.');
  return array_values(array_filter(array_map(static fn($row)=>self::get('grant',$row['id']),$rows?:[])));
 }
 public static function seen(string $id): void {
  self::identity('grant',$id);global $wpdb;
  $wpdb->query($wpdb->prepare('UPDATE '.self::table().' SET last_used_at=%d WHERE id=%s AND kind=%s AND last_used_at<%d',time(),$id,'grant',time()-300));
 }
}
