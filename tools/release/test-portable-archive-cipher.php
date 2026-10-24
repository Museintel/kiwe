<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(2);
if(!defined('ABSPATH'))define('ABSPATH',__DIR__.'/');
if(!function_exists('wp_json_encode')) { function wp_json_encode($v,$flags=0){return json_encode($v,$flags);} }
if(!function_exists('untrailingslashit')) { function untrailingslashit($v){return rtrim($v,'/');} }
if(!function_exists('home_url')) { function home_url($path='/'){return $GLOBALS['site'].'/';} }
if(!function_exists('get_current_blog_id')) { function get_current_blog_id(){return 1;} }
$GLOBALS['site']='https://source.example.invalid';
require __DIR__.'/../../wp-content/mu-plugins/dsa/includes/Backup/Archive_Error.php';
require __DIR__.'/../../wp-content/mu-plugins/dsa/includes/Backup/Archive_Cipher.php';
require __DIR__.'/../../wp-content/mu-plugins/dsa/includes/Backup/Portable_Archive_Cipher.php';
use DSA\Backup\Portable_Archive_Cipher as Cipher;
use DSA\Backup\Archive_Cipher as SameSite;
$proof=['schema'=>'kiwe.portable-frame-proof.v1','passed'=>false,'cleanup'=>true,'checks'=>[]];
$check=static function(bool $ok,string $label)use(&$proof){$proof['checks'][$label]=$ok;if(!$ok)throw new RuntimeException($label);};
$reject=static function(callable $fn,string $label)use($check){try{$fn();}catch(DSA\Backup\Archive_Error $e){$check(true,$label);return;}throw new RuntimeException('Expected refusal: '.$label);};
try{
 $phrase='Synthetic portable QA phrase only';$header=Cipher::new_header();$source=new Cipher($header,$phrase);
 $check(count($header)===4&&!str_contains(json_encode($header),'source.example.invalid')&&!str_contains(json_encode($header),$phrase),'public header excludes source identity and key');
 $payloads=['',"literal \0 byte ".chr(255),str_repeat('compressible ',5000),random_bytes(Cipher::CHUNK_BYTES)];
 foreach($payloads as$i=>$payload){$frame=$source->seal($payload,$i+1,'data');$check($source->open($frame,$i+1,'data')===$payload,'round trip bounded binary payload '.$i);}
 $frame=$source->seal('Portable changed destination proof',7,'data');$GLOBALS['site']='https://destination.example.invalid';$destination=new Cipher($header,$phrase);
 $check($destination->open($frame,7,'data')==='Portable changed destination proof','same frame opens on a different destination');
 $index=$source->seal('{"complete":true}',0,'index');$check($destination->open($index,0,'index')==='{"complete":true}','encrypted index portable round trip');
 $reordered=array_reverse($header,true);$reordered['crypto']=array_reverse($header['crypto'],true);$check((new Cipher($reordered,$phrase))->open($frame,7,'data')==='Portable changed destination proof','JSON property order canonicalized');
 $reject(static fn()=>(new Cipher($header,'Wrong synthetic phrase only'))->open($frame,7,'data'),'wrong passphrase refused');
 $reject(static fn()=>$destination->open($frame,8,'data'),'moved frame refused');
 $reject(static fn()=>$destination->open($frame,7,'manifest'),'cross-purpose frame refused');
 $changed=$header;$changed['archiveId']=str_repeat('a',32);$reject(static fn()=>(new Cipher($changed,$phrase))->open($frame,7,'data'),'different archive header refused');
 $changed=$header;$changed['crypto']['salt']=base64_encode(random_bytes(16));$reject(static fn()=>(new Cipher($changed,$phrase))->open($frame,7,'data'),'different KDF salt refused');
 foreach([0,8,12,16,17,29,44,45,strlen($frame)-1]as$offset){$bad=$frame;$bad[$offset]=chr(ord($bad[$offset])^1);$reject(static fn()=>$destination->open($bad,7,'data'),'tampered frame byte '.$offset.' refused');}
 foreach([0,12,44,strlen($frame)-1]as$length)$reject(static fn()=>$destination->open(substr($frame,0,$length),7,'data'),'truncated frame length '.$length.' refused');
 $reject(static fn()=>$destination->open($frame.'added',7,'data'),'appended bytes refused');
 $reject(static fn()=>$source->seal(str_repeat('x',Cipher::CHUNK_BYTES+1),1,'data'),'oversized plaintext refused');
 $reject(static fn()=>$destination->open(str_repeat('x',Cipher::CHUNK_BYTES+46),1,'data'),'oversized encrypted input refused');
 foreach([-1,2147483648]as$position)$reject(static fn()=>$source->seal('x',$position,'data'),'out of range position '.$position.' refused');
 $reject(static fn()=>$source->seal('x',0,'data'),'data cannot replace index position');
 $reject(static fn()=>$source->seal('x',1,'index'),'index cannot replace data position');
 $reject(static fn()=>$source->seal('x',1,'unknown'),'unknown purpose refused');
 foreach(['short',str_repeat('x',1025)]as$i=>$p)$reject(static fn()=>new Cipher($header,$p),'invalid phrase length '.$i.' refused');
 foreach(['schema','archiveId','crypto','chunkBytes']as$key){$bad=$header;unset($bad[$key]);$reject(static fn()=>new Cipher($bad,$phrase),'missing header field '.$key.' refused');}
 $cases=[];$bad=$header;$bad['extra']='injected';$cases[]=$bad;$bad=$header;$bad['schema']='unknown';$cases[]=$bad;$bad=$header;$bad['chunkBytes']=PHP_INT_MAX;$cases[]=$bad;$bad=$header;$bad['archiveId']='../unsafe';$cases[]=$bad;$bad=$header;$bad['crypto']['iterations']=PHP_INT_MAX;$cases[]=$bad;$bad=$header;$bad['crypto']['iterations']=(string)$bad['crypto']['iterations'];$cases[]=$bad;$bad=$header;$bad['crypto']['salt']='invalid';$cases[]=$bad;$bad=$header;$bad['crypto']['salt']=base64_encode(random_bytes(15));$cases[]=$bad;$bad=$header;$bad['crypto']['extra']='injected';$cases[]=$bad;
 foreach($cases as$i=>$bad)$reject(static fn()=>new Cipher($bad,$phrase),'unsupported bounded header '.$i.' refused before KDF');
 $reject(static fn()=>serialize($source),'key context cannot be persisted');$reject(static fn()=>clone $source,'key context cannot be cloned');
 ob_start();var_dump($source);$debug=ob_get_clean();$check(!str_contains($debug,$phrase)&&str_contains($debug,'[private]'),'debug projection redacts private context');
 $same=SameSite::seal(['value'=>'same site'], 'qa.same-site.v1',$phrase,[],2097152);$GLOBALS['site']='https://third.example.invalid';
 try{SameSite::open($same,'qa.same-site.v1',$phrase,2097152);throw new RuntimeException('Same-site format weakened');}catch(RuntimeException $e){$check(str_contains($e->getMessage(),'not an archive for this website'),'existing selective archive remains bound to its source site');}
 $compressed=$source->seal(str_repeat('x',Cipher::CHUNK_BYTES),12,'data');$check(strlen($compressed)<Cipher::CHUNK_BYTES&&$destination->open($compressed,12,'data')===str_repeat('x',Cipher::CHUNK_BYTES),'compression occurs before encryption with bounded expansion');
 $proof['passed']=true;
}catch(Throwable $e){$proof['error']=$e->getMessage();}
echo json_encode($proof,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
exit($proof['passed']?0:1);
