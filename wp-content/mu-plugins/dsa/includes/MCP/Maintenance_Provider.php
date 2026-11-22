<?php
namespace DSA\MCP;
if ( ! defined( 'ABSPATH' ) ) exit;

/** Additional task scopes, not a shell, filesystem or arbitrary WordPress maintenance proxy. */
final class Maintenance_Provider {
 public const AUDIT_SCOPE = 'mcp.audit.read', CLEANUP_SCOPE = 'mcp.cleanup.write', CACHE_SCOPE = 'mcp.maintenance.write';
 private static function text( int $n ): array { return [ 'type'=>'string','maxLength'=>$n ]; }
 private static function object( array $fields,array $required ): array { return [ 'type'=>'object','properties'=>$fields,'required'=>$required,'additionalProperties'=>false ]; }
 public static function cache_allowed(): bool { return Audit_Service::allowed() && current_user_can( 'manage_options' ); }
 public static function cache_available(): bool { return self::cache_allowed() && ! empty( self::capabilities()['pageCache']['adapters'] ); }
 public static function capabilities(): array {
  if ( ! self::cache_allowed() ) throw new \RuntimeException( 'Native technical maintenance permission is required.' );
  $native = ( new \DSA\Diagnostics\Cache_Maintenance_Service() )->report();
  $page = $native['pageCache'];
  $data = [ 'pageCache'=>$page,'objectCacheReset'=>false,'runtimeOrSecurityStateCleanup'=>false,'arbitraryDatabaseOrFileDeletion'=>false,'hostingAccess'=>false ];
  $data['expected'] = hash( 'sha256',serialize( $data ) );
  return $data;
 }
 private static function cache_plan( array $a ): array {
  $c = self::capabilities();
  if ( ! hash_equals( $c['expected'],$a['expected'] ) || empty( $c['pageCache']['adapters'] ) ) throw new \RuntimeException( 'Native page-cache capabilities changed or no supported adapter is available. Read maintenance again.' );
  return Authoring_Service::plan( 'Review clearing the website page cache',$c['expected'],[ 'pageCache'=>$c['pageCache'] ],'Ask the installed native page-cache adapter to discard its cached pages. The next request may be colder. No content, private session, security state, database table, authored file or backup is removed.',true );
 }
 public static function operations(): array {
  if ( ! defined( 'DSA_VERSION' ) || version_compare( DSA_VERSION,'8.0.0-rc.487','<' ) ) return [];
  $audit = new Audit_Service();$can = static fn() => Audit_Service::allowed();$output = self::object( [ 'resultJson'=>self::text( 10000 ) ],[ 'resultJson' ] );
  $type = [ 'type'=>'string','enum'=>Audit_Service::TYPES,'maxLength'=>32 ];
  $id = [ 'type'=>'integer','minimum'=>1 ];$fingerprint = self::text( 64 );
  $read = self::object( [ 'area'=>[ 'type'=>'string','enum'=>[ 'overview','records','record','maintenance' ],'maxLength'=>32 ],'type'=>$type,'id'=>$id,'page'=>[ 'type'=>'integer','minimum'=>1,'maximum'=>10000 ] ],[ 'area' ] );
  return [
   'mcp.audit.read'=>[ 'scope'=>self::AUDIT_SCOPE,'label'=>'Inspect native content, declared references and maintenance capabilities','effect'=>'read','approval'=>'none','accessVersion'=>1,'discoverPermission'=>$can,'permission'=>$can,'inputSchema'=>$read,'outputSchema'=>$output,'execute'=>static function( $a ) use ( $audit ) { if ( in_array( $a['area'],[ 'record','records' ],true ) && ! isset( $a['type'] ) || $a['area'] === 'record' && ! isset( $a['id'] ) ) throw new \RuntimeException( 'Choose the exact native record type and ID.' );return $audit->read( $a ); } ],
   'mcp.cleanup.change'=>[ 'scope'=>self::CLEANUP_SCOPE,'label'=>'Review recoverable cleanup or restore for one exact native record','effect'=>'write','approval'=>'required','accessVersion'=>1,'discoverPermission'=>$can,'permission'=>static fn( $a ) => Audit_Service::can_record( $a['type'],$a['id'] ),'inputSchema'=>self::object( [ 'type'=>$type,'id'=>$id,'action'=>[ 'type'=>'string','enum'=>[ 'trash','restore' ],'maxLength'=>16 ],'expected'=>$fingerprint ],[ 'type','id','action','expected' ] ),'outputSchema'=>$output,'prepare'=>static fn( $a ) => $audit->plan( $a ),'current'=>static fn( $a ) => $audit->plan( $a )['nativeExpected'],'execute'=>static fn( $a,$e ) => $audit->apply( $a,$e ) ],
   'mcp.maintenance.cache'=>[ 'scope'=>self::CACHE_SCOPE,'label'=>'Review a native page-cache purge, without private-state or file cleanup','effect'=>'write','approval'=>'required','accessVersion'=>1,'discoverPermission'=>static fn() => self::cache_available(),'permission'=>static fn() => self::cache_allowed(),'inputSchema'=>self::object( [ 'layer'=>[ 'type'=>'string','enum'=>[ 'page_cache' ],'maxLength'=>32 ],'expected'=>$fingerprint ],[ 'layer','expected' ] ),'outputSchema'=>$output,'prepare'=>static fn( $a ) => self::cache_plan( $a ),'current'=>static fn( $a ) => self::cache_plan( $a )['nativeExpected'],'execute'=>static function( $a,$e ): array { $p = self::cache_plan( $a );if ( ! hash_equals( $e,$p['nativeExpected'] ) ) throw new \RuntimeException( 'Native cache capabilities changed.' );$r = ( new \DSA\Diagnostics\Cache_Maintenance_Service() )->purge( 'all',[ 'page_cache' ] );if ( ! empty( $r['failed'] ) || ! empty( $r['skipped'] ) || empty( $r['completed'] ) || in_array( false,$r['completed'],true ) ) throw new \RuntimeException( 'The native page-cache adapter did not confirm the complete purge. Read its native status before another action.' );return [ 'resultJson'=>Authoring_Service::json( [ 'schema'=>'kiwe.mcp-maintenance.v1','completed'=>$r['completed'],'confirmed'=>true ] ) ]; } ],
  ];
 }
}
