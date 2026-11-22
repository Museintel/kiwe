<?php
declare(strict_types=1);

// Rank Math 1.0.280 loads this older namespace before Kiwe's lazy transport.
namespace WP\MCP\Core {
 final class McpAdapter { public const VERSION='0.5.0'; }
}
namespace WP\McpSchema {
 final class Schemas { public const FOREIGN_SCHEMA=true; }
}
namespace {
 define('ABSPATH',__DIR__);
 define('WP_MCP_VERSION','0.5.0');
 $root=dirname(__DIR__,3).'/wp-content/mu-plugins/dsa/vendor/mcp';
 require $root.'/autoload.php';
 $checks=0;
 $check=static function(bool $ok,string $why)use(&$checks):void {
  ++$checks;
  if(!$ok)throw new \RuntimeException($why);
 };
 $check(\WP\MCP\Core\McpAdapter::VERSION==='0.5.0','The previously loaded foreign adapter stays intact');
 $check(\DSA\MCP\Vendor\WP\MCP\Core\McpAdapter::VERSION==='0.7.1','Kiwe loads its pinned private adapter alongside it');
 $check(\WP\McpSchema\Schemas::FOREIGN_SCHEMA===true,'Foreign schema classes stay intact');
 $check(class_exists(\DSA\MCP\Vendor\WP\McpSchema\Schemas::class),'Kiwe loads its own schema catalogue');
 $check(WP_MCP_VERSION==='0.5.0','Foreign public constants stay intact');
 $reflection=new \ReflectionClass(\DSA\MCP\Vendor\WP\MCP\Core\McpAdapter::class);
 $check(str_starts_with(str_replace('\\','/',$reflection->getFileName()),str_replace('\\','/',realpath($root)).'/'),
  'The private adapter resolves inside the verified optional App');
 $adapter=file_get_contents($root.'/wordpress/mcp-adapter/includes/Core/McpAdapter.php');
 $check(str_contains($adapter,"'kiwe_mcp_transport_init'")&&!str_contains($adapter,"'mcp_adapter_init'"),
  'Registration uses Kiwe transport hooks independently of the foreign adapter');
 $plugin=file_get_contents($root.'/wordpress/mcp-adapter/includes/Plugin.php');
 $check(str_contains($plugin,"'KIWE_TRANSPORT_MCP_VERSION'")&&!str_contains($plugin,"'WP_MCP_VERSION'"),
  'Initializing the private plugin cannot redefine another plugin version constant');
 $sessions=file_get_contents($root.'/wordpress/mcp-adapter/includes/Transport/Infrastructure/SessionManager.php');
 $check(str_contains($sessions,"SESSION_META_KEY = 'mcp_adapter_sessions'"),'Previously issued native sessions retain their stored key');
 echo json_encode(['passed'=>true,'checks'=>$checks,'foreignAdapter'=>'0.5.0','privateAdapter'=>'0.7.1']),PHP_EOL;
}
