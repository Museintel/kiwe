<?php
/** Isolated WordPress-contract test: no live site, database or filesystem mutations. */
define( 'ABSPATH', '/example/public_html/' );
define( 'ARRAY_A', 'ARRAY_A' );
define( 'DSA_OPTION_SETTINGS', 'dsa_settings' );

$saved_meta = [];
$saved_options = [ 'dsa_settings' => [ 'api_key' => 'current-test-secret' ], 'bricks_global_classes' => [] ];
function wp_slash( $value ) { return is_array( $value ) ? array_map( 'wp_slash', $value ) : ( is_string( $value ) ? addslashes( $value ) : $value ); }
function wp_unslash( $value ) { return is_array( $value ) ? array_map( 'wp_unslash', $value ) : ( is_string( $value ) ? stripslashes( $value ) : $value ); }
function absint( $value ) { return abs( (int) $value ); }
function get_post( $id ) { return [ 'ID' => $id, 'post_type' => 'page' ]; }
function wp_update_post( $fields, $errors ) { return $fields['ID']; }
function is_wp_error( $value ) { return false; }
function maybe_unserialize( $value ) { return is_string( $value ) && preg_match( '/^(a|O|s|i|b|d):/', $value ) ? unserialize( $value ) : $value; }
function add_post_meta( $id, $key, $value ) { global $saved_meta; $saved_meta[ $id ][ stripslashes( $key ) ][] = wp_unslash( $value ); return 1; }
function get_object_taxonomies( $type ) { return []; }
function clean_post_cache( $id ) {}
function get_option( $name, $default = false ) { global $saved_options; return array_key_exists( $name, $saved_options ) ? $saved_options[ $name ] : $default; }
function update_option( $name, $value, $autoload = null ) { global $saved_options; $saved_options[ $name ] = $value; return true; }
function delete_option( $name ) { global $saved_options; unset( $saved_options[ $name ] ); return true; }
$wpdb = new class {
    public $postmeta = 'wp_postmeta';
    public function delete( $table, $where, $format ) { global $saved_meta; unset( $saved_meta[ $where['post_id'] ] ); return 1; }
};

require dirname( __DIR__, 2 ) . '/wp-content/mu-plugins/dsa/includes/Diagnostics/Test_Site_Snapshot_Service.php';
$service = new \DSA\Diagnostics\Test_Site_Snapshot_Service();
$failures = 0;
function check_snapshot( $label, $pass ) { global $failures; echo ( $pass ? 'PASS ' : 'FAIL ' ) . $label . PHP_EOL; if ( ! $pass ) { ++$failures; } }

$json = json_encode( [ 'caption' => 'A "quoted" title', 'css' => '.icon::before {content:"\\2713"}', 'regex' => '\\d+\\s' ] );
$elements = [ [ 'id' => 'testab', 'name' => 'text-basic', 'settings' => [ 'text' => 'A "quoted" title', '_cssCustom' => '%root%::before {content:"\\2713"}' ] ] ];
$record = [ 'fields' => [ 'ID' => 7, 'post_type' => 'page', 'post_title' => 'Snapshot fixture' ], 'meta' => [ 'json' => [ $json ], '_bricks_page_content_2' => [ serialize( $elements ) ], 'multiple' => [ 'one\\two', 'three"four' ] ], 'terms' => [] ];
( new ReflectionMethod( $service, 'restore_post' ) )->invoke( $service, 7, $record );
check_snapshot( 'JSON strings retain escaped quotes and backslashes', $saved_meta[7]['json'][0] === $json );
check_snapshot( 'nested Bricks arrays round-trip without CSS/JS corruption', $saved_meta[7]['_bricks_page_content_2'][0] === $elements );
check_snapshot( 'multi-value metadata remains byte-exact', $saved_meta[7]['multiple'] === $record['meta']['multiple'] );

$options = ( new ReflectionMethod( $service, 'capture_options' ) )->invoke( $service );
check_snapshot( 'Kiwe credentials and service configuration are not captured', ! array_key_exists( 'dsa_settings', $options ) );
( new ReflectionMethod( $service, 'restore_options' ) )->invoke( $service, [ 'dsa_settings' => [ 'exists' => true, 'value' => [ 'api_key' => 'old-test-secret' ] ], 'bricks_global_classes' => [ 'exists' => true, 'value' => [ [ 'id' => 'class1' ] ] ] ] );
check_snapshot( 'restore cannot overwrite current Kiwe credentials', $saved_options['dsa_settings']['api_key'] === 'current-test-secret' );
check_snapshot( 'builder globals still restore', $saved_options['bricks_global_classes'] === [ [ 'id' => 'class1' ] ] );
exit( $failures ? 1 : 0 );
