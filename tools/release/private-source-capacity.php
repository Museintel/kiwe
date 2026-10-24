<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
return static function(string $root,string $private):array{
 $proof=['schema'=>'kiwe.private-source-capacity.v1','passed'=>false,'wordpressBooted'=>false,'sourceWrites'=>0,'checks'=>[]];
 $skip=(new ReflectionClass(DSA\Backup\Portable_File_Inventory::class))->getConstant('EXCLUDED');
 $groups=[];$stack=[['path'=>$root,'depth'=>0]];$files=0;$bytes=0;$directories=0;$problems=[];
 try{
  while($stack){$next=array_pop($stack);if($next['depth']>64||$directories>40000||$files>200000)throw new RuntimeException('Bounded source capacity exceeded');
   foreach(new FilesystemIterator($next['path'],FilesystemIterator::SKIP_DOTS)as$e){$relative=substr($e->getPathname(),strlen($root)+1);if(isset($skip[$relative]))continue;
    if($e->isLink()){$problems[]=['type'=>'symlink','pathSha256'=>hash('sha256',$relative)];continue;}
    if($e->isDir()){$directories++;$stack[]=['path'=>$e->getPathname(),'depth'=>$next['depth']+1];continue;}
    if(!$e->isFile()){$problems[]=['type'=>'non-file','pathSha256'=>hash('sha256',$relative)];continue;}
    $n=$e->getSize();$files++;$bytes+=$n;$parts=explode('/',$relative);$group=$parts[0]==='wp-content'?implode('/',array_slice($parts,0,$parts[1]==='plugins'||$parts[1]==='themes'?3:2)):$parts[0];
    if(!isset($groups[$group]))$groups[$group]=['files'=>0,'bytes'=>0];$groups[$group]['files']++;$groups[$group]['bytes']+=$n;
    if($n>2147483648||!$e->isReadable())$problems[]=['type'=>$n>2147483648?'oversized':'unreadable','pathSha256'=>hash('sha256',$relative),'bytes'=>$n];
   }
  }
  $fingerprints=json_decode(file_get_contents(dirname($private).'/kiwe-ascendants-preupgrade-20261006-rc278-c/original-native-fingerprints.json'),true,512,JSON_THROW_ON_ERROR);
  $rows=array_map(static fn($v)=>$v['rows'],$fingerprints['tables']);arsort($rows);uasort($groups,static fn($a,$b)=>$b['files']<=>$a['files']);
  $proof['source']=['files'=>$files,'fileBytes'=>$bytes,'directories'=>$directories,'groups'=>$groups,'problems'=>$problems,'nativeRows'=>$rows,'freeBytes'=>(int)disk_free_space($private)];$proof['passed']=true;
 }catch(Throwable$e){$proof['error']='Bounded capacity audit refused';}
 return$proof;
};
