<?php
namespace DSA\Apps;
if ( !defined( 'ABSPATH' ) ) exit;

/** Native fallback shared by every local App card; interactive cards use the same projection. */
final class App_Card_Service {
 public static function render( array $task ): void {
  $app=$task['app']; $label=$task['label'];
  $action=$app['actionName'] ?? ('store' === $app['id'] ? Store_App_Service::ACTION : SEO_App_Service::ACTION);
  echo '<article class="kiwe-shell-tile kiwe-shell-app-card ' . ( $app['enabled'] ? 'apricot' : 'is-off' ) . '">';
  if ( $app['url'] ) echo '<a class="kiwe-shell-app-body" href="' . esc_url( $app['url'] ) . '">'; else echo '<div class="kiwe-shell-app-body">';
  echo '<strong>' . esc_html( $label ) . '</strong><span>' . esc_html( $app['description'] ) . '</span>' . ( $app['url'] ? '</a>' : '</div>' );
  echo '<div class="kiwe-shell-app-control"><span>' . esc_html( $app['status'] ) . '</span>';
  if ( $app['canChange'] && empty( $app['externalOwner'] ) && empty( $app['blocked'] ) ) {
   echo '<form method="post" action="' . esc_url( $app['action'] ) . '"><input type="hidden" name="action" value="' . esc_attr( $action ) . '"><input type="hidden" name="expected" value="' . esc_attr( $app['expected'] ) . '"><input type="hidden" name="_wpnonce" value="' . esc_attr( $app['nonce'] ) . '"><input type="hidden" name="enabled" value="' . ( $app['enabled'] ? '0' : '1' ) . '"><button type="submit">' . esc_html( 'Turn ' . $label . ( $app['enabled'] ? ' off' : ' on' ) ) . '</button></form>';
  }
  if ( !empty($app['manageUrl']) ) echo '<a href="' . esc_url($app['manageUrl']) . '">' . esc_html( empty($app['installed']) ? 'Add App ↗' : 'App details ↗' ) . '</a>';
  echo '</div></article>';
 }
}
