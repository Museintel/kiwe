<?php
namespace DSA\MCP;
if (!defined('ABSPATH')) exit;

use DSA\Apps\MCP_Provider_Contract as Contract;
use DSA\Apps\Runtime_Registry;

/** Lazy, local-code providers. Resolving a schema never grants execution or external consent. */
final class Operation_Registry {
 private array $providers=[];
 private array $operations=[];
 private array $setup=[];
 private array $unavailable=[];
 private bool $resolved=false;
 public function register(string $app,int $api,callable $factory): bool {
  if($this->resolved || count($this->providers)>=24 || !Contract::compatible($api) || !Contract::id($app)
    || isset($this->providers[$app]) || !Runtime_Registry::installed($app))return false;
  $this->providers[$app]=$factory;return true;
 }
 private function active(string $id): bool {
  try{$owner=Runtime_Registry::owner($id);return $owner::enabled();}catch(\Throwable){return false;}
 }
 /** Called on explicit MCP/connection administration only. Factories must return bounded projections. */
 public function resolve(): void {
  if($this->resolved)return;$this->resolved=true;
  foreach($this->providers as$app=>$factory){
   if(!$this->active($app))continue;
   try{
    $packet=$factory();
    if(!is_array($packet)||($packet['schema']??'')!==Contract::SCHEMA||($packet['api']??null)!==Contract::API
      ||($packet['app']??null)!==$app||!is_array($packet['operations']??null)||count($packet['operations'])>64
      ||!is_array($packet['setup']??null)||!in_array($packet['setup']['state']??null,Contract::SETUP_STATES,true))throw new \RuntimeException('Invalid provider contract');
    $staged=[];
    foreach($packet['operations']as$id=>$op){
     if(!is_string($id)||!Contract::operation($id,$app)||!is_array($op)||!is_string($op['scope']??null)
      ||!Contract::operation($op['scope'],$app)||!is_string($op['label']??null)||strlen($op['label'])>120
      ||!in_array($op['effect']??null,['read','write'],true)||!in_array($op['approval']??null,['none','required'],true)
      ||!is_int($op['accessVersion']??null)||$op['accessVersion']<1
      ||!Schema::contract($op['inputSchema']??null)||!Schema::contract($op['outputSchema']??null)
      ||!is_callable($op['discoverPermission']??null)||!is_callable($op['permission']??null)||!is_callable($op['execute']??null))throw new \RuntimeException('Invalid operation contract');
     // Future writes are callable only with a real native plan and state owner.
     if($op['effect']==='write'&&(!is_callable($op['prepare']??null)||!is_callable($op['current']??null)))throw new \RuntimeException('Native write review unavailable');
     $staged[$id]=$op+['app'=>$app];
    }
    if(count($this->operations)+count($staged)>256)throw new \RuntimeException('Operation budget exceeded');
    $needs=$packet['setup']['needs']??[];
    if(!is_array($needs)||count($needs)>16||array_filter($needs,static fn($v)=>!is_string($v)||strlen($v)>100||preg_match('/^[a-zA-Z][a-zA-Z0-9_.-]*$/D',$v)!==1))throw new \RuntimeException('Invalid setup references');
    $this->setup[$app]=['app'=>$app,'state'=>$packet['setup']['state'],'needs'=>array_values($needs),'providerConsentRequired'=>($packet['setup']['providerConsentRequired']??false)===true];
    $this->operations+=$staged;
   }catch(\Throwable){$this->unavailable[$app]='unavailable';}
  }
 }
 /** Only implemented, active native contracts can be separately consented. */
 public function scopes(): array { $this->resolve();return array_values(array_unique(array_column(array_filter($this->operations,fn($op)=>$this->active($op['app'])),'scope'))); }
 private function pin(array $op): string { return hash('sha256',wp_json_encode([$op['app'],$op['scope'],$op['effect'],$op['approval'],$op['accessVersion'],$op['inputSchema'],$op['outputSchema']])); }
 public function freeze(array $granted): array { $out=[];foreach($this->describe($granted)as$op)$out[$op['id']]=$this->pin($this->operations[$op['id']]);return$out; }
 /** Exchange can only retain earlier pins; this does not execute an actor's permission callback. */
 public function retain(array $pins,array $scopes): array {
  $this->resolve();$out=[];foreach($pins as$id=>$pin){$op=$this->operations[$id]??null;
   if($op&&$this->active($op['app'])&&in_array($op['scope'],$scopes,true)&&is_string($pin)&&hash_equals($this->pin($op),$pin))$out[$id]=$pin;
  }return$out;
 }
 public function describe(array $granted,?array $pins=null): array {
  $this->resolve();$out=[];
  foreach($this->operations as$id=>$op){if(!$this->active($op['app'])||!in_array($op['scope'],$granted,true))continue;
   if($pins!==null&&(!is_string($pins[$id]??null)||!hash_equals($this->pin($op),$pins[$id])))continue;
   try{if(($op['discoverPermission'])()!==true)continue;}catch(\Throwable){continue;}
   $out[]=['id'=>$id,'app'=>$op['app'],'scope'=>$op['scope'],'label'=>$op['label'],'effect'=>$op['effect'],'approval'=>$op['approval'],'inputSchema'=>Schema::wire($op['inputSchema']),'outputSchema'=>Schema::wire($op['outputSchema'])];
  }return $out;
 }
 public function setup(array $granted,?array $pins=null): array {
  $visible=array_unique(array_column($this->describe($granted,$pins),'app'));
  return array_values(array_filter($this->setup,fn($item)=>in_array($item['app'],$visible,true)&&$this->active($item['app'])));
 }
 /** The read transport cannot execute writes in this first connectable batch. */
 public function read(string $id,array $args,array $granted,array $pins) {
  $this->resolve();$op=$this->operations[$id]??null;
  if(!$op||$op['effect']!=='read'||!$this->active($op['app'])||!in_array($op['scope'],$granted,true)
   ||!is_string($pins[$id]??null)||!hash_equals($this->pin($op),$pins[$id]))return new \WP_Error('kiwe_mcp_unavailable','That operation needs a current website connection and permission.');
  try{
   if(($op['permission'])($args)!==true)return new \WP_Error('kiwe_mcp_forbidden','Your current website permissions do not allow this action.');
   if(!Schema::value($args,$op['inputSchema']))return new \WP_Error('kiwe_mcp_input','Review the operation inputs.');
   $result=($op['execute'])($args);
   if(is_wp_error($result))return new \WP_Error('kiwe_mcp_result','The native operation could not complete.');
   $json=wp_json_encode($result);
   if(!is_array($result)||!is_string($json)||strlen($json)>65536||!Schema::value($result,$op['outputSchema']))return new \WP_Error('kiwe_mcp_result','The operation result is unavailable.');
   return$result;
  }catch(\Throwable){return new \WP_Error('kiwe_mcp_unavailable','The operation is temporarily unavailable.');}
 }
 /** Native write admission, not approval and not a remote generic executor. */
 public function write_contract(string $id,array $args,array $granted,array $pins): array {
  $this->resolve();$op=$this->operations[$id]??null;
  if(!$op||$op['effect']!=='write'||!$this->active($op['app'])||!in_array($op['scope'],$granted,true)||!is_string($pins[$id]??null)||!hash_equals($this->pin($op),$pins[$id]))throw new \RuntimeException('This change needs a current connection with the matching setup permission.');
  if(!Schema::value($args,$op['inputSchema']))throw new \RuntimeException('Review the exact inputs described by Kiwe.');
  if(($op['discoverPermission'])()!==true||($op['permission'])($args)!==true)throw new \RuntimeException('Your current website role cannot make this change.');
  return $op;
 }
 public function prepare_change(string $id,array $args,array $granted,array $pins): array {
  $op=$this->write_contract($id,$args,$granted,$pins);$plan=($op['prepare'])($args);
  if(!is_array($plan)||!is_string($plan['nativeExpected']??null)||preg_match('/^[a-f0-9]{64}$/D',$plan['nativeExpected'])!==1||!is_bool($plan['needsApproval']??null))throw new \RuntimeException('The native change review is unavailable.');
  foreach(['summary','before','after']as$key)if(!is_string($plan[$key]??null)||strlen($plan[$key])>2400)throw new \RuntimeException('The native change review exceeds its display budget.');
  if(!is_string(wp_json_encode($plan))||strlen(wp_json_encode($plan))>6000)throw new \RuntimeException('The change review exceeds its storage budget.');
  $plan['needsApproval']=$op['approval']==='required'||$plan['needsApproval'];return$plan;
 }
 public function current_change(string $id,array $args,array $granted,array $pins): string {
  $op=$this->write_contract($id,$args,$granted,$pins);$value=($op['current'])($args);
  if(!is_string($value)||preg_match('/^[a-f0-9]{64}$/D',$value)!==1)throw new \RuntimeException('The current native state is unavailable.');return$value;
 }
 /** Only Intent_Service calls this after a server-owned intent/approval claim. */
 public function execute_prepared(string $id,array $args,string $expected,array $granted,array $pins): array {
  $op=$this->write_contract($id,$args,$granted,$pins);
  if(!hash_equals($this->current_change($id,$args,$granted,$pins),$expected))throw new \RuntimeException('The website changed. Review a fresh plan before applying it.');
  $result=($op['execute'])($args,$expected);if(is_wp_error($result))throw new \RuntimeException($result->get_error_message());
  if(!is_array($result)||!Schema::value($result,$op['outputSchema'])||strlen((string)wp_json_encode($result))>8000)throw new \RuntimeException('The native change needs readback review; do not repeat it blindly.');return$result;
 }
}
