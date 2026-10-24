<?php
namespace DSA\Backup;

use DSA\Analytics\Search_Performance_Service as Source;
use DSA\Analytics\Search_Performance_Model as Model;
use DSA\Access\Backup_Workspace_Service;

if ( ! defined( 'ABSPATH' ) ) exit;

/** Typed same-site choices through the existing encrypted snapshot owner. No provider authority. */
final class Search_Performance_Snapshot_Service {
	public const NAME = 'kiwe.search.performance.choices';
	public const GROUP = 'Search reporting';
	public static function allowed(): bool { return Backup_Workspace_Service::allowed() && current_user_can('manage_options'); }
	public static function handles( string $name ): bool { return $name===self::NAME; }
	public static function groups(): array { return self::allowed()?[self::GROUP=>[self::NAME]]:[]; }
	public static function labels(): array { return [self::NAME=>'Search reporting choices']; }
	public static function read(): array {
		if(!self::allowed())throw new Archive_Error('Website-manager backup access is required.');$c=Source::connection();
		return ['schema'=>1,'types'=>$c['types']??['web']];
	}
	public static function validate_options( array $options ): void {
		if(!isset($options[self::NAME]))return;$s=$options[self::NAME];$v=$s['value']??null;
		if(!is_array($s)||($s['exists']??null)!==true||!is_array($v)||array_diff(array_keys($v),['schema','types'])||($v['schema']??null)!==1||!is_array($v['types']??null)||!array_is_list($v['types'])||!$v['types']||count($v['types'])>6||array_diff($v['types'],Model::TYPES)||count(array_unique($v['types']))!==count($v['types']))throw new Archive_Error('Invalid search reporting choices were refused.');
	}
	public static function write( array $setting ): void {
		if(!self::allowed())throw new Archive_Error('Website-manager backup access is required.');self::validate_options([self::NAME=>$setting]);
		$result=\DSA\Analytics\Search_Performance_Store::lock(static function()use($setting){
			$c=Source::connection();if(!$c)$c=['schema'=>1,'epoch'=>bin2hex(random_bytes(16)),'status'=>'not_connected','enabled'=>false,'property'=>'','properties'=>[]];
			$c['types']=$setting['value']['types'];$c['enabled']=false;$c['epoch']=bin2hex(random_bytes(16));
			wp_clear_scheduled_hook(Source::HOOK);delete_option(Source::JOB);delete_option(Source::STATE);update_option(Source::OPTION,$c,false);
			return get_option(Source::OPTION)===$c;
		});if(is_wp_error($result)||$result!==true)throw new Archive_Error('Search choices could not be confirmed.');
	}
}
