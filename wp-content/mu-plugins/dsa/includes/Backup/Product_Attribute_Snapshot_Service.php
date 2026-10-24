<?php
namespace DSA\Backup;
use DSA\Access\Backup_Workspace_Service;
use DSA\Access\Product_Attribute_Workspace_Service;
if ( ! defined( 'ABSPATH' ) ) exit;

/** Typed same-site label recovery. Definitions, IDs, assignments and SEO retain native authority. */
final class Product_Attribute_Snapshot_Service {
	public const NAME = 'woocommerce.attribute_labels';
	public const GROUP = 'Product attribute labels';
	public const SCHEMA = 'kiwe.product-attribute-labels.v1';
	public static function handles( string $name ): bool { return $name === self::NAME; }
	public static function allowed(): bool { return Backup_Workspace_Service::allowed() && Product_Attribute_Workspace_Service::manageable(); }
	public static function groups(): array { return self::allowed() ? [ self::GROUP=>[self::NAME] ] : []; }
	public static function descriptions(): array { return [ self::GROUP=>'Names of existing native product attributes and values. IDs, addresses, visibility, product choices, variations and SEO stay current; missing records are not recreated.' ]; }
	private static function fail( string $message ): void { throw new Archive_Error( $message ); }
	private static function native( $result ): array { if ( is_wp_error($result) || !is_array($result) ) self::fail(is_wp_error($result)?$result->get_error_message():'Native attribute recovery failed.'); return $result; }
	public static function read(): array { if (!self::allowed()) self::fail('Your account cannot recover product attribute labels.'); return self::native((new Product_Attribute_Workspace_Service())->label_snapshot()); }
	private static function keys( $value, array $keys ): bool { return is_array($value) && count($value)===count($keys) && !array_diff(array_keys($value),$keys); }
	private static function label( $value ): bool { return is_string($value) && $value!=='' && strlen($value)<=120 && trim($value)===$value && sanitize_text_field($value)===$value; }
	public static function validate_options( array $options ): void {
		if (!array_key_exists(self::NAME,$options)) return;
		$setting=$options[self::NAME];
		if (!self::keys($setting,['exists','value']) || $setting['exists']!==true || !self::keys($setting['value'],['schema','records']) || $setting['value']['schema']!==self::SCHEMA || !is_array($setting['value']['records']) || !array_is_list($setting['value']['records']) || count($setting['value']['records'])>50) self::fail('The attribute label snapshot is invalid.');
		$current=array_column(self::read()['records'],null,'id'); $last=0; $count=0;
		foreach ($setting['value']['records'] as $row) {
			if (!self::keys($row,['id','identity','name','terms']) || !is_int($row['id']) || $row['id']<=$last || !isset($current[$row['id']]) || !is_string($row['identity']) || !hash_equals($current[$row['id']]['identity'],$row['identity']) || !self::label($row['name']) || !is_array($row['terms']) || !array_is_list($row['terms'])) self::fail('An attribute identity, native setup or saved label changed.');
			$last=$row['id']; $terms=array_column($current[$last]['terms'],null,'id'); $lastTerm=0;
			foreach ($row['terms'] as $term) {
				if (++$count>500 || !self::keys($term,['id','identity','name']) || !is_int($term['id']) || $term['id']<=$lastTerm || !isset($terms[$term['id']]) || !is_string($term['identity']) || !hash_equals($terms[$term['id']]['identity'],$term['identity']) || !self::label($term['name'])) self::fail('An attribute value is missing, changed or unsupported.');
				$lastTerm=$term['id'];
			}
		}
	}
	public static function merge_options( array $options ): array {
		if (!array_key_exists(self::NAME,$options)) return $options;
		self::validate_options($options); $records=array_column(self::read()['records'],null,'id');
		foreach ($options[self::NAME]['value']['records'] as $row) {
			$terms=array_column($records[$row['id']]['terms'],null,'id');
			foreach ($row['terms'] as $term) $terms[$term['id']]=$term;
			ksort($terms,SORT_NUMERIC); $row['terms']=array_values($terms); $records[$row['id']]=$row;
		}
		ksort($records,SORT_NUMERIC); $options[self::NAME]['value']['records']=array_values($records); self::validate_options($options); return $options;
	}
	public static function review( array $before,array $next ): array {
		if (!isset($next[self::NAME])) return [];
		$current=array_column($before[self::NAME]['value']['records'],null,'id'); $changes=[];
		foreach ($next[self::NAME]['value']['records'] as $row) {
			if ($current[$row['id']]['name']!==$row['name']) $changes[]=['label'=>'Attribute #'.$row['id'],'before'=>$current[$row['id']]['name'],'after'=>$row['name']];
			$terms=array_column($current[$row['id']]['terms'],null,'id');
			foreach ($row['terms'] as $term) if ($terms[$term['id']]['name']!==$term['name']) $changes[]=['label'=>$row['name'].' · value #'.$term['id'],'before'=>$terms[$term['id']]['name'],'after'=>$term['name']];
		}
		return [['label'=>self::GROUP,'changes'=>$changes]];
	}
	public static function write( array $setting ): void {
		self::validate_options([self::NAME=>$setting]); $native=new Product_Attribute_Workspace_Service(); $before=array_column(self::read()['records'],null,'id');
		foreach ($setting['value']['records'] as $row) {
			if ($before[$row['id']]['name']!==$row['name']) {
				$catalog=self::native($native->catalog());
				self::native($native->change($row['id'],0,['action'=>'rename','name'=>$row['name'],'expected'=>$catalog['expected'],'acceptEffect'=>true]));
			}
			$priorTerms=array_column($before[$row['id']]['terms'],null,'id');
			foreach ($row['terms'] as $term) {
				if ($priorTerms[$term['id']]['name']===$term['name']) continue;
				$values=self::native($native->values($row['id']));
				self::native($native->change($row['id'],$term['id'],['action'=>'rename','name'=>$term['name'],'expected'=>$values['expected'],'acceptEffect'=>true],true));
			}
		}
		if (self::read()!==$setting['value']) self::fail('Recovered attribute labels differ. Review the before-copy before retrying.');
	}
}
