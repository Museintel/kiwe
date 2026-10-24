<?php
declare(strict_types=1);
namespace {
	define( 'ABSPATH', __DIR__ );
	$checks = []; $caps = []; $signedIn = true; $owner = true; $bricks = true; $source = 'service_test'; $extensions = []; $hookThrows = false;
	$types = [];
	foreach ( [ 'post','page','product','bricks_template','service_test' ] as $type ) $types[$type] = (object) [ 'hierarchical'=>false,'cap'=>(object) [ 'edit_posts'=>'edit_' . $type,'create_posts'=>'create_' . $type ] ];
	function get_post_type_object( $name ) { return $GLOBALS['types'][$name] ?? null; }
	function is_user_logged_in() { return $GLOBALS['signedIn']; }
	function is_multisite() { return false; }
	function current_user_can( $cap ) { return $GLOBALS['caps'][$cap] ?? true; }
	function get_option( $name, $default = false ) { return $name === 'profile_test' ? [ 'services'=>[ 'sourcePostType'=>$GLOBALS['source'] ] ] : $default; }
	function sanitize_text_field( $text ) { return trim( preg_replace( '/[\r\n\t]+/', ' ', strip_tags( $text ) ) ); }
	function apply_filters( $name, $value ) { if ( $GLOBALS['hookThrows'] ) throw new \RuntimeException( 'private hook error' ); return $name === 'kiwe_backup_unit_declarations' ? $GLOBALS['extensions'] : $value; }
	if ( ! in_array( '--without-woo', $argv, true ) ) { function WC() { return true; } function wc_get_product( $id ) { return null; } }
	function expect( bool $value, string $label ): void { $GLOBALS['checks'][$label] = $value; if ( ! $value ) throw new \RuntimeException( $label ); }
}
namespace DSA\Access {
	class Backup_Workspace_Service { public static function allowed(): bool { return $GLOBALS['signedIn'] && $GLOBALS['owner']; } }
	class Template_Workspace_Service { public static function available(): bool { return $GLOBALS['bricks']; } public static function allowed(): bool { return $GLOBALS['owner']; } }
}
namespace DSA\Onboarding {
	class Design_Context_Profile_Service {
		public const OPTION_PROFILE = 'profile_test'; public const SERVICE_CATEGORY_PLAN_META = '_plan_test';
		public function service_post_types(): array { return [ 'service_test'=>true ]; }
		public function service_taxonomies( $type ): array { return []; }
		public function service_meta_fields( $type ): array { return []; }
	}
}
namespace DSA\Backup {
	// Coverage projection only; the real adapter and native writer are tested separately.
	final class Kiwe_Configuration_Snapshot_Service {
		public const SCHEMA='kiwe.configuration-choices.v1';public const GROUP='Kiwe app choices';
		public static function allowed():bool{return \DSA\Access\Backup_Workspace_Service::allowed()&&current_user_can('manage_options');}
		public static function available():bool{return true;}
		public static function groups():array{return self::allowed()?[self::GROUP=>['kiwe.configuration.choices']]:[];}
		public static function descriptions():array{return [self::GROUP=>'Native non-secret app choices.'];}
	}
}
namespace {
	require __DIR__ . '/../../wp-content/mu-plugins/dsa/includes/Backup/Bricks_Design_Snapshot_Service.php';
	require __DIR__ . '/../../wp-content/mu-plugins/dsa/includes/Backup/Bricks_Font_Archive_Service.php';
	require __DIR__ . '/../../wp-content/mu-plugins/dsa/includes/Backup/Configuration_Snapshot_Service.php';
	require __DIR__ . '/../../wp-content/mu-plugins/dsa/includes/Backup/Content_Archive_Service.php';
	require_once __DIR__.'/../../wp-content/mu-plugins/dsa/includes/Backup/Archive_Error.php';
require_once __DIR__.'/../../wp-content/mu-plugins/dsa/includes/Backup/Archive_Job_Files.php';
require_once __DIR__.'/../../wp-content/mu-plugins/dsa/includes/Backup/Media_Archive_Service.php';
require_once __DIR__.'/../../wp-content/mu-plugins/dsa/includes/Backup/Media_Archive_Job_Service.php';
require_once __DIR__.'/../../wp-content/mu-plugins/dsa/includes/Access/Media_Archive_App_Service.php';
require_once __DIR__.'/../../wp-content/mu-plugins/dsa/includes/Access/Product_Attribute_Workspace_Service.php';
require_once __DIR__.'/../../wp-content/mu-plugins/dsa/includes/Backup/Product_Attribute_Snapshot_Service.php';
require_once __DIR__.'/../../wp-content/mu-plugins/dsa/includes/Backup/Portable_Store_Recovery.php';
require __DIR__ . '/../../wp-content/mu-plugins/dsa/includes/Backup/Unit_Catalog_Service.php';
	use DSA\Backup\Unit_Catalog_Service as Catalog;
	use DSA\Backup\Content_Archive_Service as Archives;
	$passed = false; $error = '';
	$indexed = static fn( array $result ): array => array_column( $result['units'], null, 'id' );
	$declaration = static fn( string $id, array $depends = [] ): array => [ 'schema'=>Catalog::DECLARATION_SCHEMA,'id'=>$id,'label'=>'Synthetic expansion','owner'=>'Synthetic module','version'=>'synthetic.expansion.v1','description'=>'Additional module-owned records.','dependsOn'=>$depends ];
	try {
		$base = Catalog::snapshot(); $units = $indexed( $base );
		expect( $base['schema'] === Catalog::SCHEMA && count( $base['contentUnits'] ) === 5 && ! $base['issues'], 'versioned native catalog has one entrance per supported collection' );
		foreach ( $base['contentUnits'] as $unit ) expect( $unit['canExport'] === Archives::can_export_collection( $unit['scope'] ) && $unit['canImport'] === Archives::can_import( $unit['singleScope'] ), $unit['id'] . ' delegates native permissions' );
		expect( $units['woocommerce.settings']['status'] === ( function_exists( 'WC' ) ? 'partial' : 'not_connected' ) && $units['woocommerce.products']['canCapture'] === function_exists( 'WC' ), 'optional Woo presence matches native services' );
		expect( $units['woocommerce.operations']['canCapture'] === false && $units['woocommerce.operations']['canRestore'] === false, 'product archives never claim live store recovery' );
		expect( $units['kiwe.configuration']['canCapture'] && $units['kiwe.configuration']['canRestore'] && $units['kiwe.configuration']['restorePolicy']==='reviewed_settings' && count($units['kiwe.configuration']['excludes'])===2, 'typed Kiwe configuration declares its reviewed authority and exclusions' );
		$caps['manage_options']=false;$without_settings=$indexed(Catalog::snapshot());expect(!$without_settings['kiwe.configuration']['canCapture']&&!$without_settings['kiwe.configuration']['canRestore'],'backup ownership never grants technical Settings authority');unset($caps['manage_options']);
		expect( $units['wordpress.accounts']['status']==='pending' && !$units['wordpress.accounts']['canRestore'] && $units['kiwe.operations']['status']==='pending' && !$units['kiwe.operations']['canRestore'], 'app-choice recovery does not claim account consent or campaign-history protection' );
		expect( count( $units['woocommerce.products']['excludes'] ) >= 2 && str_contains( implode( ' ', $units['woocommerce.products']['excludes'] ), 'Shipping-class definitions' ) && $units['wordpress.media']['status'] === 'partial' && count( $units['wordpress.media']['excludes'] ) >= 3, 'bounded media, shipping-class and transaction exclusions remain explicit' );
		expect( $units['wordpress.posts']['version'] === Archives::SCHEMA && $units['wordpress.settings']['version'] === DSA\Backup\Configuration_Snapshot_Service::SCHEMA, 'accepted archive schema identities are preserved' );
		expect( $units['wordpress.posts']['restorePolicy'] === 'new_drafts' && $units['wordpress.settings']['restorePolicy'] === 'reviewed_settings', 'restore policies retain native differences' );
		$caps['edit_post'] = false; $caps['create_post'] = false; $owner = false;
		$denied = $indexed( Catalog::snapshot() );
		expect( ! $denied['wordpress.posts']['canCapture'] && ! $denied['wordpress.posts']['canRestore'] && ! $denied['wordpress.settings']['canCapture'], 'changed native and owner permissions remove actions on next projection' );
		$caps = []; $owner = true; $source = 'unconnected_private_source';
		expect( $indexed( Catalog::snapshot() )['kiwe.services']['status'] === 'not_connected' && ! $indexed( Catalog::snapshot() )['kiwe.services']['canRestore'], 'unknown Service source cannot invent an archive unit' ); $source = 'service_test';
		$bricks = false; expect( $indexed( Catalog::snapshot() )['bricks.templates']['status'] === 'not_connected' && ! $indexed( Catalog::snapshot() )['bricks.templates']['canCapture'], 'disabled Bricks removes capture' ); $bricks = true;
		$extensions = [ $declaration( 'synthetic.future', [ 'wordpress.posts' ] ) ];
		$future = Catalog::snapshot(); $unit = $indexed( $future )['synthetic.future'];
		expect( $unit['status'] === 'pending' && ! $unit['canCapture'] && ! $unit['canRestore'] && $unit['restorePolicy'] === 'unavailable', 'valid expansion remains a declared gap until native adapter acceptance' );
		expect( count( $future['contentUnits'] ) === 5, 'declaration cannot create a client export or import entrance' );
		foreach ( [ 'canCapture'=>true,'restorePolicy'=>'overwrite','callback'=>'delete_option','optionNames'=>['private_key'],'captureVersion'=>'any' ] as $key=>$value ) {
			$extensions = [ $declaration( 'synthetic.unsafe' ) + [ $key=>$value ] ]; $bad = Catalog::snapshot();
			expect( ! isset( $indexed( $bad )['synthetic.unsafe'] ) && in_array( 'invalid_declaration', $bad['issues'], true ), 'unknown authority field denied: ' . $key );
		}
		$extensions = [ $declaration( 'wordpress.posts' ) ];
		expect( $indexed( Catalog::snapshot() )['wordpress.posts'] === $units['wordpress.posts'], 'extensions cannot replace a native unit' );
		$extensions = [ $declaration( 'synthetic.dupe' ),$declaration( 'synthetic.dupe' ),$declaration( 'synthetic.child', [ 'synthetic.dupe' ] ) ];
		expect( ! isset( $indexed( Catalog::snapshot() )['synthetic.dupe'] ) && ! isset( $indexed( Catalog::snapshot() )['synthetic.child'] ), 'ambiguous duplicate and its dependant are excluded' );
		$extensions = [ $declaration( 'synthetic.orphan', [ 'missing.native' ] ) ];
		expect( ! isset( $indexed( Catalog::snapshot() )['synthetic.orphan'] ), 'unknown dependency is excluded' );
		$extensions = [ $declaration( 'synthetic.alpha', [ 'synthetic.beta' ] ),$declaration( 'synthetic.beta', [ 'synthetic.alpha' ] ),$declaration( 'synthetic.child', [ 'synthetic.alpha' ] ) ];
		expect( count( Catalog::snapshot()['units'] ) === count( $base['units'] ) && in_array( 'unresolved_dependency', Catalog::snapshot()['issues'], true ), 'cycles and their dependants cannot claim recovery' );
		$extensions = [ $declaration( 'synthetic.chain', [ 'wordpress.media' ] ) ];
		expect( isset( $indexed( Catalog::snapshot() )['synthetic.chain'] ) && ! $indexed( Catalog::snapshot() )['synthetic.chain']['canRestore'], 'pending native dependency does not grant readiness' );
		foreach ( [ 'id'=>'<script>','version'=>'','label'=>['not text'],'description'=>"bad\ntext",'dependsOn'=>['wordpress.posts','wordpress.posts'],'schema'=>'unknown' ] as $key=>$value ) {
			$badDeclaration = $declaration( 'synthetic.invalid' ); $badDeclaration[$key] = $value; $extensions = [ $badDeclaration ];
			expect( ! isset( $indexed( Catalog::snapshot() )['synthetic.invalid'] ), 'malformed declaration excluded: ' . $key );
		}
		$extensions = array_fill( 0, Catalog::MAX_EXTENSIONS + 1, $declaration( 'synthetic.limit' ) );
		expect( count( Catalog::snapshot()['units'] ) === count( $base['units'] ) && Catalog::snapshot()['issues'] === [ 'invalid_declaration_list' ], 'bounded declarations preserve native coverage when oversized' );
		$extensions = [ 'private-shape' ]; $hookThrows = true; $failedHook = Catalog::snapshot();
		expect( count( $failedHook['contentUnits'] ) === 5 && $failedHook['issues'] === [ 'declaration_hook_failed' ] && ! str_contains( json_encode( $failedHook ), 'private hook error' ), 'hook failure does not disable native backup actions or leak its error' );
		$hookThrows = false; $extensions = []; $signedIn = false; $out = $indexed( Catalog::snapshot() );
		expect( ! $out['wordpress.posts']['canCapture'] && ! $out['wordpress.posts']['canRestore'] && ! $out['wordpress.settings']['canCapture'], 'signed-out actions remain unavailable' );
		$signedIn = true; $extensions = [];
		for ( $i = 0; $i < Catalog::MAX_EXTENSIONS; $i++ ) {
			$dependencies = [];
			for ( $j = $i + 1; $j < min( Catalog::MAX_EXTENSIONS, $i + 13 ); $j++ ) $dependencies[] = 'synthetic.node' . $j;
			$extensions[] = $declaration( 'synthetic.node' . $i, $dependencies );
		}
		$started = hrtime( true ); $dense = Catalog::snapshot(); $elapsedMs = ( hrtime( true ) - $started ) / 1e6;
		expect( count( $dense['units'] ) === count( $base['units'] ) + Catalog::MAX_EXTENSIONS && ! $dense['issues'] && $dense['contentUnits'] === $base['contentUnits'], 'overlapping maximum-size dependency graph keeps native actions and every valid pending module' );
		expect( $elapsedMs < 1000, 'shared dependencies finish within the local one-second budget' );
		$extensions = array_reverse( $extensions );
		expect( count( Catalog::snapshot()['units'] ) === count( $dense['units'] ), 'shared dependency resolution is independent of declaration order' );
		$extensions[0]['dependsOn'] = [ 'missing.native' ];
		expect( count( Catalog::snapshot()['units'] ) === count( $base['units'] ), 'rejected shared dependency excludes every dependant without reusing valid state' );
		$extensions[0]['dependsOn'] = [ 'synthetic.node0' ];
		expect( count( Catalog::snapshot()['units'] ) === count( $base['units'] ), 'maximum-size shared cycle is excluded on a fresh projection' );
		$passed = true;
	} catch ( \Throwable $failure ) { $error = $failure->getMessage(); }
	echo json_encode( [ 'passed'=>$passed,'cleanup'=>true,'mode'=>function_exists( 'WC' ) ? 'woo' : 'non-woo','checks'=>$checks,'dependencyMilliseconds'=>$elapsedMs ?? null,'error'=>$error ], JSON_UNESCAPED_SLASHES ) . PHP_EOL;
	exit( $passed ? 0 : 1 );
}
