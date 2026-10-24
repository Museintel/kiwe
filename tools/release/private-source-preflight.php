<?php
declare(strict_types=1);
/** Read-only native-source audit, packed with checksum-bound owners by the operator. No WordPress boot. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
return static function (string $root, string $private, int $seconds = 1650): array {
    $proof = ['schema'=>'kiwe.private-source-preflight.v1','passed'=>false,'wordpressBooted'=>false,'sourceWrites'=>0,'checks'=>[]];
    $started=hrtime(true);$last=-10;$phase='native-review';$rowsSeen=0;
    $budget=static function()use($private,$seconds,$started,&$last,&$phase,&$rowsSeen):void{
        $elapsed=(hrtime(true)-$started)/1000000000;
        if($elapsed-$last>=10){
            $json=json_encode(['schema'=>'kiwe.private-source-preflight-progress.v1','phase'=>$phase,'seconds'=>$elapsed,'rowsReviewed'=>$rowsSeen,'memoryBytes'=>memory_get_usage(true),'peakMemoryBytes'=>memory_get_peak_usage(true)],JSON_THROW_ON_ERROR);
            $h=fopen($private.'/progress.private.json','c+b');
            if(!$h||!chmod($private.'/progress.private.json',0600)||!ftruncate($h,0)||fwrite($h,$json)!==strlen($json)||!fflush($h)||!fsync($h))throw new RuntimeException('Private audit progress refused');
            fclose($h);$last=$elapsed;
        }
        if($elapsed>$seconds)throw new DSA\Backup\Archive_Error('Original source review reached its bounded operation time');
    };
    $check = static function (bool $ok, string $label) use (&$proof): void {
        $proof['checks'][$label]=$ok;
        if (!$ok) throw new RuntimeException($label);
    };
    $write = static function (string $path, string $bytes): void {
        $s=fopen($path,'x+b');
        if (!$s || !chmod($path,0600)) throw new RuntimeException('Private audit storage refused');
        if (fwrite($s,$bytes)!==strlen($bytes) || !fflush($s) || !fsync($s)) throw new RuntimeException('Private audit write incomplete');
        fclose($s);
    };
    $db=null;
    try {
        $check(realpath($root)===$root && is_dir($root) && !is_link($root),'exact ordinary source root');
        $check(realpath($private)===$private && !is_link($private) && (fileperms($private)&0777)===0700 && !str_starts_with($private,$root.'/'),'owner-only unserved audit directory');
        $config=file_get_contents($root.'/wp-config.php');
        $tokens=array_values(array_filter(token_get_all($config),static fn($t)=>!is_array($t)||!in_array($t[0],[T_WHITESPACE,T_COMMENT,T_DOC_COMMENT],true)));
        $literal=static function ($t): ?string {
            if (!is_array($t)||$t[0]!==T_CONSTANT_ENCAPSED_STRING) return null;
            $v=$t[1]; if ($v[0]!=="'" && $v[0]!== '"') return null;
            $inner=substr($v,1,-1);
            if ($v[0]==='"' && preg_match('/[\\\\$]/',$inner)) return null;
            if ($v[0]==="'") $inner=str_replace(["\\'","\\\\"],["'","\\"],$inner);
            return $inner;
        };
        $values=[]; $prefix=null; $depth=0;
        for($i=0,$n=count($tokens);$i<$n;$i++) {
            $t=$tokens[$i]; if($t==='{')$depth++; elseif($t==='}')$depth--;
            if($depth!==0 || !is_array($t))continue;
            if($t[0]===T_STRING && strtolower($t[1])==='define' && ($tokens[$i+1]??null)==='(' && ($tokens[$i+3]??null)===',') {
                $key=$literal($tokens[$i+2]??null); $value=$literal($tokens[$i+4]??null);
                if($key!==null && (str_starts_with($key,'DB_') || in_array($key,['AUTH_KEY','SECURE_AUTH_KEY','LOGGED_IN_KEY','NONCE_KEY','AUTH_SALT','SECURE_AUTH_SALT','LOGGED_IN_SALT','NONCE_SALT'],true))) {
                    $check($value!==null && !isset($values[$key]) && ($tokens[$i+5]??null)===')','literal unique configuration field: '.$key); $values[$key]=$value;
                }
            }
            if($t[0]===T_VARIABLE && $t[1]==='$table_prefix') {
                $check($prefix===null && ($tokens[$i+1]??null)==='=' && ($tokens[$i+3]??null)===';','literal unique source prefix'); $prefix=$literal($tokens[$i+2]??null);
            }
        }
        foreach(['DB_NAME','DB_USER','DB_PASSWORD','DB_HOST','AUTH_KEY','SECURE_AUTH_KEY','LOGGED_IN_KEY','NONCE_KEY','AUTH_SALT','SECURE_AUTH_SALT','LOGGED_IN_SALT','NONCE_SALT']as$key)$check(isset($values[$key]) && $values[$key]!=='','required native configuration field: '.$key);
        $check(in_array($values['DB_HOST'],['localhost','127.0.0.1','localhost:3306','127.0.0.1:3306'],true),'supported local native database host');
        $check(is_string($prefix) && preg_match('/^[a-zA-Z0-9_]{1,24}_$/D',$prefix)===1,'supported native table prefix');
        $salts=array_intersect_key($values,array_flip(['AUTH_KEY','SECURE_AUTH_KEY','LOGGED_IN_KEY','NONCE_KEY','AUTH_SALT','SECURE_AUTH_SALT','LOGGED_IN_SALT','NONCE_SALT']));
        $check(count(array_unique($salts))===8,'eight distinct existing salts');
        $db=new mysqli(str_starts_with($values['DB_HOST'],'127.')?'127.0.0.1':'localhost',$values['DB_USER'],$values['DB_PASSWORD'],$values['DB_NAME'],3306);
        $db->set_charset('utf8mb4'); $db->query('SET SESSION wait_timeout=1800'); $db->query('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ'); $db->query('START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY');
        $q=static fn($v)=>"'".$db->real_escape_string((string)$v)."'";
        $id=static fn($v)=>DSA\Backup\Portable_Table_Definition::identifier($v);
        $tables=$db->query('SELECT TABLE_NAME,ENGINE,TABLE_TYPE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND LEFT(TABLE_NAME,'.strlen($prefix).')='.$q($prefix).' ORDER BY BINARY TABLE_NAME')->fetch_all(MYSQLI_ASSOC);
        $check(count($tables)>=12 && count($tables)<=128,'source native table count fits materialization boundary');
        $catalog=[]; $tableProof=[]; $definitions=[];
        foreach($tables as$table) {
            $name=$table['TABLE_NAME'];$suffix=substr($name,strlen($prefix));$catalog[]=$suffix;
            $check($table['ENGINE']==='InnoDB' && $table['TABLE_TYPE']==='BASE TABLE','supported transactional native table: '.$suffix);
            $ddl=$db->query('SHOW CREATE TABLE '.$id($name))->fetch_row()[1];
            try { DSA\Backup\Portable_Table_Definition::parse($ddl,$name); $supported=true; } catch(Throwable $e) { $supported=false; }
            $definitions[$suffix]=['supported'=>$supported,'sha256'=>hash('sha256',$ddl)];
            $hash=hash_init('sha256'); $rows=0;
            $primary=$db->query('SHOW INDEX FROM '.$id($name)." WHERE Key_name='PRIMARY'")->fetch_all(MYSQLI_ASSOC);
            usort($primary,static fn($a,$b)=>$a['Seq_in_index']<=>$b['Seq_in_index']);
            $order=$primary?' ORDER BY '.implode(',',array_map(static fn($v)=>$id($v['Column_name']),$primary)):'';
            $r=$db->query('SELECT * FROM '.$id($name).$order,MYSQLI_USE_RESULT);
            while($row=$r->fetch_row()){hash_update($hash,serialize($row));$rows++;$rowsSeen++;$budget();} $r->free();
            $tableProof[$suffix]=['rows'=>$rows,'definitionSha256'=>hash('sha256',$ddl),'rowsSha256'=>hash_final($hash)];
        }
        $options=[]; $r=$db->query('SELECT option_id,option_name,option_value,autoload FROM '.$id($prefix.'options').' ORDER BY BINARY option_name');
        while($row=$r->fetch_assoc())$options[]=['id'=>(int)$row['option_id'],'name'=>$row['option_name'],'valueSha256'=>hash('sha256',$row['option_value']),'autoload'=>$row['autoload']];
        $allowed=['active_plugins','template','stylesheet','home','siteurl']; $settings=[];
        foreach($allowed as$key){$r=$db->query('SELECT option_value FROM '.$id($prefix.'options').' WHERE option_name='.$q($key));$v=$r->fetch_row()[0]??null;if($key==='active_plugins')$v=unserialize((string)$v,['allowed_classes'=>false]);$settings[$key]=$v;}
        $db->query('COMMIT');
        $db->close(); $db=null;
        $write($private.'/original-native-fingerprints.json',json_encode(['prefix'=>$prefix,'configurationSha256'=>hash('sha256',$config),'tables'=>$tableProof,'options'=>$options],JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
        $write($private.'/native-source-settings.json',json_encode($settings,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
        $proof['native']=['tables'=>count($tables),'rows'=>array_sum(array_column($tableProof,'rows')),'options'=>count($options),'tableDefinitions'=>$definitions];
        $phase='file-inventory';$budget();
        $plan=DSA\Backup\Portable_File_Inventory::scan($root,DSA\Backup\Portable_File_Inventory::MAX_FILES,DSA\Backup\Portable_File_Inventory::MAX_BYTES,$budget,'posix');
        $write($private.'/original-file-inventory.json',json_encode($plan,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
        $proof['source']=['files'=>count($plan['files']),'fileBytes'=>$plan['bytes'],'directories'=>count($plan['directories']),'exclusions'=>$plan['exclusions'],'tables'=>count($tables),'rows'=>array_sum(array_column($tableProof,'rows')),'options'=>count($options),'tableDefinitions'=>$definitions,'activePlugins'=>$settings['active_plugins'],'template'=>$settings['template'],'stylesheet'=>$settings['stylesheet'],'classicConfiguration'=>true,'configurationSha256'=>hash('sha256',$config),'freeBytes'=>(int)disk_free_space($private)];
        $proof['passed']=true;
    } catch(Throwable $error) { $proof['errorClass']=get_class($error);$proof['error']=$error instanceof DSA\Backup\Archive_Error?$error->getMessage():'Native source preflight refused'; }
    finally { if($db instanceof mysqli){try{$db->query('ROLLBACK');$db->close();}catch(Throwable $e){$proof['databaseCloseSucceeded']=false;$proof['passed']=false;}} }
    $proof['milliseconds']=(hrtime(true)-$started)/1000000;
    return $proof;
};
