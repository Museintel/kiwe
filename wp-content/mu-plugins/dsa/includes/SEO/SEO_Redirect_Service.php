<?php

namespace DSA\SEO;

if ( ! defined( 'ABSPATH' ) ) exit;

/** Exact native redirects: indexed reads, no visitor DDL, hit writes or URI caches. */
final class SEO_Redirect_Service {
	public const OPTION = 'kiwe_seo_redirect_store_v1';
	public const LIMIT = 20000;
	public static function table(): string { global $wpdb; return $wpdb->prefix . 'kiwe_seo_redirects_v1'; }
	public static function sql(): string {
		$table=self::table();
		return "CREATE TABLE IF NOT EXISTS {$table} (id bigint unsigned NOT NULL AUTO_INCREMENT, source_hash char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, fold_hash char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, source_path varchar(2048) NOT NULL, ignore_case tinyint unsigned NOT NULL DEFAULT 0, destination varchar(2048) NOT NULL, code smallint unsigned NOT NULL, state varchar(12) NOT NULL DEFAULT 'pending', source_id bigint unsigned NOT NULL DEFAULT 0, source_sha char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, imported_sha char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, PRIMARY KEY (id), UNIQUE KEY source_hash (source_hash), KEY fold_hash (fold_hash), KEY source_id (source_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
	}
	public static function prepare_store() {
		global $wpdb;
		// Invoked only by an explicitly authorized manager mutation, never registration/GET.
		if ( ! current_user_can( 'manage_options' ) ) return new \WP_Error( 'kiwe_redirect_forbidden', 'Website manager authority is required.' );
		$prepared = 1 === (int) ( get_option( self::OPTION, [] )['schema'] ?? 0 );
		if ( ! $prepared && false === $wpdb->query( self::sql() ) ) return new \WP_Error( 'kiwe_redirect_store', 'Redirect storage could not be prepared.' );
		$indexes=$wpdb->get_results( 'SHOW INDEX FROM ' . self::table(), ARRAY_A );
		if ( $wpdb->last_error || ! $indexes ) return new \WP_Error( 'kiwe_redirect_store', 'Redirect indexes were not verified.' );
		$keys=[]; foreach($indexes as$i)$keys[$i['Key_name']][]=[ $i['Column_name'], (int)$i['Non_unique'], (int)$i['Seq_in_index'], $i['Sub_part'] ];
		foreach(['PRIMARY'=>['id',0,1,null],'source_hash'=>['source_hash',0,1,null],'fold_hash'=>['fold_hash',1,1,null],'source_id'=>['source_id',1,1,null]]as$key=>$definition)if(($keys[$key]??[])!==[$definition])return new \WP_Error('kiwe_redirect_store','Redirect index definitions require review.');
		$columns=$wpdb->get_results( 'SHOW FULL COLUMNS FROM ' . self::table(), ARRAY_A );
		if($wpdb->last_error||count($columns)!==11)return new \WP_Error('kiwe_redirect_store','Redirect columns require review.');
		$actual=[];foreach($columns as$c)$actual[$c['Field']]=$c;
		foreach(['id'=>'bigint','source_hash'=>'char(64)','fold_hash'=>'char(64)','source_path'=>'varchar(2048)','ignore_case'=>'tinyint','destination'=>'varchar(2048)','code'=>'smallint','state'=>'varchar(12)','source_id'=>'bigint','source_sha'=>'char(64)','imported_sha'=>'char(64)']as$field=>$type)if(!isset($actual[$field])||!str_starts_with($actual[$field]['Type'],$type)||$actual[$field]['Null']!=='NO')return new \WP_Error('kiwe_redirect_store','Redirect column definitions require review.');
		foreach(['source_hash','fold_hash','source_sha','imported_sha']as$field)if($actual[$field]['Collation']!=='ascii_bin')return new \WP_Error('kiwe_redirect_store','Redirect ownership must use exact binary hashes.');
		$create=$wpdb->get_row( 'SHOW CREATE TABLE ' . self::table(), ARRAY_N );
		if($wpdb->last_error||!preg_match('/ENGINE=InnoDB\b/i',$create[1]??'')||!str_contains($actual['id']['Extra'],'auto_increment'))return new \WP_Error('kiwe_redirect_store','Redirect storage must preserve atomic InnoDB statements.');
		if($prepared)return true;
		$value=[ 'schema'=>1, 'enabled'=>false, 'observe404'=>false ]; update_option( self::OPTION, $value, false );
		return get_option( self::OPTION ) === $value ? true : new \WP_Error( 'kiwe_redirect_store', 'Redirect storage readback failed.' );
	}
	public function register(): void { add_action( 'template_redirect', [ $this, 'handle' ], 1 ); }
	public static function source_path( string $value ) {
		if ( strlen( $value ) > 2048 || !preg_match('//u',$value) || preg_match('/%(?![0-9a-f]{2})/i',$value) || preg_match( '/[\x00-\x20\x7f\\\\]/', $value ) || preg_match( '/%(?:00|0a|0d|2f|5c)/i', $value ) || str_contains( $value, '?' ) || str_contains( $value, '#' ) ) return new \WP_Error( 'kiwe_redirect_path', 'This source path needs explicit support before handover.' );
		if ( preg_match( '~^https?://~i', $value ) ) {
			$p=wp_parse_url($value);$home=wp_parse_url(home_url('/'));
			if ( ! is_array($p) || isset($p['user']) || isset($p['pass']) || strtolower($p['host']??'')!==strtolower($home['host']??'') || (int)($p['port']??0)!==(int)($home['port']??0) ) return new \WP_Error('kiwe_redirect_path','The source must belong to this website.');
			$value=$p['path']??'/';$base=rtrim($home['path']??'','/');
			if ($base!==''&&!str_starts_with($value,$base.'/')) return new \WP_Error('kiwe_redirect_path','The source is outside this website root.');
			if($base!=='')$value=substr($value,strlen($base));
		}
		if ( str_contains($value, '://') || str_starts_with($value,'//') || preg_match('~(?:^|/)\.{1,2}(?:/|$)~',rawurldecode($value)) ) return new \WP_Error('kiwe_redirect_path','Ambiguous source paths are unsupported.');
		$value=trim($value,'/');
		if($value===''||preg_match('~^(?:wp-admin(?:/|$)|wp-json(?:/|$)|wp-login\.php(?:/|$)|wp-cron\.php(?:/|$))~i',$value))return new \WP_Error('kiwe_redirect_path','Protected website routes cannot be redirected.');
		return $value;
	}
	public static function destination( string $value ) {
		if ( strlen($value)>2048 || !preg_match('//u',$value) || preg_match('/%(?![0-9a-f]{2})/i',$value) || preg_match('/[\x00-\x20\x7f\\\\]/',$value) || preg_match('/%(?:00|0a|0d)/i',$value) || str_starts_with($value,'//') ) return new \WP_Error('kiwe_redirect_target','The destination is unsafe or oversized.');
		if(str_starts_with($value,'/'))$value=home_url($value);
		$p=wp_parse_url($value);
		if(strlen($value)>2048||!is_array($p)||!in_array(strtolower($p['scheme']??''),['http','https'],true)||empty($p['host'])||isset($p['user'])||isset($p['pass'])||!filter_var($value,FILTER_VALIDATE_URL))return new \WP_Error('kiwe_redirect_target','Choose a complete HTTP or HTTPS destination.');
		return $value;
	}
	public static function rule( string $path, string $target, int $code, bool $ignore=false ) {
		$path=self::source_path($path);if(is_wp_error($path))return$path;
		if(!in_array($code,[301,302,307,308,410,451],true))return new \WP_Error('kiwe_redirect_status','The source status needs explicit support.');
		if(in_array($code,[410,451],true))$target='';else{$target=self::destination($target);if(is_wp_error($target))return$target;}
		$r=['source_path'=>$path,'destination'=>$target,'code'=>$code,'ignore_case'=>$ignore?1:0];
		$r['source_hash']=hash('sha256',($ignore?'i:':'c:').($ignore?strtolower($path):$path));$r['fold_hash']=hash('sha256',strtolower($path));$r['imported_sha']=self::fingerprint($r);return$r;
	}
	public static function fingerprint( array $row ): string { return hash('sha256',(string)wp_json_encode([(string)$row['source_path'],(string)$row['destination'],(int)$row['code'],(int)$row['ignore_case']])); }

	/** New rows stay pending until one atomic publication statement; replay is idempotent. */
	public static function stage( array $rules, int $source_id, string $sha, string $expected_target = '' ) {
		global $wpdb;$table=self::table();
		if(!current_user_can('manage_options')||$source_id<=0||1!==(int)(get_option(self::OPTION,[])['schema']??0)||!$rules||count($rules)>16||!preg_match('/^[a-f0-9]{64}$/D',$sha))return new \WP_Error('kiwe_redirect_store','A reviewed bounded import and prepared store are required.');
		$checked=[];foreach($rules as$r){if(!is_array($r)||!isset($r['source_path'],$r['destination'],$r['code'],$r['ignore_case']))return new \WP_Error('kiwe_redirect_source','Invalid redirect input.');$clean=self::rule((string)$r['source_path'],(string)$r['destination'],(int)$r['code'],(bool)$r['ignore_case']);if(is_wp_error($clean)||$clean!==$r||isset($checked[$clean['source_hash']]))return new \WP_Error('kiwe_redirect_source','Invalid or duplicate redirect input.');$checked[$clean['source_hash']]=true;}
		$lock='kiwe_redirect_'.substr(hash('sha256',$table),0,32);
		if('1'!==(string)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,0)',$lock)))return new \WP_Error('kiwe_redirect_busy','Another redirect change is in progress.');
		try {
			if($expected_target!==''){
				$fresh=SEO_Structured_Import_Service::redirect_review($source_id);
				if(is_wp_error($fresh)||$fresh['sourceSha']!==$sha||$fresh['targetSha']!==$expected_target)return new \WP_Error('kiwe_redirect_conflict','Source or destination changed while storage was prepared.');
			}
			$count=$wpdb->get_var("SELECT COUNT(*) FROM {$table}");if($wpdb->last_error||null===$count)return new \WP_Error('kiwe_redirect_read','Redirect capacity is unavailable.');
			$owned=$wpdb->get_results($wpdb->prepare("SELECT * FROM {$table} WHERE source_id=%d ORDER BY id LIMIT 17",$source_id),ARRAY_A);
			if($wpdb->last_error||count($owned)>16)return new \WP_Error('kiwe_redirect_read','The import journal is unavailable.');
			if((int)$count+count($rules)-count($owned)>self::LIMIT)return new \WP_Error('kiwe_redirect_capacity','Redirect capacity requires review.');
			foreach($owned as$row)if($row['source_sha']!==$sha||$row['imported_sha']!==self::fingerprint($row))return new \WP_Error('kiwe_redirect_conflict','Existing imported rules have changed.');
			foreach($rules as$r){
				$overlap=$wpdb->get_results($wpdb->prepare("SELECT * FROM {$table} WHERE fold_hash=%s LIMIT 17",$r['fold_hash']),ARRAY_A);
				if($wpdb->last_error||count($overlap)>16)return new \WP_Error('kiwe_redirect_conflict','The path inventory needs review.');
				foreach($overlap as$o)if($o['source_hash']===$r['source_hash']||(int)$o['ignore_case']||$r['ignore_case'])if((int)$o['source_id']!==$source_id||$o['source_sha']!==$sha||$o['imported_sha']!==$r['imported_sha'])return new \WP_Error('kiwe_redirect_conflict','A redirect already owns this path.');
			}
			foreach($rules as$r){$existing=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE source_hash=%s",$r['source_hash']),ARRAY_A);if($wpdb->last_error)return new \WP_Error('kiwe_redirect_read','Redirect read failed.');if(!$existing&&false===$wpdb->insert($table,$r+['state'=>'pending','source_id'=>$source_id,'source_sha'=>$sha]))return new \WP_Error('kiwe_redirect_write','Import paused with pending rules; review and resume.');}
			$rows=$wpdb->get_results($wpdb->prepare("SELECT * FROM {$table} WHERE source_id=%d AND source_sha=%s LIMIT 17",$source_id,$sha),ARRAY_A);
			if($wpdb->last_error||count($rows)!==count($rules))return new \WP_Error('kiwe_redirect_write','The pending import is incomplete.');
			$expected=array_column($rules,'imported_sha','source_hash');foreach($rows as$r)if(($expected[$r['source_hash']]??null)!==self::fingerprint($r)||$r['imported_sha']!==self::fingerprint($r))return new \WP_Error('kiwe_redirect_conflict','A staged redirect changed before publication.');
			if(false===$wpdb->query($wpdb->prepare("UPDATE {$table} SET state='active' WHERE source_id=%d AND source_sha=%s",$source_id,$sha)))return new \WP_Error('kiwe_redirect_write','Publication requires readback before retry.');
			$active=$wpdb->get_results($wpdb->prepare("SELECT * FROM {$table} WHERE source_id=%d AND source_sha=%s AND state='active' LIMIT 17",$source_id,$sha),ARRAY_A);
			if($wpdb->last_error||count($active)!==count($rules))return new \WP_Error('kiwe_redirect_write','Publication readback failed.');
			foreach($active as$r)if(($expected[$r['source_hash']]??null)!==self::fingerprint($r)||$r['imported_sha']!==self::fingerprint($r))return new \WP_Error('kiwe_redirect_conflict','A published redirect changed.');
			return true;
		} finally {$wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock));}
	}
	public static function undo( int $source_id, string $expected ) {
		global $wpdb;$table=self::table();$lock='kiwe_redirect_'.substr(hash('sha256',$table),0,32);
		if(!current_user_can('manage_options')||$source_id<=0)return new \WP_Error('kiwe_redirect_forbidden','Website manager authority is required.');
		if('1'!==(string)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,0)',$lock)))return new \WP_Error('kiwe_redirect_busy','Another redirect change is in progress.');
		try{
			$rows=$wpdb->get_results($wpdb->prepare("SELECT * FROM {$table} WHERE source_id=%d ORDER BY id LIMIT 17",$source_id),ARRAY_A);if($wpdb->last_error||!$rows||count($rows)>16)return new \WP_Error('kiwe_redirect_undo','The import journal is unavailable.');
			if(!hash_equals($expected,hash('sha256',(string)wp_json_encode($rows))))return new \WP_Error('kiwe_redirect_conflict','The redirect journal changed.');
			foreach($rows as$r)if($r['imported_sha']!==self::fingerprint($r))return new \WP_Error('kiwe_redirect_conflict','Newer redirect edits are protected.');
			$sha=$rows[0]['source_sha'];foreach($rows as$r)if($r['source_sha']!==$sha)return new \WP_Error('kiwe_redirect_conflict','Mixed import ownership needs review.');
			$conditions=[];$args=[];foreach($rows as$r){$conditions[]='(id=%d AND BINARY source_path=%s AND BINARY destination=%s AND code=%d AND ignore_case=%d AND BINARY state=%s AND source_sha=%s AND imported_sha=%s)';array_push($args,(int)$r['id'],$r['source_path'],$r['destination'],(int)$r['code'],(int)$r['ignore_case'],$r['state'],$r['source_sha'],$r['imported_sha']);}
			if(false===$wpdb->query($wpdb->prepare("DELETE FROM {$table} WHERE ".implode(' OR ',$conditions),...$args)))return new \WP_Error('kiwe_redirect_undo','Undo requires readback before retry.');
			$left=$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE source_id=%d",$source_id));return !$wpdb->last_error&&0===(int)$left?true:new \WP_Error('kiwe_redirect_undo','Undo readback failed.');
		}finally{$wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock));}
	}
	private function lookup( string $path ) {
		global $wpdb;$table=self::table();$c=hash('sha256','c:'.$path);$i=hash('sha256','i:'.strtolower($path));
		$rows=$wpdb->get_results($wpdb->prepare("SELECT source_path,destination,code,ignore_case FROM {$table} WHERE source_hash IN (%s,%s) AND state='active' LIMIT 2",$c,$i),ARRAY_A);
		if($wpdb->last_error)return new \WP_Error('kiwe_redirect_read','Redirect lookup is unavailable.');
		if(count($rows)>1)return new \WP_Error('kiwe_redirect_conflict','Conflicting redirects require review.');
		return $rows[0]??null;
	}
	public function decision( string $uri, string $method='GET' ) {
		if ( ! in_array( $method, ['GET','HEAD'], true ) || ! SEO_Defaults_Service::enabled() ) return null;
		$exact = $this->exact_decision( $uri, $method );
		return null === $exact ? $this->policy_decision( $uri, $method ) : $exact;
	}
	/** Policy reads reuse native query identity and the same saved SEO defaults. */
	public function policy_decision( string $uri, string $method='GET' ) {
		if ( ! in_array( $method, ['GET','HEAD'], true ) || ! SEO_Defaults_Service::enabled() || strlen( $uri ) > 4096 ) return null;
		$parts = wp_parse_url( $uri );
		if ( ! is_array( $parts ) || isset( $parts['host'] ) || isset( $parts['scheme'] ) || preg_match( '/[\x00-\x20\x7f\\\\]/', $uri ) || preg_match( '/%(?:00|0a|0d)/i', $uri ) ) return null;
		$home = wp_parse_url( home_url( '/' ) ); $origin = ( $home['scheme'] ?? 'https' ) . '://' . ( $home['host'] ?? '' ) . ( isset( $home['port'] ) ? ':' . $home['port'] : '' );
		$path = $parts['path'] ?? '/'; $relative = self::source_path( $origin . $path );
		// Root query archives/attachments are valid; protected or ambiguous paths never are.
		if ( is_wp_error( $relative ) && rtrim( $path, '/' ) !== rtrim( $home['path'] ?? '', '/' ) ) return null;
		$policy = SEO_Defaults_Service::settings()['policies']; $target = '';
		if ( ( $policy['redirectDateArchives'] && is_date() ) || ( $policy['redirectAuthorArchives'] && is_author() ) ) $target = home_url();
		elseif ( $policy['redirectAttachments'] && is_attachment() ) {
			$attachment = get_post( get_queried_object_id() ); $parent = $attachment && $attachment->post_parent ? get_post( $attachment->post_parent ) : null;
			if ( $parent && $parent->ID !== $attachment->ID && 'attachment' !== $parent->post_type && is_post_publicly_viewable( $parent ) ) $target = (string) get_permalink( $parent );
			if ( '' === $target ) $target = $policy['attachmentFallback'] ?: home_url();
		}
		if ( '' === $target ) return null;
		$target = self::destination( $target ); if ( is_wp_error( $target ) ) return $target;
		$current = wp_parse_url( $origin . $path ); $to = wp_parse_url( $target );
		if ( strtolower( $current['host'] ?? '' ) === strtolower( $to['host'] ?? '' ) && (int) ( $current['port'] ?? 0 ) === (int) ( $to['port'] ?? 0 ) && rtrim( $current['path'] ?? '', '/' ) === rtrim( $to['path'] ?? '', '/' ) && ( $parts['query'] ?? '' ) === ( $to['query'] ?? '' ) ) return new \WP_Error( 'kiwe_redirect_loop', 'Archive redirect loop refused.' );
		if ( ! is_wp_error( $relative ) && strtolower( $home['host'] ?? '' ) === strtolower( $to['host'] ?? '' ) && (int) ( $home['port'] ?? 0 ) === (int) ( $to['port'] ?? 0 ) ) {
			$chain = $this->exact_decision( ( $to['path'] ?? '/' ) . ( isset( $to['query'] ) ? '?' . $to['query'] : '' ), $method, $relative );
			if ( is_wp_error( $chain ) ) return $chain;
		}
		return [ 'code'=>301, 'url'=>$target ];
	}
	private function exact_decision( string $uri, string $method='GET', string $forbidden='' ) {
		if(!in_array($method,['GET','HEAD'],true)||!SEO_Defaults_Service::enabled())return null;
		$settings=get_option(self::OPTION,[]);if(1!==($settings['schema']??0)||true!==($settings['enabled']??false))return null;
		$p=wp_parse_url($uri);if(!is_array($p)||isset($p['host'])||isset($p['scheme']))return null;
		$home=wp_parse_url(home_url('/'));$origin=($home['scheme']??'https').'://'.($home['host']??'').(isset($home['port'])?':'.$home['port']:'');
		$path=self::source_path($origin.($p['path']??''));if(is_wp_error($path))return null;$query=$p['query']??'';
		if(strlen($query)>2048||preg_match('/[\x00-\x20\x7f]/',$query)||preg_match('/%(?:00|0a|0d)/i',$query))return null;
		$first=null;$seen=$forbidden!==''?[strtolower($forbidden)=>true]:[];
		for($hop=0;$hop<8;$hop++){
			$key=strtolower($path);if(isset($seen[$key]))return new \WP_Error('kiwe_redirect_loop','Redirect loop refused.');$seen[$key]=true;
			$row=$this->lookup($path);if(is_wp_error($row))return$row;if(!$row)return$first;
			if(!$first){$first=['code'=>(int)$row['code'],'url'=>$row['destination']];if($query!==''&&$first['url']!==''){$parts=explode('#',$first['url'],2);$parts[0].=(str_contains($parts[0],'?')?'&':'?').$query;$first['url']=implode('#',$parts);if(strlen($first['url'])>4096)return new \WP_Error('kiwe_redirect_size','Redirect URL exceeds its output budget.');}}
			if(in_array((int)$row['code'],[410,451],true))return$first;
			$target=self::destination($row['destination']);if(is_wp_error($target))return$target;
			$to=wp_parse_url($target);$home=wp_parse_url(home_url('/'));if(strtolower($to['host'])!==strtolower($home['host'])||(int)($to['port']??0)!==(int)($home['port']??0))return$first;
			$next=self::source_path(strtok($target,'?#'));if(is_wp_error($next))return$first;$path=$next;
		}
		return new \WP_Error('kiwe_redirect_depth','Redirect chain exceeds the reviewed hop budget.');
	}
	public function handle(): void {
		if(is_admin()||wp_doing_ajax()||(defined('REST_REQUEST')&&REST_REQUEST)||is_preview()||headers_sent())return;
		$decision=$this->decision((string)($_SERVER['REQUEST_URI']??'/'),(string)($_SERVER['REQUEST_METHOD']??'GET'));
		if(!$decision||is_wp_error($decision))return;
		if(in_array($decision['code'],[410,451],true)){status_header($decision['code']);nocache_headers();exit;}
		if(wp_redirect($decision['url'],$decision['code'],'Kiwe'))exit;
	}
}
