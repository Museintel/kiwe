<?php
function kiwe_release_splice_bytes(string $base,array $patch,string $expected):string {
 if(($patch['schema']??'')!=='kiwe.release-byte-splices.v1'||strlen($base)>4194304||($patch['baseSha256']??'')!==hash('sha256',$base)||($patch['targetSha256']??'')!==$expected||!is_int($patch['targetBytes']??null)||$patch['targetBytes']<0||$patch['targetBytes']>4194304||!is_array($patch['operations']??null)||count($patch['operations'])>10000)throw new RuntimeException('Invalid pinned byte splice');
 $format=$patch['baseFormat']??'';if(!in_array($format,['original','lf','crlf'],true))throw new RuntimeException('Unknown base format');
 if($format!=='original'){if(!preg_match('//u',$base))throw new RuntimeException('Text format requires UTF-8');$base=str_replace("\r\n","\n",$base);if($format==='crlf')$base=str_replace("\n","\r\n",$base);if(strlen($base)>4194304)throw new RuntimeException('Oversized base');}
 $out='';foreach($patch['operations']as$op){
  if(is_array($op)){if(array_keys($op)!==[0,1]||!is_int($op[0])||!is_int($op[1])||$op[0]<0||$op[1]<0||$op[0]>strlen($base)||$op[1]>strlen($base)-$op[0])throw new RuntimeException('Invalid copy range');$out.=substr($base,$op[0],$op[1]);}
  elseif(is_string($op)){if(strlen($op)>5592408)throw new RuntimeException('Oversized literal');$part=base64_decode($op,true);if($part===false||base64_encode($part)!==$op)throw new RuntimeException('Invalid literal');$out.=$part;}
  else throw new RuntimeException('Invalid byte operation');
  if(strlen($out)>$patch['targetBytes'])throw new RuntimeException('Oversized reconstruction');
 }
 if(strlen($out)!==$patch['targetBytes']||hash('sha256',$out)!==$expected)throw new RuntimeException('Reconstructed target mismatch');return$out;
}
