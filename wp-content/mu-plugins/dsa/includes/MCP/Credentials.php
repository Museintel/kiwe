<?php
namespace DSA\MCP;
if (!defined('ABSPATH')) exit;

/** Opaque resource-only tokens. No JWTs, application passwords or general WP REST login. */
final class Credentials {
 public static function secret(): string { return rtrim(strtr(base64_encode(random_bytes(32)),'+/','-_'),'='); }
 public static function id(string $kind): string { return $kind.'_'.bin2hex(random_bytes(16)); }
 public static function digest(string $secret): string { return hash('sha256',$secret); }
 public static function token(string $kind,string $id,string $secret): string { return 'kiwe_mcp_'.$kind.'_'.$id.'.'.$secret; }
 public static function parse(string $token,string $kind): ?array {
  if(strlen($token)>180||preg_match('/^kiwe_mcp_'.preg_quote($kind,'/').'_(g_[a-f0-9]{32}|c_[a-f0-9]{32})\.([A-Za-z0-9_-]{43})$/D',$token,$m)!==1)return null;
  if(($kind==='code'&&!str_starts_with($m[1],'c_'))||($kind!=='code'&&!str_starts_with($m[1],'g_')))return null;
  return['id'=>$m[1],'secret'=>$m[2]];
 }
 public static function matches(string $secret,$hash): bool { return is_string($hash)&&strlen($hash)===64&&hash_equals($hash,self::digest($secret)); }
 public static function pkce(string $verifier,string $challenge): bool {
  if(preg_match('/^[A-Za-z0-9._~-]{43,128}$/D',$verifier)!==1||preg_match('/^[A-Za-z0-9_-]{43}$/D',$challenge)!==1)return false;
  return hash_equals($challenge,rtrim(strtr(base64_encode(hash('sha256',$verifier,true)),'+/','-_'),'='));
 }
 public static function actor_allowed(int $id): bool {
  return$id>0 && get_userdata($id) && !\DSA\Access\Team_Workspace_Service::suspended($id)
   && (user_can($id,'kiwe_manage_context')||user_can($id,'edit_posts')||user_can($id,'edit_pages')||user_can($id,'edit_products'));
 }
 public static function actor_pin(int $id): string { $user=get_userdata($id);return$user?hash_hmac('sha256',(string)$user->user_pass,wp_salt('auth')):''; }
 public static function actor_current(array $grant,int $id): bool {
  return self::actor_allowed($id)&&is_string($grant['actorPin']??null)&&hash_equals(self::actor_pin($id),$grant['actorPin'])
   &&is_string($grant['binding']??null)&&hash_equals(Urls::binding(),$grant['binding'])&&($grant['active']??null)===true;
 }
}
