<?php
declare(strict_types=1);
// Actual owners, synthetic WordPress I/O. No site state, native boot or browser claim.
define('ABSPATH', __DIR__.'/');
define('DSA_URL', 'https://site.invalid/wp-content/mu-plugins/dsa/');
define('DSA_VERSION', 'test');
function sanitize_key($s){return preg_replace('/[^a-z0-9_\-]/','',strtolower((string)$s));}
function wp_strip_all_tags($s){return strip_tags((string)$s);}
function get_option(...$args){throw new RuntimeException('Private token projection must not query site state');}
function wp_style_is($handle,$state){return isset($GLOBALS['styles'][$handle]);}
function wp_enqueue_style($handle,$src,$dependencies=[],$version=null){$GLOBALS['styles'][$handle]=compact('src','dependencies','version');}
function wp_add_inline_style($handle,$css){$GLOBALS['inline'][$handle][]=$css;}
require dirname(__DIR__,2).'/wp-content/mu-plugins/dsa/includes/Design/Seam_Token_Service.php';
require dirname(__DIR__,2).'/wp-content/mu-plugins/dsa/includes/Access/Workspace_Design_Service.php';
use DSA\Design\Seam_Token_Service as Tokens;
use DSA\Access\Workspace_Design_Service as Workspace;
$checks=0;
function verify($value,$message){$GLOBALS['checks']++;if(!$value)throw new RuntimeException($message);}
$css=Workspace::workspace_stylesheet();$registry=Tokens::css_variables();
preg_match('/:where\(\.kiwe-client-shell,\.kiwe-screen\)\{([^}]+)\}/',$css,$match);
verify(!empty($match[1]),'Private workspace roots are present');
foreach(array_keys($registry)as $name)verify(str_contains($match[1],$name.':'),'All canonical tokens are available: '.$name);
foreach(['--kiwe-color-brand:#20352b','--kiwe-color-text:#20352b','--kiwe-color-surface:#f8f7f3','--kiwe-color-border:#d9dfd2','--kiwe-font-body:system-ui,-apple-system,"Segoe UI",sans-serif']as $value)verify(str_contains($match[1],$value),'Existing task palette/font preserved: '.$value);
verify(str_contains($css,'.kiwe-client-shell{--kiwe-color-brand:#212823;--kiwe-color-text:#212823;--kiwe-color-surface:#f7f6f2;--kiwe-color-border:#d9ddcd}'),'PHP Home preserves its established palette');
verify(str_starts_with($css,Tokens::workspace_layout_stylesheet()),'Existing canonical geometry projection remains exact');
verify(!str_contains(substr($css,strlen(Tokens::workspace_layout_stylesheet())),':root'),'Private colours never replace public root tokens');
$styles=[];$inline=[];
$dependencies=Workspace::app_dependencies(['wp-block-library','wp-editor','wp-block-library']);
verify($dependencies===['wp-block-library','wp-editor','kiwe-workspace-foundation'],'Native editor dependencies preserved with unique shared handle');
verify($styles['kiwe-workspace-foundation']['dependencies']===[],'Shared CSS introduces no circular dependency');
verify(Workspace::app_dependencies($dependencies)===$dependencies&&count($styles)===1,'Repeated native consumers reuse a single handle');
Workspace::enqueue();Workspace::enqueue();
verify($styles['kiwe-app-primitives']['dependencies']===['kiwe-workspace-tokens'],'Home/legacy private CSS depends on canonical tokens');
verify(count($inline['kiwe-app-primitives'])===1&&$inline['kiwe-app-primitives'][0]===Workspace::stylesheet(),'Existing legacy palette remains exact and enqueues once');
verify(count($styles)===3,'No unrelated public runtime, script or provider dependency');
$native_classes=Tokens::framework_classes_for_bricks()['classes'];
verify(count(array_unique(array_column($native_classes,'id')))===count($native_classes),'One native Bricks identity per vocabulary class even when reference groups overlap');
verify(count(array_filter($native_classes,static fn($row)=>$row['name']==='seam-print-hidden'))===1,'Shared print utility exports once without losing vocabulary coverage');
echo json_encode(['passed'=>true,'checks'=>$checks,'registryVariables'=>count($registry)]);
