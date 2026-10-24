<?php
declare(strict_types=1);
/** Test dependency only: authenticate an official Woo archive before private fixture allocation. */
if(PHP_SAPI!=='cli')return null;
return static function(string$archive,string$checksums,string$checksumsSha256,string$version):array{
 if(!in_array($version,['11.0.1','11.1.0'],true)||!preg_match('/^[a-f0-9]{64}$/D',$checksumsSha256)||!is_file($checksums)||is_link($checksums)||filesize($checksums)>4000000||hash_file('sha256',$checksums)!==$checksumsSha256||!is_file($archive)||is_link($archive)||filesize($archive)>134217728)throw new RuntimeException('Exact official Woo dependency binding required');
 $manifest=json_decode(file_get_contents($checksums),true,64,JSON_THROW_ON_ERROR);
 if(($manifest['plugin']??null)!=='woocommerce'||($manifest['version']??null)!==$version||($manifest['zip']??null)!=='https://downloads.wordpress.org/plugins/woocommerce.'.$version.'.zip'||($manifest['source']??null)!=='https://plugins.svn.wordpress.org/woocommerce/tags/'.$version.'/'||!is_array($manifest['files']??null)||count($manifest['files'])<1||count($manifest['files'])>20000)throw new RuntimeException('Official Woo checksum identity refused');
 $expected=[];$dirs=['woocommerce'=>true];
 foreach($manifest['files']as$name=>$digests){
  if(!is_string($name)||!preg_match('#^[a-zA-Z0-9_./@+,-]+$#D',$name)||str_starts_with($name,'/')||array_intersect(explode('/',$name),['','.','..'])||!is_array($digests)||!preg_match('/^[a-f0-9]{64}$/D',$digests['sha256']??''))throw new RuntimeException('Official dependency member refused');
  $expected['woocommerce/'.$name]=$digests['sha256'];for($p=dirname('woocommerce/'.$name);$p!=='.';$p=dirname($p))$dirs[$p]=true;
 }
 $zip=new ZipArchive();if($zip->open($archive)!==true)throw new RuntimeException('Official Woo archive unavailable');
 $files=[];$bytes=0;
 try{
  if($zip->numFiles>30000)throw new RuntimeException('Official dependency member budget exceeded');
  for($i=0;$i<$zip->numFiles;++$i){
   $stat=$zip->statIndex($i);if(!is_array($stat)||!is_string($stat['name']))throw new RuntimeException('Official archive member unavailable');$name=$stat['name'];
   $opsys=0;$attributes=0;if(!$zip->getExternalAttributesIndex($i,$opsys,$attributes)||(($attributes>>16)&0170000)===0120000)throw new RuntimeException('Linked dependency member refused');
   if(str_ends_with($name,'/')){if(!isset($dirs[rtrim($name,'/')]))throw new RuntimeException('Unknown dependency directory refused');continue;}
   if(!isset($expected[$name])||isset($files[$name])||$stat['size']>16777216||($bytes+=$stat['size'])>536870912)throw new RuntimeException('Unknown duplicate or oversized dependency member refused');
   $body=$zip->getFromIndex($i);if(!is_string($body)||strlen($body)!==$stat['size']||hash('sha256',$body)!==$expected[$name])throw new RuntimeException('Official dependency member checksum mismatch');
   $files[$name]=['bytes'=>$stat['size'],'sha256'=>$expected[$name]];
  }
  if(count($files)!==count($expected))throw new RuntimeException('Incomplete official Woo dependency refused');
 }finally{$zip->close();}
 ksort($files,SORT_STRING);
 return['version'=>$version,'archiveSha256'=>hash_file('sha256',$archive),'checksumsSha256'=>$checksumsSha256,'entries'=>count($files)+count($dirs),'bytes'=>$bytes,'fileCount'=>count($files),'files'=>$files];
};

