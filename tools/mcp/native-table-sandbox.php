<?php
/** Lab-only SQL sandbox. This file is a qualification tool, not shipped runtime. */
final class Kiwe_MCP_Native_Table_Sandbox {
 private array $map=[];private array $owned=[];private $cache;private bool $active=false;
 public function __construct(private string $run) {
  if(PHP_SAPI!=='cli'||home_url()!=='https://plum-giraffe-539524.hostingersite.com'||is_multisite()||!preg_match('/^[a-f0-9]{16}$/D',$run)||wp_using_ext_object_cache())throw new RuntimeException('Exact private lab with process-local native cache required.');
 }
 public static function rewrite_sql(string $sql,array $map,string $run): string {
  // Mutexes used by these fixtures must not contend with the real website.
  if(preg_match("/^SELECT\s+(GET_LOCK|RELEASE_LOCK|IS_FREE_LOCK|IS_USED_LOCK)\(\s*'([^']+)'/i",$sql,$match)&&str_starts_with($match[2],'kiwe-'))$sql=str_replace("'".$match[2]."'","'kiwe-m04-".substr(hash('sha256',$run.'|'.$match[2]),0,40)."'",$sql);
  $out='';$length=strlen($sql);
  for($i=0;$i<$length;){$c=$sql[$i];
   if($c==="'"||$c==='"'){$start=$i++;while($i<$length){if($sql[$i]==='\\'){$i+=2;continue;}if($sql[$i]===$c){if(($sql[$i+1]??'')===$c){$i+=2;continue;}$i++;break;}$i++;}$out.=substr($sql,$start,$i-$start);continue;}
   if($c==='`'){$start=$i++;$identifier='';while($i<$length){if($sql[$i]==='`'){if(($sql[$i+1]??'')==='`'){$identifier.='`';$i+=2;continue;}$i++;break;}$identifier.=$sql[$i++];}$out.=isset($map[$identifier])?'`'.$map[$identifier].'`':substr($sql,$start,$i-$start);continue;}
   if(substr($sql,$i,2)==='/*'){$end=strpos($sql,'*/',$i+2);$end=$end===false?$length:$end+2;$out.=substr($sql,$i,$end-$i);$i=$end;continue;}
   if($c==='#'||(substr($sql,$i,2)==='--'&&preg_match('/\s/',$sql[$i+2]??' '))){$end=strpos($sql,"\n",$i);$end=$end===false?$length:$end;$out.=substr($sql,$i,$end-$i);$i=$end;continue;}
   if(preg_match('/[a-zA-Z_]/',$c)){$start=$i++;while($i<$length&&preg_match('/[a-zA-Z0-9_$]/',$sql[$i]))$i++;$name=substr($sql,$start,$i-$start);$out.=$map[$name]??$name;continue;}
   $out.=$c;$i++;
  }return$out;
 }
 public function query(string $sql): string {return self::rewrite_sql($sql,$this->map,$this->run);}
 public function start(int $owner): array {
  global$wpdb;$tables=$wpdb->get_col($wpdb->prepare('SHOW TABLES LIKE %s',$wpdb->esc_like($wpdb->prefix).'%'));
  if(!$tables||count($tables)>256)throw new RuntimeException('Native table inventory exceeds the fixture budget.');
  try{
   foreach($tables as$table){if(!preg_match('/^[a-zA-Z0-9_]{1,64}$/D',$table))throw new RuntimeException('Unsupported native table identifier.');$clone='kiwe_m04_'.substr($this->run,0,12).'_'.substr(hash('sha256',$table),0,12);
    if($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$wpdb->esc_like($clone)))!==null)throw new RuntimeException('A native fixture table already exists.');
    if($wpdb->query("CREATE TABLE `{$clone}` LIKE `{$table}`")===false)throw new RuntimeException('Native fixture schema could not be copied.');$this->map[$table]=$clone;$this->owned[]=$clone;
   }
   // No customers, carts, orders or their activity are copied. Only original
   // settings and the technical test actor's own native account/role are seeded.
   foreach([$wpdb->options=>'',$wpdb->users=>$wpdb->prepare(' WHERE ID=%d',$owner),$wpdb->usermeta=>$wpdb->prepare(' WHERE user_id=%d',$owner)]as$table=>$where){$clone=$this->map[$table]??null;if(!$clone||$wpdb->query("INSERT INTO `{$clone}` SELECT * FROM `{$table}`".$where)===false)throw new RuntimeException('Native fixture seed could not be prepared.');}
   $this->cache=$GLOBALS['wp_object_cache'];$GLOBALS['wp_object_cache']=new WP_Object_Cache();add_filter('query',[$this,'query'],PHP_INT_MAX);$this->active=true;
   return['tables'=>count($this->map),'ownerSeeded'=>true,'customersCopied'=>false,'externalCache'=>false];
  }catch(Throwable $e){$this->close();throw$e;}
 }
 public function close(): array {
  global$wpdb;if($this->active){remove_filter('query',[$this,'query'],PHP_INT_MAX);$GLOBALS['wp_object_cache']=$this->cache;$this->active=false;}
  $removed=0;foreach(array_reverse($this->owned)as$table){if(!str_starts_with($table,'kiwe_m04_'.substr($this->run,0,12).'_')||!preg_match('/^kiwe_m04_[a-f0-9]{12}_[a-f0-9]{12}$/D',$table))throw new RuntimeException('Exact fixture retirement refused.');if($wpdb->query("DROP TABLE `{$table}`")===false)throw new RuntimeException('Native fixture retirement could not be confirmed.');$removed++;}
  $this->owned=[];return['retired'=>$removed,'remaining'=>0];
 }
}
