<?php
namespace DSA\Backup;

use DSA\Access\Backup_Workspace_Service;
use DSA\Access\Template_Workspace_Service;

if ( ! defined( 'ABSPATH' ) ) exit;

/** Coverage discovery only. Native services remain the only capture/restore authorities. */
final class Unit_Catalog_Service {
	public const SCHEMA = 'kiwe.backup-coverage.v1';
	public const DECLARATION_SCHEMA = 'kiwe.backup-unit-declaration.v1';
	public const MAX_EXTENSIONS = 24;

	/** One source for the supported collection entrances; never supplied by request data. */
	public static function content_definitions(): array {
		return [
			'wordpress.posts'=>[ 'scope'=>'posts','singleScope'=>'post','label'=>'Posts','owner'=>'WordPress','description'=>'Article text, SEO, categories, tags and existing local image references.' ],
			'wordpress.pages'=>[ 'scope'=>'pages','singleScope'=>'page','label'=>'Pages','owner'=>'WordPress','description'=>'Page text, SEO, hierarchy, saved layouts and existing local image references.' ],
			'woocommerce.products'=>[ 'scope'=>'products','singleScope'=>'product','label'=>'Products','owner'=>'WooCommerce','description'=>'Supported product records, variations, terms, prices and existing local image references.' ],
			'kiwe.services'=>[ 'scope'=>'services','singleScope'=>'service','label'=>'Services','owner'=>'Connected Service source','description'=>'Connected Service text, SEO, hierarchy, terms and registered public scalar fields.' ],
			'bricks.templates'=>[ 'scope'=>'templates','singleScope'=>'template','label'=>'Bricks templates','owner'=>'Bricks','description'=>'Reusable layouts and native template types. Imports create drafts with display conditions removed.' ],
		];
	}

	private static function pending( string $id, string $label, string $owner, string $description, array $dependencies = [], bool $available = true ): array {
		return [ 'id'=>$id,'label'=>$label,'owner'=>$owner,'version'=>'','status'=>$available ? 'pending' : 'not_connected','description'=>$description,'dependsOn'=>$dependencies,'excludes'=>[],'canCapture'=>false,'canRestore'=>false,'restorePolicy'=>'unavailable' ];
	}

	public static function snapshot(): array {
		$units = []; $content = []; $allowed = Backup_Workspace_Service::allowed();
		if(class_exists(Search_Performance_Snapshot_Service::class))$units['kiwe.search']=['id'=>'kiwe.search','label'=>'Search reporting','owner'=>'Website-owned Search Console','version'=>Configuration_Snapshot_Service::SCHEMA,'status'=>'partial','description'=>'Encrypted same-site search-type choices. Restore pauses synchronization and preserves current provider authority.','dependsOn'=>[],'excludes'=>['OAuth credentials, property authorization and active jobs are excluded from selective settings snapshots.','Whole-site archives retain the original reporting tables privately; reactivation and mapped historical recovery belong to the shared handover/recovery adapter.'],'canCapture'=>Search_Performance_Snapshot_Service::allowed(),'canRestore'=>Search_Performance_Snapshot_Service::allowed(),'restorePolicy'=>'reviewed_settings'];
		$settings = Configuration_Snapshot_Service::option_groups();
		foreach ( [ 'WordPress'=>'wordpress.settings','Kiwe'=>'kiwe.settings','WooCommerce'=>'woocommerce.settings' ] as $group=>$id ) {
			$units[$id] = [ 'id'=>$id,'label'=>$group . ' settings','owner'=>$group,'version'=>Configuration_Snapshot_Service::SCHEMA,'status'=>isset( $settings[$group] ) ? 'partial' : 'not_connected','description'=>[ 'WordPress'=>'Website name, presentation, homepage choices and search visibility.','Kiwe'=>'Design Context, website identity, contact details and SEO/AI enablement choices.','WooCommerce'=>'Currency, units and the store address.' ][$group],'dependsOn'=>[ 'wordpress.pages','wordpress.media' ],'excludes'=>[ 'Other settings, credentials and operational records.' ],'canCapture'=>$allowed && isset( $settings[$group] ),'canRestore'=>$allowed && isset( $settings[$group] ),'restorePolicy'=>'reviewed_settings' ];
		}
		foreach ( self::content_definitions() as $id=>$definition ) {
			$type = Content_Archive_Service::type( $definition['scope'] );
			$connected = (bool) ( $type && get_post_type_object( $type ) );
			if ( 'woocommerce.products' === $id ) $connected = $connected && function_exists( 'wc_get_product' );
			if ( 'bricks.templates' === $id ) $connected = $connected && Template_Workspace_Service::available();
			$capture = Content_Archive_Service::can_export_collection( $definition['scope'] );
			$restore = Content_Archive_Service::can_import( $definition['singleScope'] );
			$excludes = [ 'Image files, saved revisions and unregistered plugin fields.' ];
			$dependencies = [ 'wordpress.media' ];
			if ( 'woocommerce.products' === $id ) $excludes[] = 'Orders, refunds, customer records, live payment state and unsupported product extensions.';
			if ( 'woocommerce.products' === $id ) $excludes[] = 'Shipping-class definitions, their delivery charges and tax configuration require the separate private whole-site Store recovery adapter.';
			if ( in_array( $id, [ 'wordpress.pages','bricks.templates' ], true ) ) { $dependencies[] = 'bricks.globals'; $excludes[] = 'Bricks global styles, components, fonts and external assets.'; }
			$units[$id] = [ 'id'=>$id,'label'=>$definition['label'],'owner'=>$definition['owner'],'version'=>Content_Archive_Service::SCHEMA,'status'=>$connected ? 'partial' : 'not_connected','description'=>$definition['description'],'dependsOn'=>$dependencies,'excludes'=>$excludes,'canCapture'=>$capture,'canRestore'=>$restore,'restorePolicy'=>'new_drafts' ];
			$content[] = [ 'id'=>$id,...$definition,'canExport'=>$capture,'canImport'=>$restore ];
		}
		foreach ( [
			[ 'id'=>'wordpress.media','label'=>'Media files','owner'=>'WordPress','version'=>Media_Archive_Job_Service::SCHEMA,'status'=>'partial','description'=>'Registered local files, generated sizes and attachment details restored as new copies.','dependsOn'=>[],'excludes'=>[ 'Offloaded or remote files, unsafe file types, embedded artwork metadata and unregistered plugin files.','New copies reconnect reviewed content drafts or global design settings through an explicitly selected completed recovery; existing published content records are not rewritten.','At most 200 attachments, 2,000 registered files, 64 MB raw and 128 MB encrypted per set.' ],'canCapture'=>Media_Archive_Service::can_use() && class_exists( '\ZipArchive' ),'canRestore'=>Media_Archive_Service::can_use() && class_exists( '\ZipArchive' ),'restorePolicy'=>'new_media_copies' ],
			[ 'id'=>'bricks.globals','label'=>'Bricks design settings','owner'=>'Bricks','version'=>Configuration_Snapshot_Service::SCHEMA,'status'=>Bricks_Design_Snapshot_Service::available() ? 'partial' : 'not_connected','description'=>'Native classes, variables, palettes, theme styles, components, global elements, breakpoints, pseudo selectors and Style Manager. Choose completed same-site recoveries to reconnect typed media, font and template references.','dependsOn'=>[ 'wordpress.media','bricks.fonts' ],'excludes'=>[ 'Global builder/security settings, executable code, global queries, external/SVG transport and whole-site recovery.','The Bricks design tab combines these settings, eligible templates and referenced local images/fonts into one same-site package. Recover copies first, then review live settings. Template copies require native publication; every dependency and selected job must remain unchanged.','Single-site, at most 10,000 entries per design list and 16 MB encrypted; this does not restore a complete Bricks installation.' ],'canCapture'=>Bricks_Design_Snapshot_Service::allowed(),'canRestore'=>Bricks_Design_Snapshot_Service::allowed(),'restorePolicy'=>'reviewed_settings' ],
			[ 'id'=>'bricks.fonts','label'=>'Custom fonts','owner'=>'Bricks','version'=>Media_Archive_Job_Service::FONT_SCHEMA,'status'=>Bricks_Font_Archive_Service::available() ? 'partial' : 'not_connected','description'=>'Published native font families, legacy and subset face registrations, and local registered font files. Recovery creates copies and an explicit design/content font mapping.','dependsOn'=>['wordpress.media'],'excludes'=>['Same-site only, up to 100 published families and 200 attachments within the bounded 64 MB media set.','External fonts, unsupported variable faces, whole-site and cross-site recovery remain separate.'],'canCapture'=>Bricks_Font_Archive_Service::allowed(),'canRestore'=>Bricks_Font_Archive_Service::allowed(),'restorePolicy'=>'new_media_copies' ],
			[ 'id'=>'woocommerce.operations','label'=>'Store operations','owner'=>'WooCommerce','version'=>Portable_Store_Recovery::SCHEMA,'status'=>function_exists( 'WC' ) ? 'partial' : 'not_connected','description'=>'The private encrypted whole-site recovery tool preserves supported native Store facts into an empty Store destination with all commerce actions paused. Product archives do not protect these records.','dependsOn'=>[ 'woocommerce.products' ],'excludes'=>[ 'No selective Store restore, transaction merge, resumed sales, payment/provider enrollment or consent transfer.','Reviewed native Woo 11.0.1 and 11.1.0 schemas and core shipping methods only; a qualified isolated destination and owner review are required.' ],'canCapture'=>false,'canRestore'=>false,'restorePolicy'=>'unavailable' ],
			self::pending( 'wordpress.accounts','Accounts and security','WordPress / Kiwe','Users, roles, verification, consent, sessions and security history.' ),
			self::pending( 'kiwe.operations','Kiwe activity and configuration','Kiwe','Campaigns, audiences, tag folders, navigation configuration, analytics, operational history and guarded Service conversion recovery.' ),
			self::pending( 'wordpress.site','Whole-site recovery','Hosting provider','Website files, database, plugins, themes and cross-module recovery.', [ 'wordpress.media','wordpress.accounts','kiwe.operations' ] ),
		] as $unit ) $units[$unit['id']] = $unit;
		if ( class_exists( Organization_Snapshot_Service::class ) ) {
			foreach ( [ 'kiwe.tags'=>[ 'Tags',Organization_Snapshot_Service::TAGS ],'kiwe.audiences'=>[ 'User groups',Organization_Snapshot_Service::AUDIENCES ],'kiwe.navigation'=>[ 'Navigation',Organization_Snapshot_Service::NAVIGATION ] ] as $id=>[ $label,$name ] ) {
				$units[$id] = [ 'id'=>$id,'label'=>$label,'owner'=>'Native WordPress / Kiwe','version'=>Configuration_Snapshot_Service::SCHEMA,'status'=>'partial','description'=>Organization_Snapshot_Service::descriptions()[$label],'dependsOn'=>Organization_Snapshot_Service::AUDIENCES === $name ? [ 'wordpress.accounts' ] : [],'excludes'=>[ 'Existing same-site records only; at most 1,000 tag assignments or account memberships. Newer groups and accounts are preserved.','Accounts, roles, credentials, consent, verification, active campaign jobs, sends, menu contents and theme placements are not restored.' ],'canCapture'=>Organization_Snapshot_Service::allowed( $name ),'canRestore'=>Organization_Snapshot_Service::allowed( $name ),'restorePolicy'=>'reviewed_settings' ];
			}
		}

		if ( class_exists( Account_Profile_Snapshot_Service::class ) ) $units['wordpress.profiles'] = [
			'id'=>'wordpress.profiles','label'=>'Account profiles','owner'=>'WordPress / Users workspace','version'=>Account_Profile_Snapshot_Service::SCHEMA,'status'=>'partial',
			'description'=>Account_Profile_Snapshot_Service::descriptions()[Account_Profile_Snapshot_Service::GROUP],'dependsOn'=>[ 'wordpress.accounts' ],
			'excludes'=>[ 'Existing editable same-site accounts only; at most 1,000 people. Newer people are preserved.', 'Login identity, credentials, roles, verification, consent, sessions, security history, portraits and store addresses are not restored.' ],
			'canCapture'=>Account_Profile_Snapshot_Service::allowed(),'canRestore'=>Account_Profile_Snapshot_Service::allowed(),'restorePolicy'=>'reviewed_settings',
		];
		if ( class_exists( Kiwe_Configuration_Snapshot_Service::class ) ) $units['kiwe.configuration'] = [
			'id'=>'kiwe.configuration','label'=>'Kiwe app choices','owner'=>'Kiwe / native Settings','version'=>Kiwe_Configuration_Snapshot_Service::SCHEMA,'status'=>'partial',
			'description'=>Kiwe_Configuration_Snapshot_Service::descriptions()[Kiwe_Configuration_Snapshot_Service::GROUP],'dependsOn'=>[],
			'excludes'=>[ 'Only the four explicitly listed non-secret choice sections are recovered on this same site; other Kiwe settings are not included.', 'Credentials, security policy, roles, visitor consent, verification, browser subscriptions, campaigns, delivery queues and operational history stay current.' ],
			'canCapture'=>Kiwe_Configuration_Snapshot_Service::allowed() && Kiwe_Configuration_Snapshot_Service::available(),'canRestore'=>Kiwe_Configuration_Snapshot_Service::allowed() && Kiwe_Configuration_Snapshot_Service::available(),'restorePolicy'=>'reviewed_settings',
		];

		if ( class_exists( SEO_State_Snapshot_Service::class ) ) $units['kiwe.seo-defaults'] = [
			'id'=>'kiwe.seo-defaults','label'=>'SEO defaults','owner'=>'Kiwe SEO','version'=>Configuration_Snapshot_Service::SCHEMA,'status'=>'partial',
			'description'=>'Native title, sharing, robots and sitemap defaults through the shared encrypted settings snapshot.',
			'dependsOn'=>[],'excludes'=>[ 'Same-site reviewed settings only; content schema graphs travel in content archives.', 'Provider credentials, source plugin settings, redirect rules, search history, migration jobs and SEO/AI permission choices are not changed by this settings unit.' ],
			'canCapture'=>SEO_State_Snapshot_Service::allowed(),'canRestore'=>SEO_State_Snapshot_Service::allowed(),'restorePolicy'=>'reviewed_settings',
		];

		$attributeConnected = \DSA\Access\Product_Attribute_Workspace_Service::available();
		$attributeAllowed = Product_Attribute_Snapshot_Service::allowed();
		$units['woocommerce.attribute-labels'] = [
			'id'=>'woocommerce.attribute-labels','label'=>'Product attribute labels','owner'=>'WooCommerce and WordPress','version'=>Product_Attribute_Snapshot_Service::SCHEMA,'status'=>$attributeConnected ? 'partial' : 'not_connected',
			'description'=>Product_Attribute_Snapshot_Service::descriptions()[Product_Attribute_Snapshot_Service::GROUP],'dependsOn'=>[],
			'excludes'=>['Same-site existing labels only; native IDs, addresses, types, visibility, ordering, metadata, product assignments and variation configuration stay current.','Missing definitions/values, new-site mapping and complete Store state require the separate private whole-site Store recovery adapter.'],
			'canCapture'=>$attributeAllowed,'canRestore'=>$attributeAllowed,'restorePolicy'=>'reviewed_settings',
		];
		// An installed module may declare a gap. A declaration cannot invent a writer or grant access.
		$issues = []; $extensions = [];
		try { $input = apply_filters( 'kiwe_backup_unit_declarations', [] ); }
		catch ( \Throwable $error ) { $input = []; $issues[] = 'declaration_hook_failed'; }
		if ( ! is_array( $input ) || ! array_is_list( $input ) || count( $input ) > self::MAX_EXTENSIONS ) $issues[] = 'invalid_declaration_list';
		else {
			$duplicates = [];
			foreach ( $input as $declaration ) {
				if ( ! self::valid_declaration( $declaration ) ) { $issues[] = 'invalid_declaration'; continue; }
				$id = $declaration['id'];
				if ( isset( $units[$id] ) || isset( $extensions[$id] ) || isset( $duplicates[$id] ) ) { unset( $extensions[$id] ); $duplicates[$id] = true; $issues[] = 'duplicate_unit'; continue; }
				$extensions[$id] = [ 'id'=>$id,'label'=>$declaration['label'],'owner'=>$declaration['owner'],'version'=>$declaration['version'],'status'=>'pending','description'=>$declaration['description'],'dependsOn'=>$declaration['dependsOn'],'excludes'=>[],'canCapture'=>false,'canRestore'=>false,'restorePolicy'=>'unavailable' ];
			}
			// Drop missing/cyclic extension contracts and their dependants, preserving native entrances.
			$graph = $units + $extensions; $states = [];
			foreach ( array_keys( $extensions ) as $id ) if ( ! self::resolved( $id, $graph, $states ) ) { unset( $extensions[$id] ); $issues[] = 'unresolved_dependency'; }
		}
		return [ 'schema'=>self::SCHEMA,'units'=>array_values( $units + $extensions ),'contentUnits'=>$content,'issues'=>array_values( array_unique( $issues ) ),'destinations'=>[
			[ 'id'=>'local','label'=>'Local encrypted files','status'=>'partial','description'=>'Download supported settings and content to your computer. Same-site reviews precede any restore.' ],
			[ 'id'=>'drive','label'=>'Google Drive','status'=>'pending','description'=>'Website-owned connection, verified transfers and retention are not implemented yet.' ],
			[ 'id'=>'host','label'=>'Hosting recovery','status'=>'external','description'=>'Request a backup from your Super Admin. Provider copies, completion and cooldown need provider confirmation.' ],
		] ];
	}

	private static function valid_declaration( $value ): bool {
		$keys = [ 'schema','id','label','owner','version','description','dependsOn' ];
		if ( ! is_array( $value ) || array_diff( array_keys( $value ), $keys ) || count( $value ) !== count( $keys ) || ( $value['schema'] ?? null ) !== self::DECLARATION_SCHEMA ) return false;
		if ( ! is_string( $value['id'] ) || ! preg_match( '/^[a-z][a-z0-9_-]{1,47}\.[a-z][a-z0-9_-]{1,47}$/D', $value['id'] ) || ! is_string( $value['version'] ) || ! preg_match( '/^[a-z][a-z0-9._-]{2,90}\.v[1-9][0-9]*$/D', $value['version'] ) ) return false;
		foreach ( [ 'label'=>80,'owner'=>80,'description'=>600 ] as $key=>$limit ) if ( ! is_string( $value[$key] ) || ! trim( $value[$key] ) || strlen( $value[$key] ) > $limit || sanitize_text_field( $value[$key] ) !== $value[$key] ) return false;
		if ( ! is_array( $value['dependsOn'] ) || ! array_is_list( $value['dependsOn'] ) || count( $value['dependsOn'] ) > 12 ) return false;
		foreach ( $value['dependsOn'] as $id ) if ( ! is_string( $id ) || ! preg_match( '/^[a-z][a-z0-9_-]{1,47}\.[a-z][a-z0-9_-]{1,47}$/D', $id ) ) return false;
		return count( array_unique( $value['dependsOn'] ) ) === count( $value['dependsOn'] );
	}

	/** Request-local graph states: 1 visiting, 2 resolved, 3 rejected. Shared paths are visited once. */
	private static function resolved( string $id, array $units, array &$states ): bool {
		if ( isset( $states[$id] ) ) return $states[$id] === 2;
		if ( ! isset( $units[$id] ) ) { $states[$id] = 3; return false; }
		$states[$id] = 1;
		foreach ( $units[$id]['dependsOn'] as $dependency ) if ( ! self::resolved( $dependency, $units, $states ) ) { $states[$id] = 3; return false; }
		$states[$id] = 2;
		return true;
	}
}
