<?php
namespace DSA\Analytics;

if ( ! defined( 'ABSPATH' ) ) exit;
if ( ! class_exists( Restored_Reporting_State::class, false ) ) require_once __DIR__.'/Restored_Reporting_State.php';

/** Native, indexed reporting facts. Explicit preparation only; no frontend hooks or DDL. */
final class Search_Performance_Store {
	public const OPTION = 'kiwe_search_store_v1';
	public const MAX_ROWS = 60000;
	public const MAX_BUCKETS = 4000;
	public const MAX_BYTES = 50331648;
	public static function table( string $kind ): string {
		global $wpdb;$table=$wpdb->prefix.'kiwe_search_'.$kind.'_v1';
		if(!in_array($kind,['buckets','rows'],true)||!preg_match('/^[A-Za-z0-9_]+$/D',$table))throw new \RuntimeException('Search table identity is invalid.');return $table;
	}
	public static function ready(): bool { $v=get_option(self::OPTION,[]);return is_array($v)&&($v['schema']??null)===1&&!Restored_Reporting_State::blocks('search'); }
	private static function lock_key(): string { global $wpdb;return 'kiwe_search_'.substr(hash('sha256',$wpdb->dbname.'|'.$wpdb->prefix),0,32); }
	public static function lock( callable $work ) {
		global $wpdb;$key=self::lock_key();
		if((int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,0)',$key))!==1)return Search_Performance_Model::error('busy','Search reporting is busy. Retry after the current background step.');
		try{return $work();}finally{$wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$key));}
	}
	private static function owns_lock(): bool { global $wpdb;$key=self::lock_key();return (int)$wpdb->get_var($wpdb->prepare('SELECT IS_USED_LOCK(%s)=CONNECTION_ID()',$key))===1; }
	public static function prepare() {
		if(!current_user_can('manage_options'))return Search_Performance_Model::error('permission','Website-manager access is required.');
		if(Restored_Reporting_State::blocks('search'))return Search_Performance_Model::error('restored_history','Restored search history retains its source identifiers. It needs an explicit history adapter before reporting can be activated.');
		return self::lock(static function(){
			global $wpdb;$b=self::table('buckets');$r=self::table('rows');$collate=$wpdb->get_charset_collate();
			delete_option(self::OPTION); // A refused explicit preparation cannot leave stale write authority.
			$definitions=[
				$b=>"bucket char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, property_hash char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, source varchar(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, search_type varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, dataset varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, day date NOT NULL, aggregation varchar(16) NOT NULL, source_sha char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, row_count int unsigned NOT NULL, key_bytes int unsigned NOT NULL, synced_at bigint unsigned NOT NULL, PRIMARY KEY(bucket), KEY period(property_hash,source,search_type,dataset,day), KEY retention(day,bucket)",
				$r=>"row_key char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, bucket char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, property_hash char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, source varchar(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, search_type varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, dataset varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, day date NOT NULL, entity char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, page_hash char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, label text NOT NULL, secondary_label text NOT NULL, clicks bigint unsigned NOT NULL, impressions bigint unsigned NOT NULL, position double NULL, PRIMARY KEY(row_key), KEY bucket_rows(bucket), KEY entity_period(property_hash,source,search_type,dataset,entity,day), KEY page_period(property_hash,source,search_type,dataset,page_hash,day), KEY period(property_hash,source,search_type,dataset,day)",
			];
			foreach($definitions as $table=>$definition){
				$exists=$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$wpdb->esc_like($table)));
				if($wpdb->last_error)return Search_Performance_Model::error('storage','Search storage could not be inspected.');
				if(!$exists&&false===$wpdb->query("CREATE TABLE {$table} ({$definition}) ENGINE=InnoDB {$collate}"))return Search_Performance_Model::error('storage','Search storage could not be prepared.');
				$columns=$wpdb->get_results("SHOW FULL COLUMNS FROM {$table}",ARRAY_A);$indexes=$wpdb->get_results("SHOW INDEX FROM {$table}",ARRAY_A);
				$expected=$table===$b?['bucket','property_hash','source','search_type','dataset','day','aggregation','source_sha','row_count','key_bytes','synced_at']:['row_key','bucket','property_hash','source','search_type','dataset','day','entity','page_hash','label','secondary_label','clicks','impressions','position'];
				if($wpdb->last_error||!self::columns($columns,$expected)||!self::indexes($indexes,$table===$b?['PRIMARY'=>['bucket'],'period'=>['property_hash','source','search_type','dataset','day'],'retention'=>['day','bucket']]:['PRIMARY'=>['row_key'],'bucket_rows'=>['bucket'],'entity_period'=>['property_hash','source','search_type','dataset','entity','day'],'page_period'=>['property_hash','source','search_type','dataset','page_hash','day'],'period'=>['property_hash','source','search_type','dataset','day']]))return Search_Performance_Model::error('storage','Search storage columns or indexes need an explicit upgrade.');
				$definition=(string)($wpdb->get_row("SHOW CREATE TABLE {$table}",ARRAY_N)[1]??'');
				if(!preg_match('/ENGINE=InnoDB/i',$definition))return Search_Performance_Model::error('storage','Search reporting requires transactional storage.');
			}
			update_option(self::OPTION,['schema'=>1],false);return self::ready()?true:Search_Performance_Model::error('storage','Search storage readiness could not be confirmed.');
		});
	}
	private static function columns( array $rows, array $expected ): bool {
		if(array_column($rows,'Field')!==$expected)return false;
		$ascii=['bucket'=>64,'row_key'=>64,'property_hash'=>64,'source'=>24,'search_type'=>16,'dataset'=>16,'source_sha'=>64,'entity'=>64,'page_hash'=>64];
		foreach($rows as $row){
			$name=$row['Field'];$type=strtolower($row['Type']);
			if(($row['Null']??'')!==($name==='position'?'YES':'NO')||($row['Default']??null)!==null||($row['Extra']??'')!=='')return false;
			if(isset($ascii[$name])){if($type!==(($ascii[$name]===64?'char':'varchar').'('.$ascii[$name].')')||($row['Collation']??'')!=='ascii_bin')return false;}
			elseif(in_array($name,['clicks','impressions','synced_at','row_count','key_bytes'],true)){if(!preg_match('/^'.(in_array($name,['row_count','key_bytes'],true)?'int':'bigint').'(?:\(\d+\))? unsigned$/D',$type))return false;}
			elseif($type!==(['day'=>'date','aggregation'=>'varchar(16)','label'=>'text','secondary_label'=>'text','position'=>'double'][$name]??''))return false;
		}return true;
	}
	private static function indexes( array $rows, array $expected ): bool {
		$actual=[];foreach($rows as $r){if($r['Sub_part']!==null||$r['Index_type']!=='BTREE'||(int)$r['Non_unique']!==($r['Key_name']==='PRIMARY'?0:1))return false;$actual[$r['Key_name']][(int)$r['Seq_in_index']]=$r['Column_name'];}
		foreach($actual as &$v){ksort($v);$v=array_values($v);}unset($v);ksort($actual);ksort($expected);return $actual===$expected;
	}
	public static function identity( string $property, string $source, string $type, string $dataset, string $day, string $part = '' ): string { return hash('sha256',wp_json_encode([$property,$source,$type,$dataset,$day,$part])); }
	/** Caller holds the zero-wait writer lock. One bounded bucket becomes visible atomically. */
	public static function put( string $property, string $source, string $type, string $dataset, string $day, string $aggregation, array $rows, string $source_sha, string $part = '' ) {
		global $wpdb;$m=Search_Performance_Model::class;
		if(!self::ready()||!self::owns_lock()||!$m::property($property,home_url('/'))||!in_array($source,['gsc','rank-math'],true)||!in_array($type,[...$m::TYPES,'unknown'],true)||!in_array($dataset,['site','page','query','legacy'],true)||!$m::date($day)||!in_array($aggregation,['byProperty','byPage','unknown'],true)||!preg_match('/^[a-f0-9]{64}$/D',$source_sha)||count($rows)>($dataset==='legacy'?100:$m::DETAIL)||strlen($part)>40)return $m::error('bucket','The reporting bucket exceeds its supported boundary or writer ownership.');
		$b=self::table('buckets');$r=self::table('rows');$hash=self::identity($property,$source,$type,$dataset,$day,$part);$property_hash=hash('sha256',$property);$keys=0;$seen=[];
		foreach($rows as $row){
			if(!is_array($row)||array_diff(array_keys($row),['day','entity','label','secondary','clicks','impressions','position'])||!$m::date($row['day']??null)||!preg_match('/^[a-f0-9]{64}$/D',$row['entity']??'')||!$m::text($row['label']??null,2048)||!$m::text($row['secondary']??null,1000)||is_wp_error($m::metrics($row))||isset($seen[$row['entity']]))return $m::error('bucket','Invalid or duplicate reporting facts were refused.');
			if(($source==='gsc'&&$row['day']!==$day)||($dataset==='site'&&($row['label']!==''||$row['secondary']!==''||count($rows)!==1)))return $m::error('bucket','Reporting dimensions changed.');
			$keys+=strlen($row['label'])+strlen($row['secondary']);$seen[$row['entity']]=true;
		}
		$usage=$wpdb->get_row("SELECT COUNT(*) buckets,COALESCE(SUM(row_count),0) rows_used,COALESCE(SUM(key_bytes),0) key_bytes FROM (SELECT row_count,key_bytes FROM {$b} LIMIT 4001) AS bounded_usage",ARRAY_A);
		$old=$wpdb->get_row($wpdb->prepare("SELECT row_count,key_bytes FROM {$b} WHERE bucket=%s",$hash),ARRAY_A);
		if($wpdb->last_error||!$usage)return $m::error('storage','Search admission could not be inspected.');
		if((int)$usage['buckets']+($old?0:1)>self::MAX_BUCKETS||(int)$usage['rows_used']-(int)($old['row_count']??0)+count($rows)>self::MAX_ROWS||(int)$usage['key_bytes']-(int)($old['key_bytes']??0)+$keys>self::MAX_BYTES)return $m::error('capacity','Search detail capacity reached. Existing reports are preserved; reduce the chosen backfill or types.');
		if(false===$wpdb->query('START TRANSACTION'))return $m::error('storage','Search publication could not start.');
		try{
			if(false===$wpdb->query($wpdb->prepare("DELETE FROM {$r} WHERE bucket=%s",$hash)))throw new \RuntimeException();
			foreach(array_chunk($rows,20) as $chunk){$values=[];$args=[];foreach($chunk as $row){$values[]='(%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%d,%d,'.($row['position']===null?'NULL':'%s').')';$key=$source==='rank-math'?hash('sha256',$property_hash.'|rank-math|'.$row['entity']):hash('sha256',$hash.'|'.$row['entity']);array_push($args,$key,$hash,$property_hash,$source,$type,$dataset,$row['day'],$row['entity'],hash('sha256',$row['label']),$row['label'],$row['secondary'],$row['clicks'],$row['impressions']);if($row['position']!==null)$args[]=sprintf('%.17g',$row['position']);}
				if(false===$wpdb->query($wpdb->prepare("INSERT INTO {$r}(row_key,bucket,property_hash,source,search_type,dataset,day,entity,page_hash,label,secondary_label,clicks,impressions,position) VALUES ".implode(',',$values),$args)))throw new \RuntimeException();
			}
			if(false===$wpdb->query($wpdb->prepare("INSERT INTO {$b}(bucket,property_hash,source,search_type,dataset,day,aggregation,source_sha,row_count,key_bytes,synced_at) VALUES(%s,%s,%s,%s,%s,%s,%s,%s,%d,%d,%d) ON DUPLICATE KEY UPDATE aggregation=VALUES(aggregation),source_sha=VALUES(source_sha),row_count=VALUES(row_count),key_bytes=VALUES(key_bytes),synced_at=VALUES(synced_at)",$hash,$property_hash,$source,$type,$dataset,$day,$aggregation,$source_sha,count($rows),$keys,time())))throw new \RuntimeException();
			$count=$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$r} WHERE bucket=%s",$hash));
			if($wpdb->last_error||(int)$count!==count($rows)||false===$wpdb->query('COMMIT'))throw new \RuntimeException();return ['bucket'=>$hash,'rows'=>count($rows)];
		}catch(\Throwable $e){$wpdb->query('ROLLBACK');return $m::error('storage','The reporting batch could not be confirmed. Its previous version was preserved.');}
	}
	/** At most ten expired buckets and 1,000 owned detail rows per background step. */
	public static function prune() {
		global $wpdb;if(!self::ready())return true;if(!self::owns_lock())return Search_Performance_Model::error('busy','Search retention needs its writer ownership.');$b=self::table('buckets');$r=self::table('rows');$cut=Search_Performance_Model::today()->modify('-'.Search_Performance_Model::DAYS.' days')->format('Y-m-d');
		$rows=$wpdb->get_results($wpdb->prepare("SELECT bucket,row_count FROM {$b} WHERE day<%s ORDER BY day LIMIT 10",$cut),ARRAY_A);
		if($wpdb->last_error)return Search_Performance_Model::error('storage','Search retention is awaiting a storage retry.');
		foreach($rows as $row){if((int)$row['row_count']>100)return Search_Performance_Model::error('storage','An unknown reporting bucket needs review.');
			if(false===$wpdb->query('START TRANSACTION'))return Search_Performance_Model::error('storage','Search retention could not start.');
			if(false===$wpdb->query($wpdb->prepare("DELETE FROM {$r} WHERE bucket=%s",$row['bucket']))||false===$wpdb->query($wpdb->prepare("DELETE FROM {$b} WHERE bucket=%s AND day<%s",$row['bucket'],$cut))||false===$wpdb->query('COMMIT')){$wpdb->query('ROLLBACK');return Search_Performance_Model::error('storage','Search retention could not be confirmed.');}
		}return true;
	}
	public static function period( string $property, string $source, string $type, string $from, string $to, string $url = '' ): array {
		global $wpdb;$empty=['available'=>false,'complete'=>false,'metrics'=>null,'observedDays'=>0,'updatedAt'=>null];
		if(!self::ready()||!Search_Performance_Model::date($from)||!Search_Performance_Model::date($to)||$from>$to)return $empty;
		$b=self::table('buckets');$r=self::table('rows');$dataset=$source==='rank-math'?'legacy':($url?'page':'site');$type=$source==='rank-math'?'unknown':$type;
		$where='r.property_hash=%s AND r.source=%s AND r.search_type=%s AND r.dataset=%s AND r.day>=%s AND r.day<=%s';$args=[hash('sha256',$property),$source,$type,$dataset,$from,$to];
		if($url){$where.=' AND r.page_hash=%s';$args[]=hash('sha256',$url);}
		$totals=$wpdb->get_row($wpdb->prepare("SELECT COUNT(*) n,COUNT(DISTINCT r.day) days,MAX(b.synced_at) updated,SUM(r.clicks) clicks,SUM(r.impressions) impressions,SUM(r.position*r.impressions) weighted,SUM(IF(r.position IS NULL,0,r.impressions)) weights FROM {$r} r INNER JOIN {$b} b ON b.bucket=r.bucket WHERE {$where}",$args),ARRAY_A);
		if($wpdb->last_error||!$totals||(int)$totals['n']===0)return $empty;
		$days=(int)(new \DateTimeImmutable($from))->diff(new \DateTimeImmutable($to))->days+1;$i=(int)$totals['impressions'];
		return ['available'=>true,'complete'=>$source==='gsc'&&!$url&&(int)$totals['days']===$days,'metrics'=>['clicks'=>(int)$totals['clicks'],'impressions'=>$i,'ctr'=>$i?100*(int)$totals['clicks']/$i:null,'position'=>(float)$totals['weights']>0?(float)$totals['weighted']/(float)$totals['weights']:null],'observedDays'=>(int)$totals['days'],'updatedAt'=>$totals['updated']?(int)$totals['updated']:null];
	}
	/** Only an authorized site report may call this; query detail is not attributed to individual pages. */
	public static function queries( string $property, string $type, string $from, string $to ): array {
		global $wpdb;if(!self::ready())return [];$r=self::table('rows');$b=self::table('buckets');
		$rows=$wpdb->get_results($wpdb->prepare("SELECT MAX(r.label) query,SUM(r.clicks) clicks,SUM(r.impressions) impressions,SUM(r.position*r.impressions) weighted,SUM(IF(r.position IS NULL,0,r.impressions)) weights FROM {$r} r INNER JOIN {$b} b ON b.bucket=r.bucket WHERE r.property_hash=%s AND r.source='gsc' AND r.search_type=%s AND r.dataset='query' AND r.day>=%s AND r.day<=%s GROUP BY r.entity ORDER BY clicks DESC,r.entity ASC LIMIT 20",hash('sha256',$property),$type,$from,$to),ARRAY_A);
		if($wpdb->last_error||!is_array($rows))return [];
		return array_map(static fn($row)=>['query'=>$row['query'],'clicks'=>(int)$row['clicks'],'impressions'=>(int)$row['impressions'],'ctr'=>(int)$row['impressions']?100*(int)$row['clicks']/(int)$row['impressions']:null,'position'=>(float)$row['weights']>0?(float)$row['weighted']/(float)$row['weights']:null],$rows);
	}
}
