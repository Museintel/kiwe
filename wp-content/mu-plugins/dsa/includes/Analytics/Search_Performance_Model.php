<?php
namespace DSA\Analytics;

if ( ! defined( 'ABSPATH' ) ) exit;

/** Search Console dates and aggregation are provider facts, separate from local visits. */
final class Search_Performance_Model {
	public const TYPES = [ 'web','image','video','news','discover','googleNews' ];
	public const DAYS = 190;
	public const PAGE = 20;
	public const DETAIL = 40;
	public const SCOPE = 'https://www.googleapis.com/auth/webmasters.readonly';
	public static function error( string $code, string $message ): \WP_Error { return new \WP_Error( 'kiwe_search_' . $code,$message ); }
	public static function date( $date ): bool {
		if ( ! is_string( $date ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/D',$date ) ) return false;
		$d = \DateTimeImmutable::createFromFormat( '!Y-m-d',$date,new \DateTimeZone( 'America/Los_Angeles' ) );
		return $d && $d->format( 'Y-m-d' ) === $date;
	}
	public static function today(): \DateTimeImmutable { return new \DateTimeImmutable( 'today',new \DateTimeZone( 'America/Los_Angeles' ) ); }
	public static function range( int $days ): array {
		$days = in_array( $days,[7,30,90],true ) ? $days : 30;
		$end = self::today()->modify( '-3 days' ); $from = $end->modify( '-' . ( $days - 1 ) . ' days' );
		return [ 'from'=>$from->format('Y-m-d'),'to'=>$end->format('Y-m-d'),'prior'=>$from->modify('-'.$days.' days')->format('Y-m-d'),'priorTo'=>$from->modify('-1 day')->format('Y-m-d'),'days'=>$days ];
	}
	public static function text( $value, int $limit ): bool { return is_string($value) && strlen($value)<=$limit && !preg_match('/[\x00-\x1f\x7f]/',$value) && (bool)preg_match('//u',$value); }
	public static function url( $url ): bool {
		if ( ! self::text($url,2048) || !filter_var($url,FILTER_VALIDATE_URL) ) return false;
		$p=wp_parse_url($url);return is_array($p) && in_array($p['scheme']??'',['https','http'],true) && !isset($p['user'],$p['pass']) && !isset($p['user']) && !isset($p['fragment']);
	}
	/** Only properties actually containing this native website may be selected. */
	public static function property( $property, string $home ): bool {
		if ( ! self::text($property,2048) || !self::url($home) ) return false;
		$h=wp_parse_url($home);$host=strtolower($h['host']);
		if(str_starts_with($property,'sc-domain:')){
			$domain=substr($property,10);return (bool)preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/D',$domain) && ($host===$domain || str_ends_with($host,'.'.$domain));
		}
		if(!self::url($property))return false;$p=wp_parse_url($property);
		return strtolower($p['host'])===$host && $p['scheme']===$h['scheme'] && (int)($p['port']??0)===(int)($h['port']??0) && str_starts_with(($h['path']??'/').'/',rtrim($p['path']??'/','/').'/');
	}
	public static function properties( $body, string $home ) {
		if(!is_array($body)||array_diff(array_keys($body),['siteEntry'])||!is_array($body['siteEntry']??[])||count($body['siteEntry']??[])>1000)return self::error('properties','The property list exceeded its supported response boundary.');
		$out=[];foreach($body['siteEntry']??[] as $row){
			if(!is_array($row)||!is_string($row['siteUrl']??null)||!in_array($row['permissionLevel']??'',['siteOwner','siteFullUser','siteRestrictedUser','siteUnverifiedUser'],true))return self::error('properties','The property permission response is incomplete.');
			if($row['permissionLevel']!=='siteUnverifiedUser'&&self::property($row['siteUrl'],$home))$out[$row['siteUrl']]=['property'=>$row['siteUrl'],'permission'=>$row['permissionLevel']];
		}return array_values($out);
	}
	public static function query( string $date, string $type, string $dataset, int $offset = 0 ) {
		if(!self::date($date)||!in_array($type,self::TYPES,true)||!in_array($dataset,['site','page','query'],true)||!in_array($offset,[0,self::PAGE],true)||($dataset==='site'&&$offset)||($dataset==='query'&&in_array($type,['discover','googleNews'],true)))return self::error('query','Choose a supported date, search type and reporting dimension.');
		return ['startDate'=>$date,'endDate'=>$date,'type'=>$type,'dimensions'=>$dataset==='site'?[]:[$dataset],'aggregationType'=>($dataset==='page'||in_array($type,['discover','googleNews'],true))?'byPage':'byProperty','dataState'=>'final','rowLimit'=>$dataset==='site'?1:self::PAGE,'startRow'=>$offset];
	}
	public static function metrics( $row ) {
		if(!is_array($row))return self::error('metrics','Search measurements are invalid.');
		foreach(['clicks','impressions'] as $k)if(!isset($row[$k])||!is_numeric($row[$k])||!is_finite((float)$row[$k])||(float)$row[$k]<0||(float)$row[$k]>1000000000000||floor((float)$row[$k])!==(float)$row[$k])return self::error('metrics','Search counts exceed their numeric boundary.');
		if((float)$row['clicks']>(float)$row['impressions'])return self::error('metrics','Search clicks exceed impressions.');
		$p=$row['position']??null;if($p!==null&&(!is_numeric($p)||!is_finite((float)$p)||(float)$p<0||(float)$p>1000000))return self::error('metrics','Search position is invalid.');
		if(isset($row['ctr'])&&(!is_numeric($row['ctr'])||!is_finite((float)$row['ctr'])||(float)$row['ctr']<0||(float)$row['ctr']>1))return self::error('metrics','Search click-through rate is invalid.');
		return ['clicks'=>(int)$row['clicks'],'impressions'=>(int)$row['impressions'],'position'=>$p===null?null:(float)$p];
	}
	/** Empty site responses record observed no rows; detail never implies exhaustive query coverage. */
	public static function response( $body, array $request ) {
		if(!is_array($body)||array_diff(array_keys($body),['rows','responseAggregationType','metadata'])||!is_array($body['rows']??[])||!array_is_list($body['rows']??[])||count($body['rows']??[])>$request['rowLimit']||($body['responseAggregationType']??'')!==$request['aggregationType']||!empty($body['metadata']))return self::error('response','Search response aggregation or final-date semantics changed.');
		$dataset=$request['dimensions'][0]??'site';$rows=[];$seen=[];
		foreach($body['rows']??[] as $row){
			if(!is_array($row)||array_diff(array_keys($row),['keys','clicks','impressions','ctr','position'])||!is_array($row['keys']??[])||count($row['keys']??[])!==($dataset==='site'?0:1))return self::error('response','Search dimensions do not match the requested report.');
			$m=self::metrics($row);if(is_wp_error($m))return $m;$label=$row['keys'][0]??'';
			if(($dataset==='page'&&!self::url($label))||($dataset==='query'&&!self::text($label,1000)))return self::error('response','A search dimension exceeds the supported boundary.');
			$key=hash('sha256',$label);if(isset($seen[$key]))return self::error('response','Duplicate search dimensions require a fresh synchronization.');$seen[$key]=true;
			$rows[]=['day'=>$request['startDate'],'entity'=>$key,'label'=>$label,'secondary'=>'',...$m];
		}
		if($dataset==='site'&&!$rows)$rows[]=['day'=>$request['startDate'],'entity'=>hash('sha256',''),'label'=>'','secondary'=>'','clicks'=>0,'impressions'=>0,'position'=>null];
		return $rows;
	}
	public static function weighted( array $rows ): array {
		$c=0;$i=0;$n=0.0;$w=0;foreach($rows as $r){$c+=(int)$r['clicks'];$i+=(int)$r['impressions'];if($r['position']!==null){$n+=(float)$r['position']*(int)$r['impressions'];$w+=(int)$r['impressions'];}}
		return ['clicks'=>$c,'impressions'=>$i,'ctr'=>$i?100*$c/$i:null,'position'=>$w?$n/$w:null];
	}
}
