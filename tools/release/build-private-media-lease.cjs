const fs=require('node:fs'),path=require('node:path'),crypto=require('node:crypto');
const repo=path.resolve(__dirname,'../..'),arg=n=>{const v=process.argv.find(x=>x.startsWith('--'+n+'='));if(!v)throw Error('Required '+n);return v.slice(n.length+3);};
const root=arg('root'),id=arg('id'),output=arg('output');
if(!/^\/home\/u\d+\/domains\/ascendants\.in\/public_html$/.test(root)||!/^kiwe-[a-z0-9-]+$/.test(id)||!/^kiwe-[a-z0-9-]+\.php$/.test(path.basename(output)))throw Error('Exact original-site lease scope required');
const cfg={root,private:path.posix.dirname(root)+'/kiwe-private-backups/'+id};
const q=x=>"'"+x.replaceAll('\\','\\\\').replaceAll("'","\\'")+"'";
const factory=fs.readFileSync(path.join(repo,'tools/release/private-media-lease-guard.php'),'utf8').replaceAll('\r\n','\n');
const guard="<?php\nif(!defined('ABSPATH')){http_response_code(404);exit;}\n$runner=(static function(){\n"+factory.replace(/^<\?php\s*/,'')+"\n})();$runner(json_decode("+q(JSON.stringify(cfg))+",true));\n";
const sha=crypto.createHash('sha256').update(guard).digest('hex');
const body=`<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
$c=json_decode(${q(JSON.stringify(cfg))},true);$guard=${q(guard)};$guardSha=${q(sha)};$target=$c['root'].'/wp-content/mu-plugins/kiwe-backup-media-lease.php';
$p=['schema'=>'kiwe.private-original-media-lease-control.v1','passed'=>false,'cleanup'=>false,'checks'=>[]];
$check=static function(bool$ok,string$name)use(&$p){$p['checks'][$name]=$ok;if(!$ok)throw new RuntimeException($name);};
$write=static function(string$file,string$bytes,bool$fresh=true){$s=fopen($file,$fresh?'x+b':'r+b');if(!$s||!chmod($file,0600)||(!$fresh&&!ftruncate($s,0)))throw new RuntimeException('Private lease write refused');$at=0;while($at<strlen($bytes)){$n=fwrite($s,substr($bytes,$at,65536));if(!$n)throw new RuntimeException('Private lease write incomplete');$at+=$n;}if(!fflush($s)||!fsync($s))throw new RuntimeException('Private lease sync refused');fclose($s);};
try{
 $action=$argv[1]??'--start';
 if($action==='--start'||$action==='--prepare'){
  $check(realpath(__DIR__)===$c['root']&&!file_exists($target)&&!is_dir($c['private']),'fresh exact original media lease scope');
  if(!mkdir($c['private'],0700)||!chmod($c['private'],0700)||!rename(__FILE__,$c['private'].'/media-lease-control.php')||!chmod($c['private'].'/media-lease-control.php',0600))throw new RuntimeException('Private lease entry retirement refused');
  $active=$action==='--start';$issued=time();
  $state=['schema'=>'kiwe.private-media-lease.v1','root'=>$c['root'],'active'=>$active,'issuedAt'=>$issued,'expiresAt'=>$active?$issued+5400:0,'configurationSha256'=>hash_file('sha256',$c['root'].'/wp-config.php'),'loaderSha256'=>hash_file('sha256',$c['root'].'/wp-content/mu-plugins/dsa.php'),'guardSha256'=>$guardSha];
  $write($c['private'].'/media-lease.private.json',json_encode($state,JSON_THROW_ON_ERROR));$write($target,$guard);$check(hash_file('sha256',$target)===$guardSha,'exact temporary guard installed');
 }else{
  $check(realpath(__DIR__)===$c['private'],'exact private control scope');$state=json_decode(file_get_contents($c['private'].'/media-lease.private.json'),true,512,JSON_THROW_ON_ERROR);
  $check($state['root']===$c['root']&&$state['guardSha256']===$guardSha,'exact recorded media lease owner');
 }
 if($action==='--activate'){
  $check($state['active']===false&&$state['expiresAt']===0&&!isset($state['releasedAt']),'only fresh inactive media guard may activate');
  $check(is_file($target)&&!is_link($target)&&hash_file('sha256',$target)===$guardSha,'prepared guard bytes remain unchanged');
  $check(hash_file('sha256',$c['root'].'/wp-config.php')===$state['configurationSha256']&&hash_file('sha256',$c['root'].'/wp-content/mu-plugins/dsa.php')===$state['loaderSha256'],'original configuration and loader preserved before activation');
  $state['active']=true;$state['issuedAt']=$state['activatedAt']=time();$state['expiresAt']=$state['issuedAt']+5400;
  $write($c['private'].'/media-lease.private.json',json_encode($state,JSON_THROW_ON_ERROR),false);$action='--probe';
 }
 if($action==='--release'){
  $check(is_file($target)&&!is_link($target)&&hash_file('sha256',$target)===$guardSha,'only unchanged owned guard may be retired');
  $state['active']=false;$state['releasedAt']=time();$write($c['private'].'/media-lease.private.json',json_encode($state,JSON_THROW_ON_ERROR),false);
  $check(unlink($target)&&!file_exists($target),'owned public media guard retired');$p['cleanup']=true;
 }elseif($action==='--prepare'){
  $check($state['active']===false&&$state['expiresAt']===0,'prepared media guard is inactive');
  define('ABSPATH',$c['root'].'/');require_once ABSPATH.'wp-includes/plugin.php';require_once ABSPATH.'wp-includes/class-wp-error.php';require$target;
  $sample=['name'=>'synthetic.jpeg'];
  $check(apply_filters('wp_handle_upload_prefilter',$sample)===$sample,'prepared guard leaves upload prefilter unchanged');
  $check(apply_filters('wp_image_editors',['WP_Image_Editor_GD'])===['WP_Image_Editor_GD'],'prepared guard leaves image editor unchanged');
  $media=new class{function get_route(){return'/wp/v2/media';}function get_method(){return'POST';}};
  $check(apply_filters('rest_pre_dispatch',null,null,$media)===null,'prepared guard leaves media request unchanged');
  $check(!defined('DB_NAME'),'inactive probe does not load connection or WordPress settings');
  $p['cleanup']=true;$p['scope']='guard-prepared-inactive-native-hooks-only';
 }elseif($action==='--probe'||$action==='--start'){
  $check($state['active']===true&&$state['expiresAt']>time(),'bounded media lease is active');
  define('ABSPATH',$c['root'].'/');require_once ABSPATH.'wp-includes/plugin.php';require_once ABSPATH.'wp-includes/class-wp-error.php';require$target;
  $check(isset(apply_filters('wp_handle_upload_prefilter',['name'=>'synthetic.jpeg'])['error']),'native upload prefilter pauses new media');
  $check(isset(apply_filters('wp_handle_sideload_prefilter',['name'=>'synthetic.jpeg'])['error']),'native sideload prefilter pauses imports');
  $check(apply_filters('pre_delete_attachment',null)===false,'native attachment deletion pauses');
  $check(apply_filters('wp_image_editors',['WP_Image_Editor_GD'])===[],'native derived image writes pause');
  $check(apply_filters('compressx_allowed_image_auto_optimization',true)===false,'CompressX native auto optimization pauses without settings mutation');
  $media=new class{function get_route(){return'/wp/v2/media';}function get_method(){return'POST';}};$post=new class{function get_route(){return'/wp/v2/posts';}function get_method(){return'POST';}};
  $check(apply_filters('rest_pre_dispatch',null,null,$media) instanceof WP_Error,'native REST media mutation pauses');
  $check(apply_filters('rest_pre_dispatch',null,null,$post)===null,'ordinary post request is unchanged');
  $check(!defined('DB_NAME'),'native hook probe does not load connection or WordPress settings');
  $p['cleanup']=true;$p['scope']='guard-installed-native-hooks-only';
 }else throw new RuntimeException('Known lease action required');
 $p['configurationUnchanged']=hash_file('sha256',$c['root'].'/wp-config.php')===$state['configurationSha256'];$p['loaderUnchanged']=hash_file('sha256',$c['root'].'/wp-content/mu-plugins/dsa.php')===$state['loaderSha256'];$p['guardSha256']=$guardSha;$p['leaseActive']=$state['active'];$p['expiresAt']=$state['expiresAt'];$p['passed']=true;
}catch(Throwable$e){$p['error']='Original media lease action did not complete';$p['errorClass']=get_class($e);}
$json=json_encode($p,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);if(is_dir($c['private'])){file_put_contents($c['private'].'/lease-'.preg_replace('/[^a-z]/','',$argv[1]??'start').'-receipt.json',$json);chmod($c['private'].'/lease-'.preg_replace('/[^a-z]/','',$argv[1]??'start').'-receipt.json',0600);}echo$json;exit($p['passed']?0:1);
`;
fs.writeFileSync(output,body,{flag:'wx'});console.log(JSON.stringify({path:output,bytes:Buffer.byteLength(body),sha256:crypto.createHash('sha256').update(body).digest('hex'),guardSha256:sha,automaticExpirySeconds:5400}));
