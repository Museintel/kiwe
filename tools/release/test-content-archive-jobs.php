<?php
/** Isolated filesystem/cipher/selection proof. Native WordPress has its separate acceptance fixture. */
define('KIWE_CONTENT_JOB_TEST_BOOTSTRAP',true);
require __DIR__.'/test-content-archive-boundaries.php';
require __DIR__.'/../../wp-content/mu-plugins/dsa/includes/Backup/Content_Archive_Job_Service.php';
use DSA\Backup\Content_Archive_Job_Service as Jobs;
use DSA\Backup\Content_Archive_Service as Archives;
use DSA\Backup\Archive_Cipher as Cipher;
$jobs=new Jobs();$error='';$copies=[];$next=5000;$original=[];
$infoPath=static fn($id)=>$root.'/kiwe-private-backups/content-job-'.$id.'/info.json';
$loadInfo=static fn($id)=>json_decode(file_get_contents($infoPath($id)),true);
$saveInfo=static function($id,$value)use($infoPath){file_put_contents($infoPath($id),json_encode($value));};
$finish=static function($job,$target,bool$confirm=false)use($jobs,$phrase){$limit=240;while($job['status']!==$target&&$limit-->0)$job=$jobs->step($job['id'],$phrase,$job['revision'],$confirm);check($job['status']===$target,'bounded job reaches '.$target);return $job;};
try{
 for($id=100;$id<305;$id++){$posts[$id]=new WP_Post(['ID'=>$id,'post_type'=>'post','post_title'=>'Synthetic story '.$id,'post_content'=>'<p>Native story '.$id.'</p>','post_excerpt'=>'Synthetic excerpt','post_author'=>$id%2?7:8,'post_date'=>$id<301?'2026-10-02 12:00:00':'2026-10-03 00:00:00','post_date_gmt'=>'2026-10-02 06:30:00','post_status'=>'publish','post_parent'=>0,'post_name'=>'story-'.$id,'menu_order'=>0]);$assignments[$id]=['category'=>[3],'post_tag'=>[]];$original[$id]=clone$posts[$id];}
 $terms['category:3']=(object)['term_id'=>3,'taxonomy'=>'category','name'=>'Synthetic category','slug'=>'synthetic-category','description'=>'Synthetic detail','parent'=>0,'count'=>205];
 $selection=['from'=>'2026-10-02','to'=>'2026-10-02'];$key=str_repeat('A',32);$backup=$jobs->start_export('posts',0,$phrase,$selection,$key);$copies[]=$backup['id'];
 check($backup['total']===201&&$backup['count']===0&&$backup['selection']['timezone']==='Asia/Kolkata','large selection is frozen with inclusive website dates');
 check($jobs->start_export('posts',0,$phrase,$selection,$key)['id']===$backup['id'],'repeated creation returns the same actor request');
 check(rejects(fn()=>$jobs->start_export('pages',0,$phrase,[],$key)),'request reference cannot create a different selection');
 check(rejects(fn()=>$jobs->download_path($backup['id'])),'incomplete capture has no complete download');
 $backup=$jobs->step($backup['id'],$phrase,$backup['revision']);check($backup['count']===10,'capture is bounded to ten primary records');
 $terms['category:3']->description='Changed during capture';check(rejects(fn()=>$jobs->step($backup['id'],$phrase,$backup['revision'])),'changed taxonomy cannot create inconsistent backup parts');$terms['category:3']->description='Synthetic detail';
 $retry=$jobs->step($backup['id'],$phrase,$backup['revision']-1);check($retry['count']===10&&$retry['revision']===$backup['revision'],'lost response retry cannot advance twice');
 check(rejects(fn()=>$jobs->step($backup['id'],'wrong-recovery-phrase-123',$backup['revision'])),'wrong resume phrase cannot change progress');
 $actor=8;check(!$jobs->inventory()&&rejects(fn()=>$jobs->step($backup['id'],$phrase,$backup['revision'])),'saved jobs are bound to their original actor');$actor=7;
 $lock=fopen(dirname($infoPath($backup['id'])).'/lock','c+b');flock($lock,LOCK_EX);check(rejects(fn()=>$jobs->step($backup['id'],$phrase,$backup['revision'])),'concurrent step cannot obtain the OS lock');flock($lock,LOCK_UN);fclose($lock);
 $savedCheckpoint=$loadInfo($backup['id']);$stateFile=dirname($infoPath($backup['id'])).'/'.$savedCheckpoint['state'];$savedBytes=file_get_contents($stateFile);file_put_contents($stateFile,$savedBytes.'tampered');check(rejects(fn()=>$jobs->step($backup['id'],$phrase,$backup['revision'])),'tampered saved checkpoint cannot resume');file_put_contents($stateFile,$savedBytes);
 $posts[900]=clone$posts[100];$posts[900]->ID=900;$posts[900]->post_title='Created after selection';$assignments[900]=['category'=>[3]];
 $backup=$finish($backup,'ready');$path=$jobs->download_path($backup['id']);$zip=new ZipArchive();$zip->open($path);$manifest=Cipher::open($zip->getFromName('manifest.kiwe'),Jobs::SCHEMA,$phrase,2097152);$zip->close();
 check($backup['count']===201&&!in_array(900,$manifest['ids'],true)&&count($manifest['parts'])===21,'finished archive contains all frozen records without later additions');
 check(!str_contains(file_get_contents($path),'Native story')&&!str_contains(file_get_contents($infoPath($backup['id'])),$phrase),'content and passphrase are absent from public envelope and private progress journal');
 check(!array_key_exists('sourceIds',$jobs->inventory()[0])&&!array_key_exists('state',$jobs->inventory()[0])&&!array_key_exists('pending',$jobs->inventory()[0]),'inventory excludes private journal and source paths');
 $import=$jobs->start_import($path,$phrase,'post',str_repeat('B',32));$copies[]=$import['id'];$before=count($posts);$import=$finish($import,'review');check(count($posts)===$before&&$import['review']['count']===201&&count($import['review']['items'])===30,'entire set validated before any write with bounded review projection');
 $unchanged=$jobs->step($import['id'],$phrase,$import['revision']);check($unchanged['status']==='review'&&count($posts)===$before,'review cannot write without explicit confirmation');
 $import=$jobs->step($import['id'],$phrase,$import['revision'],true);$import=$jobs->step($import['id'],$phrase,$import['revision']);check($import['count']===10&&count($posts)===$before+10,'confirmed import writes one native bounded frame');
 $saved=$loadInfo($import['id']);$dir=dirname($infoPath($import['id']));$state=Cipher::open(file_get_contents($dir.'/'.$saved['state']),'kiwe.content-job.v1',$phrase,4194304);
 // Recreate a process loss after native completion, before the job checkpoint. The saved native journal is authoritative.
 $frameMap=$state['map'];$fingerprint=new ReflectionMethod(Jobs::class,'fingerprint');$guards=[];foreach($frameMap as$newId)$guards[$newId]=['hash'=>$fingerprint->invoke(null,$newId)];
 $state['map']=[];$state['offset']=0;$saved['count']=0;$saved['pending']=['token'=>str_repeat('d',32),'offset'=>0,'posts'=>$guards,'terms'=>[],'completed'=>['sourceMap'=>$frameMap,'ids'=>array_values($frameMap),'count'=>10,'createdTerms'=>[],'state'=>'drafts']];
 $bytes=Cipher::seal($state,'kiwe.content-job.v1',$phrase,[],4194304);file_put_contents($dir.'/'.$saved['state'],$bytes);$saved['stateHash']=hash('sha256',$bytes);$saveInfo($import['id'],$saved);
 $import=$jobs->step($import['id'],$phrase,$saved['revision']);check($import['count']===10&&count($posts)===$before+10,'native completed journal recovers after transient expiry without duplicate drafts');
 $import=$finish($import,'complete');check($import['result']['count']===201&&count($import['result']['ids'])===201&&count($posts)===$before+201,'complete large set restores every record exactly once');
 foreach($import['result']['ids']as$id)check($posts[$id]->post_status==='draft'&&$assignments[$id]['category']===[3],'native draft and category '.$id);
 foreach($original as$id=>$post)check($posts[$id]==$post,'source record unchanged '.$id);
 $retry=$jobs->step($import['id'],$phrase,$import['revision']-1,true);check($retry['status']==='complete'&&count($posts)===$before+201,'completed set retry is idempotent');
 // A pending partial record changed outside this job must never be removed or duplicated.
 $pending=$jobs->start_import($path,$phrase,'post',str_repeat('C',32));$copies[]=$pending['id'];$pending=$finish($pending,'review');$pending=$jobs->step($pending['id'],$phrase,$pending['revision'],true);$saved=$loadInfo($pending['id']);
 $partial=wp_insert_post(['post_type'=>'post','post_status'=>'draft','post_author'=>7,'post_title'=>'Partial','post_name'=>'pending','post_content'=>'Partial','post_excerpt'=>'','post_date'=>'2026-10-02 00:00:00','post_date_gmt'=>'2026-10-02 00:00:00','menu_order'=>0]);$saved['pending']=['token'=>str_repeat('e',32),'offset'=>0,'posts'=>[$partial=>['hash'=>$fingerprint->invoke(null,$partial)]],'terms'=>[]];$saveInfo($pending['id'],$saved);
 $posts[$partial]->post_title='Changed elsewhere';check(rejects(fn()=>$jobs->step($pending['id'],$phrase,$pending['revision']))&&get_post($partial)&&rejects(fn()=>$jobs->remove($pending['id'])),'changed partial draft prevents cleanup and duplication');$posts[$partial]->post_title='Partial';
 $pending=$jobs->step($pending['id'],$phrase,$pending['revision']);check(!get_post($partial)&&$pending['count']===10,'untouched partial draft is removed before retrying its uncommitted frame');
 $expiry=$loadInfo($backup['id']);$expiry['expiresAt']=time()-1;$saveInfo($backup['id'],$expiry);check(rejects(fn()=>$jobs->download_path($backup['id']))&&$jobs->inventory()[0]['expired']===false,'expired download is denied without hiding removable jobs');
 check($jobs->remove($backup['id'])['removed'],'expired own private files remain removable');$copies=array_values(array_diff($copies,[$backup['id']]));
 // Strict ZIP allowlist: no extraction, arbitrary filenames, compressed expansion or cross-site index.
 $bad=$root.'/bad.kiwe-set';copy($path=$dir.'/source.kiwe-set',$bad);$zip=new ZipArchive();$zip->open($bad);$zip->addFromString('../unsafe.php','<?php echo 1;');$zip->close();check(rejects(fn()=>$jobs->start_import($bad,$phrase,'post',str_repeat('F',32))),'path traversal and unknown ZIP entries refused');unlink($bad);
 copy($path,$bad);$zip=new ZipArchive();$zip->open($bad);$zip->setCompressionName('parts/part-0000.kiwe',ZipArchive::CM_DEFLATE);$zip->close();check(rejects(fn()=>$jobs->start_import($bad,$phrase,'post',str_repeat('G',32))),'compressed ZIP expansion refused');unlink($bad);
 $removed=$jobs->remove($import['id']);check($removed['draftsKept']===201&&count($posts)===$before+211,'removing completed private job keeps all native drafts');$copies=array_values(array_diff($copies,[$import['id']]));
 foreach($copies as$id)$jobs->remove($id);$copies=[];check(!$jobs->inventory(),'private job inventory empty after explicit removal');
 for($id=2000;$id<2008;$id++){$posts[$id]=clone$posts[100];$posts[$id]->ID=$id;$posts[$id]->post_type='page';$posts[$id]->post_date='2085-01-01 12:00:00';$posts[$id]->post_content=str_repeat('x',900000);}
 $large=$jobs->start_export('pages',0,$phrase,['from'=>'2085-01-01','to'=>'2085-01-01'],str_repeat('H',32));$copies[]=$large['id'];$large=$jobs->step($large['id'],$phrase,$large['revision']);check($large['count']===4&&$large['total']===8,'large text automatically uses smaller frames without truncating the selection');$jobs->remove($large['id']);$copies=[];
}catch(Throwable$failure){$error=$failure->getMessage();}
finally{$cleanup=static function($path)use(&$cleanup){if(is_link($path))throw new RuntimeException('Unexpected fixture symlink');foreach(glob($path.'/*')?:[]as$file){if(is_dir($file))$cleanup($file);else unlink($file);}rmdir($path);};$cleanup($root);}
echo json_encode(['passed'=>$error==='','cleanup'=>!file_exists($root),'checks'=>$checks,'error'=>$error],JSON_UNESCAPED_SLASHES).PHP_EOL;exit($error===''?0:1);
