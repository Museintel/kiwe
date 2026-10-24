<?php
declare(strict_types=1);
define('ABSPATH',__DIR__.'/');
class WP_Post { public function __construct(public string $post_type) {} }
$deleted=[];$hooks=[];
function add_action(string $name,$callback,int $priority=10,int $args=1): void {$GLOBALS['hooks'][$name]=[$callback,$priority,$args];}
function delete_metadata(string $type,int $id,string $key): bool {$GLOBALS['deleted'][]=[$type,$id,$key];return true;}
function delete_post_meta(int $id,string $key): bool {throw new RuntimeException('Revision-redirecting metadata API must not run during deletion.');}
function check(bool $ok,string $label): void {if(!$ok)throw new RuntimeException($label);echo 'PASS '.$label.PHP_EOL;}
require dirname(__DIR__,2).'/wp-content/mu-plugins/dsa/includes/Element_Registry.php';
$registry=new DSA\Element_Registry();$registry->register();
check($hooks['deleted_post'][2]===2,'native deletion receives the deleted object after its row disappears');
$registry->delete_snapshot(82,new WP_Post('revision'));
check($deleted===[],'revision deletion never redirects snapshot removal to its parent');
$registry->delete_snapshot(94,new WP_Post('kiwe_service'));
check($deleted===[['post',94,DSA\Element_Registry::SNAPSHOT_META_KEY]],'record deletion removes only that exact record snapshot');
$registry->delete_snapshot(82);$registry->delete_snapshot(0);
check(count($deleted)===2&&$deleted[1][1]===82,'direct cache retirement also uses the exact ID and refuses invalid IDs');
