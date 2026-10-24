<?php
namespace DSA\Apps;
if (!defined('ABSPATH')) exit;

/** Only native update work loads the download, inventory and recovery implementations. */
final class Update_Hooks {
 public function register(): void {
  add_filter('update_plugins_app.kiwelaunch.com',[Native_Update_Service::class,'offer'],10,4);
  add_filter('upgrader_pre_download',[Native_Update_Service::class,'download'],10,4);
  add_filter('upgrader_post_install',[Native_Update_Service::class,'installed'],100,3);
  add_action('upgrader_process_complete',[Native_Update_Service::class,'complete'],100,2);
 }
}
