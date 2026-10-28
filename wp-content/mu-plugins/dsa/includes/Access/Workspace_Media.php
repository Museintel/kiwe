<?php
namespace DSA\Access;
if ( ! defined( 'ABSPATH' ) ) exit;
/** Native media presentation and validation, loaded only by private editing tasks. */
final class Workspace_Media {
 public static function validate( $id, int $previous = 0 ) {
  if ( ! is_int( $id ) || $id < 0 ) return new \WP_Error( 'kiwe_image_invalid', 'Choose an existing image.', [ 'status'=>400 ] );
  if ( $id === $previous ) return $id;
  if ( ! current_user_can( 'upload_files' ) ) return new \WP_Error( 'kiwe_image_forbidden', 'Your role cannot change images.', [ 'status'=>403 ] );
  if ( $id && ( ! wp_attachment_is_image( $id ) || ! current_user_can( 'edit_post', $id ) ) ) return new \WP_Error( 'kiwe_image_forbidden', 'Choose an image you can manage in the media library.', [ 'status'=>403 ] );
  return $id;
 }
 public static function image( int $id ): array { return [ 'id'=>$id, 'url'=>$id ? (string) ( wp_get_attachment_image_url( $id, 'medium' ) ?: '' ) : '' ]; }
 public static function enqueue(): void { if ( current_user_can( 'upload_files' ) ) wp_enqueue_media(); }
 private static bool $pwa_printed=false;
 public static function pwa_head(): void {
  if(self::$pwa_printed||!is_user_logged_in()||!current_user_can('read')||!Workspace_Admin_Service::uses_workspace(wp_get_current_user()))return;
  self::$pwa_printed=true;
  (new \DSA\PWA\PWA_Service(new \DSA\Settings()))->print_head_tags();
  wp_enqueue_script('kiwe-workspace-app-use',DSA_URL.'assets/js/workspace-app-use.js',[],DSA_VERSION,true);
  wp_localize_script('kiwe-workspace-app-use','KIWE_WORKSPACE_APP_USE',['endpoint'=>rest_url('dsa/v1/app/activity'),'nonce'=>wp_create_nonce('wp_rest'),'actor'=>get_current_user_id()]);
  wp_print_scripts(['kiwe-workspace-app-use']);
 }
 public static function styles(): void { self::pwa_head(); if ( current_user_can( 'upload_files' ) ) wp_print_styles( [ 'common', 'forms', 'buttons', 'media-views', 'imgareaselect', 'wp-mediaelement' ] ); }
 public static function footer(): void {
  if ( ! current_user_can( 'upload_files' ) ) return;
  echo '<script>var ajaxurl=' . wp_json_encode( admin_url( 'admin-ajax.php', 'relative' ), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT ) . ';</script>';
  wp_print_scripts( [ 'media-editor', 'media-views', 'media-audiovideo', 'mce-view', 'image-edit' ] );
  wp_print_media_templates();
 }
}
