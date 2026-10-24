<?php
/** Real cipher, ZIP, digest and private-filesystem proof; native writers have a separate fixture. */
define( 'KIWE_CONTENT_JOB_TEST_BOOTSTRAP', true );
require __DIR__.'/test-content-archive-boundaries.php';
use DSA\Backup\Media_Archive_Service as Media;
use DSA\Backup\Media_Archive_Job_Service as Jobs;
use DSA\Backup\Archive_Job_Files as Files;
use DSA\Backup\Archive_Cipher as Cipher;
function sanitize_file_name( $name ) { return preg_replace( '/[^A-Za-z0-9._-]/', '', $name ); }
function wp_http_validate_url( $url ) { return filter_var( $url, FILTER_VALIDATE_URL ); }
$error = ''; $owned = []; $zipPath = $root.'/media.kiwe-media';
try {
 foreach ( [ '../file.txt','/file.txt','a\\file.txt','a//file.txt','a/../file.txt','a/.secret','a/file.php','a/file.svg','a/file.txt:evil' ] as $path ) check( rejects( fn()=>Media::relative( $path ) ), 'unsafe upload path rejected: '.$path );
 check( Media::relative( '2026/10/file-1.png' ) === '2026/10/file-1.png', 'canonical registered upload path retained' );
 $digest = new ReflectionMethod( Jobs::class, 'digest' ); $raw = str_repeat( 'Synthetic media bytes.', 60000 );
 $first = $digest->invoke( null, null, substr( $raw, 0, Jobs::CHUNK_BYTES ), false );
 $last = $digest->invoke( null, $first, substr( $raw, Jobs::CHUNK_BYTES ), true );
 check( $last['hash'] === hash( 'sha256', $raw ), 'native incremental SHA-256 survives a persisted chunk boundary' );
 $bad = $first; $bad['runtime']--; check( rejects( fn()=>$digest->invoke( null, $bad, 'x', true ) ), 'changed hash runtime requires a fresh live job' );
 $bad = $first; $bad['data'][0] = 'md5'; check( rejects( fn()=>$digest->invoke( null, $bad, 'x', true ) ), 'digest context cannot change algorithms' );
 $id = bin2hex( random_bytes( 16 ) ); $dir = Files::directory( 'media', $id, true ); $owned[] = $dir;
 check( rejects( fn()=>Files::path( $dir, '../secret' ) ) && rejects( fn()=>Files::directory( 'other', $id ) ), 'shared private storage has closed kinds and filenames' );
 Files::write( $dir, 'info.json', '{"actor":7}' ); check( Files::read( $dir, 'info.json', 100 ) === '{"actor":7}', 'atomic private checkpoint reads back exact bytes' );
 check( rejects( fn()=>Files::read( $dir, 'info.json', 1 ) ), 'private reader enforces byte limits' );
 for ( $i=0; $i<7; $i++ ) { $next = Files::directory( $i%2 ? 'content' : 'media', bin2hex( random_bytes( 16 ) ), true ); $owned[] = $next; Files::write( $next, 'info.json', '{"actor":7}' ); }
 check( rejects( fn()=>Files::quota( 7 ) ), 'content and media share the eight-job actor quota' );
 foreach ( $owned as $path ) Files::retire( $path ); $owned = [];
 $from = 'https://qa.example.test/uploads/a.png'; $to = 'https://qa.example.test/uploads/recovery/a.png';
 $record = [ 'featuredImage'=>91,'content'=>'<!-- wp:image {"id":91} --><img class="wp-image-91" src="'.$from.'?v=1"><img src="'.$from.'.other">','excerpt'=>$from,'builder'=>[ [ 'settings'=>[ 'image'=>[ 'id'=>'91','url'=>$from ] ],'unrelated'=>[ 'id'=>91 ] ] ],'serviceMeta'=>[ 'intro'=>$from ] ];
 $mapped = Media::remap_content( [ 'records'=>[ $record ] ], [ 'map'=>[ 91=>[ 'id'=>801,'urls'=>[ $from=>$to ] ] ] ] )['records'][0];
 check( $mapped['featuredImage'] === 801 && str_contains( $mapped['content'], '"id":801' ) && str_contains( $mapped['content'], 'wp-image-801' ), 'reviewed draft maps featured image and native block identities' );
 check( str_contains( $mapped['content'], $to.'?v=1' ) && str_contains( $mapped['content'], $from.'.other' ), 'URL mapping keeps query strings and larger unrelated filenames' );
 check( $mapped['builder'][0]['settings']['image']['id'] === '801' && $mapped['builder'][0]['unrelated']['id'] === 91 && $mapped['excerpt'] === $to && $mapped['serviceMeta']['intro'] === $to, 'typed builder images map without rewriting arbitrary numeric ids' );
 $file = [ 'name'=>'file.txt','relative'=>'2026/10/file.txt','bytes'=>strlen( $raw ),'modified'=>1,'hash'=>hash( 'sha256', $raw ),'mime'=>'text/plain' ];
 $record = [ 'id'=>91,'title'=>'Synthetic file','caption'=>'','description'=>'','date'=>'2026-10-03 00:00:00','dateGmt'=>'2026-10-02 18:30:00','mime'=>'text/plain','alt'=>'','attached'=>$file['relative'],'metadata'=>[],'backups'=>[],'seo'=>[],'files'=>[ $file ],'fingerprint'=>str_repeat( 'a', 64 ) ];
 Media::validate_record( $record ); check( true, 'registered local file manifest is accepted' );
 $bad = $record; $bad['metadata']['sizes'] = [ 'bad'=>[ 'file'=>'missing.png' ] ]; check( rejects( fn()=>Media::validate_record( $bad ) ), 'unrepresented derivative is refused' );
 $bad = $record; $bad['metadata']['image'] = [ 'embedded'=>true ]; check( rejects( fn()=>Media::validate_record( $bad ) ), 'embedded artwork requires a separate adapter' );
 $plan = [ 'scope'=>'attachment','sourceId'=>91,'label'=>'Synthetic media','selection'=>[ 'from'=>'','to'=>'','timezone'=>'UTC' ],'records'=>[ $record ],'rawBytes'=>strlen( $raw ),'fileCount'=>1,'uploadsUrl'=>'https://qa.example.test/uploads','chunks'=>[] ];
 $zip = new ZipArchive(); $zip->open( $zipPath, ZipArchive::CREATE | ZipArchive::EXCL );
 for ( $offset=0,$index=0; $offset<strlen( $raw ); $offset+=Jobs::CHUNK_BYTES,$index++ ) {
  $part = substr( $raw, $offset, Jobs::CHUNK_BYTES ); $name = sprintf( 'chunk-%05d.kiwe', $index ); $frame = Cipher::seal( [ 'file'=>0,'offset'=>$offset,'length'=>strlen( $part ),'data'=>base64_encode( $part ) ], 'kiwe.media-chunk.v1', $phrase, [], 2097152 );
  $zip->addFromString( 'chunks/'.$name, $frame ); $zip->setCompressionName( 'chunks/'.$name, ZipArchive::CM_STORE ); $plan['chunks'][] = [ 'name'=>$name,'file'=>0,'offset'=>$offset,'length'=>strlen( $part ),'bytes'=>strlen( $frame ),'hash'=>hash( 'sha256', $frame ) ];
 }
 $zip->addFromString( 'manifest.kiwe', Cipher::seal( $plan, Jobs::SCHEMA, $phrase, [], 4194304 ) ); $zip->setCompressionName( 'manifest.kiwe', ZipArchive::CM_STORE ); $zip->close();
 $manifest = new ReflectionMethod( Jobs::class, 'manifest' ); $zip->open( $zipPath ); $decoded = $manifest->invoke( null, $zip, $phrase );
 check( count( $decoded['chunks'] ) === 3 && $decoded['selection']['timezone'] === 'UTC', 'real encrypted ZIP validates contiguous chunks and capture timezone independently' );
 check( rejects( fn()=>$manifest->invoke( null, $zip, 'wrong-passphrase-123456' ) ), 'media index rejects wrong recovery phrase' ); $zip->close();
 $zip->open( $zipPath ); $zip->addFromString( '../outside.php', 'unsafe' ); $zip->setCompressionName( '../outside.php', ZipArchive::CM_STORE ); $zip->close(); $zip->open( $zipPath );
 check( rejects( fn()=>$manifest->invoke( null, $zip, $phrase ) ), 'closed ZIP index rejects extra traversal entries before restoration' ); $zip->close();
} catch ( Throwable $failure ) { $error = $failure->getMessage(); }
finally {
 foreach ( $owned as $path ) try { Files::retire( $path ); } catch ( Throwable $failure ) { $error = $error ?: $failure->getMessage(); }
 if ( is_file( $zipPath ) ) unlink( $zipPath ); foreach ( glob( $root.'/kiwe-private-backups/*' ) ?: [] as $path ) if ( is_file( $path ) ) unlink( $path );
 $clean = ( !is_dir( $root.'/kiwe-private-backups' ) || @rmdir( $root.'/kiwe-private-backups' ) ) && @rmdir( $root.'/public_html' ) && @rmdir( $root );
}
echo json_encode( [ 'passed'=>!$error && $clean,'checks'=>$checks,'cleanup'=>$clean,'error'=>$error ] ).PHP_EOL;
exit( $error || !$clean ? 1 : 0 );
