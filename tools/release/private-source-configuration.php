<?php
declare(strict_types=1);
/** Operator-only literal configuration reader. Never executes wp-config.php or emits its values. */
if (PHP_SAPI !== 'cli') return null;
return static function(#[SensitiveParameter] string $php): array {
    if(strlen($php)>65536||!str_starts_with(ltrim($php),'<?php'))throw new RuntimeException('Bounded classic source configuration required');
    $tokens=array_values(array_filter(token_get_all($php),static fn($t)=>!is_array($t)||!in_array($t[0],[T_WHITESPACE,T_COMMENT,T_DOC_COMMENT],true)));
    $literal=static function($t):?string{if(!is_array($t)||$t[0]!==T_CONSTANT_ENCAPSED_STRING)return null;$v=$t[1];$inner=substr($v,1,-1);if($v[0]==='"'&&preg_match('/[\\\\$]/',$inner))return null;if($v[0]==="'")$inner=str_replace(["\\'","\\\\"],["'","\\"],$inner);return $inner;};
    $values=[];$prefix=null;$depth=0;$saltNames=['AUTH_KEY','SECURE_AUTH_KEY','LOGGED_IN_KEY','NONCE_KEY','AUTH_SALT','SECURE_AUTH_SALT','LOGGED_IN_SALT','NONCE_SALT'];
    foreach($tokens as$i=>$t){if(is_array($t)&&$t[0]===T_STRING&&strtolower($t[1])==='define'&&in_array($literal($tokens[$i+2]??null),['MULTISITE','SUBDOMAIN_INSTALL','SUNRISE'],true))throw new RuntimeException('Multisite or sunrise configuration requires separate qualification');if($t==='{')$depth++;elseif($t==='}')$depth--;if($depth!==0||!is_array($t))continue;
        if($t[0]===T_STRING&&strtolower($t[1])==='define'&&($tokens[$i+1]??null)==='('&&($tokens[$i+3]??null)===','){$key=$literal($tokens[$i+2]??null);if($key!==null&&(str_starts_with($key,'DB_')||in_array($key,$saltNames,true))){$value=$literal($tokens[$i+4]??null);if($value===null||isset($values[$key])||($tokens[$i+5]??null)!==')')throw new RuntimeException('Unique literal source configuration required');$values[$key]=$value;}}
        if($t[0]===T_VARIABLE&&$t[1]==='$table_prefix'){if($prefix!==null||($tokens[$i+1]??null)!=='='||($tokens[$i+3]??null)!==';')throw new RuntimeException('Unique literal source prefix required');$prefix=$literal($tokens[$i+2]??null);}
    }
    foreach(array_merge(['DB_NAME','DB_USER','DB_PASSWORD','DB_HOST'],$saltNames)as$n)if(!isset($values[$n])||$values[$n]==='')throw new RuntimeException('Complete existing native configuration required');
    if(!in_array($values['DB_HOST'],['localhost','127.0.0.1','localhost:3306','127.0.0.1:3306'],true)||!is_string($prefix)||preg_match('/^[a-zA-Z0-9_]{1,24}_$/D',$prefix)!==1)throw new RuntimeException('Reviewed local single-site database required');
    if(count(array_unique(array_intersect_key($values,array_flip($saltNames))))!==8)throw new RuntimeException('Eight distinct original salts required');
    return ['values'=>$values,'prefix'=>$prefix,'configurationSha256'=>hash('sha256',$php)];
};
