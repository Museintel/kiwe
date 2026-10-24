<?php
declare(strict_types=1);
/** CLI adapter for existing source observation, inventory and archive owners; no application bootstrap. */
if(PHP_SAPI!=='cli')return null;
return static function(array $c,wpdb $db,$database,#[SensitiveParameter]string $phrase,Closure $budget,Closure $write,Closure $diagnostics):array {
    if($c['phase']!=='members'||!is_resource($database))throw new RuntimeException('Reviewed native source member context required');
    $plan=DSA\Backup\Portable_File_Inventory::scan($c['sourceRoot'],DSA\Backup\Portable_File_Inventory::MAX_FILES,DSA\Backup\Portable_File_Inventory::MAX_BYTES,$budget,'posix');
    $required=2*$plan['bytes']+1073741824;if((int)disk_free_space($c['private'])<$required)throw new DSA\Backup\Archive_Error('Insufficient private source member byte reserve');
    $inventory=json_encode($plan,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);$write($c['private'].'/source-inventory.private.json',$inventory);
    $diagnosticReceipt=$diagnostics($c,$phrase,$budget,$write);
    $files=fopen($c['private'].'/files.kiwe','x+b');if(!$files||!chmod($c['private'].'/files.kiwe',0600))throw new RuntimeException('Fresh private source file archive required');
    try{$observation=DSA\Backup\Portable_Source_Observation::capture($c['sourceRoot'],$plan,$db,$database,$files,$phrase,$budget);if(!fflush($files)||!fsync($files))throw new RuntimeException('Source file archive sync refused');}finally{if(is_resource($files))fclose($files);}
    $files=fopen($c['private'].'/files.kiwe','rb');try{$verified=DSA\Backup\Portable_Archive_Container::verify($files,$phrase,$budget);}finally{if(is_resource($files))fclose($files);}
    if($verified['inventory']!==$plan||$verified['archiveId']!==$observation['files']['archiveId']||hash_file('sha256',$c['private'].'/files.kiwe')!==$observation['files']['sha256']||filesize($c['private'].'/files.kiwe')!==$observation['files']['bytes']||$observation['observedStable']!==true||$observation['database']['transactionEnded']!==true)throw new RuntimeException('Complete authenticated source members required');
    $write($c['private'].'/source-observation.private.json',json_encode($observation,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
    return ['schema'=>'kiwe.private-native-source-members.v1','passed'=>true,'observation'=>$observation,'diagnostics'=>$diagnosticReceipt,'fileCount'=>count($plan['files']),'directoryCount'=>count($plan['directories']),'fileBytes'=>$plan['bytes'],'inventorySha256'=>hash('sha256',$inventory),'exclusions'=>$plan['exclusions'],'filesAuthenticated'=>true,'physicalByteReserveRequired'=>$required,'accountQuotaFreshlyQualified'=>false,'writerFreezeVerified'=>false,'databaseFileConsistencyVerified'=>false,'fullRecoveryEnvelopeCreated'=>false,'offlineCopyCompleted'=>false];
};
