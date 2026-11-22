<?php
namespace DSA\MCP;
if ( ! defined( 'ABSPATH' ) ) exit;

use DSA\MCP\Vendor\WP\MCP\Transport\Infrastructure\SessionManager;

/** Keep legacy handles bound to the exact current grant and native session. */
final class HTTP_Transport extends \DSA\MCP\Vendor\WP\MCP\Transport\HttpTransport {
 public function handle_request( \WP_REST_Request $request ): \WP_REST_Response {
  $grant = Auth_Context::current();
  if ( ! $grant ) return HTTP_Controller::response( [ 'error'=>'invalid_token' ],401 );
  $handle = (string) $request->get_header( 'Mcp-Session-Id' );
  if ( $handle !== '' ) {
   if ( ! self::valid_handle( $handle ) ) return HTTP_Controller::response( [ 'error'=>'invalid_session' ],400 );
   $binding = Records::get( 'session',self::binding_id( $handle ) );
   if ( ! $binding || $binding['expiresAt'] <= time() || $binding['userId'] !== $grant['actor'] || ( $binding['data']['grant'] ?? '' ) !== $grant['id'] || ( $binding['data']['client'] ?? '' ) !== $grant['client'] ) {
    return HTTP_Controller::response( [ 'error'=>'invalid_session' ],404 );
   }
  }

  // The current protocol has no initialize exchange or session allocation.
  $initialize = $request->get_method() === 'POST'
   && $request->get_header( 'Mcp-Protocol-Version' ) !== \DSA\MCP\Vendor\WP\McpSchema\Schemas::V2026_07_28
   && ( json_decode( $request->get_body(),true )['method'] ?? null ) === 'initialize';
  $response = $initialize ? $this->initialize( $request,$grant ) : parent::handle_request( $request );
  if ( ! $initialize && ! empty( $response->get_headers()['Mcp-Session-Id'] ) ) {
   // A handle must never escape without going through the allocation lock.
   self::discard( $grant['actor'],(string) $response->get_headers()['Mcp-Session-Id'] );
   return self::unavailable();
  }
  if ( $handle !== '' && $request->get_method() === 'DELETE' && $response->get_status() === 200 ) {
   try { Records::atomic( static fn() => Records::delete( 'session',self::binding_id( $handle ) ) ); }
   catch ( \Throwable ) { /* The native handle is closed. A later initialize reconciles any leftover binding. */ }
  }
  $response->header( 'Cache-Control','no-store, private' );
  $response->header( 'X-Robots-Tag','noindex, nofollow' );
  return $response;
 }

 private function initialize( \WP_REST_Request $request,array $grant ): \WP_REST_Response {
  $actor = $grant['actor'];
  $created = '';
  try {
   return Records::session_transaction( $actor,function() use ( $actor,$grant,$request,&$created ) {
    $current = Records::get( 'grant',$grant['id'],true );
    if ( ! $current || $current['userId'] !== $actor || $current['expiresAt'] <= time() || ( $current['data']['client'] ?? '' ) !== $grant['client'] || ! Credentials::actor_current( $current['data'],$actor ) ) {
     throw new \RuntimeException( 'The connection changed during initialization.' );
    }
    // The native handshake can write one metadata row. It never dispatches a tool here.
    wp_cache_delete( $actor,'user_meta' );
    $response = parent::handle_request( $request );
    $created = $response->get_headers()['Mcp-Session-Id'] ?? '';
    if ( $created === '' ) return $response;
    if ( ! is_string( $created ) || ! self::valid_handle( $created ) ) throw new \RuntimeException( 'The native session handle is invalid.' );
    $native = SessionManager::get_all_user_sessions( $actor );
    if ( ! isset( $native[$created] ) ) throw new \RuntimeException( 'The native adapter did not retain the new session.' );
    $native_ids = [];
    foreach ( array_keys( $native ) as $handle ) {
     if ( is_string( $handle ) && self::valid_handle( $handle ) ) $native_ids[self::binding_id( $handle )] = true;
    }
    if ( Records::reconcile_sessions( $actor,$native_ids ) >= 32 ) throw new \RuntimeException( 'The session binding limit is in use.' );
    // Native idle expiration still applies. An active bound session cannot outlive its grant.
    Records::put( 'session',self::binding_id( $created ),[ 'grant'=>$grant['id'],'client'=>$grant['client'] ],$current['expiresAt'],$actor,$grant['client'] );
    return $response;
   } );
  } catch ( \Throwable ) {
   if ( is_string( $created ) && self::valid_handle( $created ) ) {
    // Native transactional storage has already rolled back. Retire an unbound handle on legacy storage too.
    wp_cache_delete( $actor,'user_meta' );
    self::discard( $actor,$created );
   }
   return self::unavailable();
  } finally {
   if ( $created !== '' ) wp_cache_delete( $actor,'user_meta' );
  }
 }

 private static function valid_handle( string $handle ): bool {
  return strlen( $handle ) <= 100 && preg_match( '/^[A-Za-z0-9-]+$/D',$handle ) === 1;
 }
 private static function binding_id( string $handle ): string { return 's_' . hash( 'sha256',$handle ); }
 private static function discard( int $actor,string $handle ): void {
  try { SessionManager::delete_session( $actor,$handle ); } catch ( \Throwable ) { /* No binding was issued for this handle. */ }
 }
 private static function unavailable(): \WP_REST_Response {
  $response = HTTP_Controller::response( [ 'error'=>'temporarily_unavailable' ],503 );
  $response->header( 'Retry-After','1' );
  return $response;
 }
}
