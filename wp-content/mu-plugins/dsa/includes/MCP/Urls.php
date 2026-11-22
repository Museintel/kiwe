<?php
namespace DSA\MCP;
if (!defined('ABSPATH')) exit;

final class Urls {
 public const NS='kiwe-mcp/v1';
 public static function resource(): string { return untrailingslashit(rest_url(self::NS.'/mcp')); }
 public static function issuer(): string { return untrailingslashit(home_url('/')); }
 public static function endpoint(string $name): string { return rest_url(self::NS.'/'.$name); }
 public static function authorize(): string { return add_query_arg('action','kiwe_mcp_authorize',admin_url('admin-post.php')); }
 public static function secure(): bool { return wp_parse_url(self::issuer(),PHP_URL_SCHEME)==='https' && wp_parse_url(self::resource(),PHP_URL_SCHEME)==='https'; }
 public static function request_host_matches(): bool {
  $host=$_SERVER['HTTP_HOST']??'';$parts=wp_parse_url(self::resource());
  if(!is_string($host)||!is_array($parts)||!isset($parts['host']))return false;
  $expected=strtolower($parts['host']).(isset($parts['port'])&&$parts['port']!==443?':'.$parts['port']:'');
  return hash_equals($expected,strtolower($host));
 }
 public static function binding(): string {
  return hash_hmac('sha256',self::issuer().'|'.self::resource().'|'.(string)get_option('kiwe_mcp_revocation_epoch_v1','uninitialized'),wp_salt('auth'));
 }
 /** Redirects are identifiers, not fetch targets. No registration URL is requested. */
 public static function valid_redirect(string $url): bool {
  if(strlen($url)>2048 || preg_match('/[\x00-\x20\x7f\\\\]|%0[ad]|%00/i',$url))return false;
  $parts=parse_url($url);
  if(!is_array($parts)||isset($parts['user'])||isset($parts['pass'])||isset($parts['fragment'])||!isset($parts['scheme'],$parts['host']))return false;
  $scheme=strtolower($parts['scheme']);$host=strtolower($parts['host']);
  $loopback=in_array($host,['127.0.0.1','localhost','[::1]'],true);
  if(isset($parts['port'])&&($parts['port']<1||$parts['port']>65535))return false;
  if($scheme==='http')return $loopback;
  return $scheme==='https' && preg_match('/^[a-z0-9][a-z0-9.-]*[a-z0-9]$/D',$host)===1 && !str_contains($host,'..');
 }
 /** RFC8252 permits an ephemeral port only for a registered native-loopback URI. */
 public static function redirect_matches(string $requested,array $registered): bool {
  if(!self::valid_redirect($requested))return false;
  foreach($registered as$known){
   if(!is_string($known)||!self::valid_redirect($known))continue;
   if(hash_equals($known,$requested))return true;
   $a=parse_url($known);$b=parse_url($requested);
   if(($a['scheme']??'')!=='http'||isset($a['port'])||!in_array(strtolower($a['host']??''),['127.0.0.1','localhost','[::1]'],true))continue;
   unset($b['port']);if($a===$b)return true;
  }return false;
 }
 public static function challenge(string $error='',array $scopes=[]): string {
  $value='Bearer resource_metadata="'.self::endpoint('resource-metadata').'"';
  if(in_array($error,['invalid_token','insufficient_scope'],true))$value.=', error="'.$error.'"';
  if($scopes)$value.=', scope="'.implode(' ',array_filter($scopes,static fn($s)=>is_string($s)&&preg_match('/^[a-z][a-z0-9._-]{0,79}$/D',$s))).'"';
  return$value;
 }
}
