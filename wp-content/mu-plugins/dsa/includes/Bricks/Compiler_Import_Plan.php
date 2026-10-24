<?php
namespace DSA\Bricks;
use DSA\Design\Seam_Token_Service as Tokens;
if ( ! defined( 'ABSPATH' ) ) exit;

/** Target-specific authoring decisions. Read-only until the separate reviewed job is confirmed. */
final class Compiler_Import_Plan {
	public static function allowed(): bool {
		return ! is_multisite() && Framework_Style_Service::allowed() && \DSA\Access\Template_Workspace_Service::allowed() && \DSA\Access\Template_Workspace_Service::available() && current_user_can( 'upload_files' ) && current_user_can( 'create_kiwe_templates' ) && current_user_can( 'delete_kiwe_templates' ) && \Bricks\Builder_Permissions::user_has_permission( 'import_export_templates' );
	}
	public static function prepare( array $inspection,\DSA\Settings $settings ): array {
		if ( ! self::allowed() ) throw new Compiler_Package_Error( 'Your account needs native template import, Media, style editing and template recovery permissions.' );
		$profile = $inspection['documents'][$inspection['profileFile']];
		if ( ( $profile['schema'] ?? '' ) !== 'kiwe.framework-profile.v1' || ! is_array( $profile['settings']['tokens'] ?? null ) ) throw new Compiler_Package_Error( 'The package needs a supported Kiwe Framework Profile.' );
		$input = $profile['settings']['tokens'];
		self::guard_values( $input );
		$enhancements = new \DSA\Onboarding\Design_Context_Enhancement_Service();
		$owner = $enhancements->validate_framework_tokens( $input );
		if ( is_wp_error( $owner ) ) throw new Compiler_Package_Error( 'This design conflicts with an owner-selected brand colour. Resolve it in Design Context before importing.' );
		$before_tokens = $settings->get( 'tokens',[] );
		$defaults = $settings->defaults()['tokens'] ?? [];
		// This sanitizer owns a slashed form boundary; preserve source CSS backslashes.
		$next = Tokens::sanitize_framework_profile_tokens( wp_slash( $enhancements->apply_owner_colors_to_framework_tokens( $input ) ),$before_tokens,$defaults );
		$project = Tokens::project_extensions_for_bricks( $next['project'] ?? [] );
		$source_project = $input['project'] ?? [];
		foreach ( [ 'classes','variables' ] as $kind ) {
			if ( count( $project[$kind] ?? [] ) !== count( $source_project[$kind] ?? [] ) ) throw new Compiler_Package_Error( 'The profile contains unsupported styles. Recompile rather than silently dropping them.' );
			foreach ( $source_project[$kind] ?? [] as $index=>$item ) {
				$native = $project[$kind][$index];
				if ( $native['name'] !== ltrim( (string) ( $item['name'] ?? '' ),'-' ) || ( $kind === 'variables' ? $native['value'] !== ( $item['value'] ?? '' ) : $native['id'] !== ( $item['id'] ?? '' ) || ( $native['settings'] ?? [] ) !== ( $item['settings'] ?? [] ) ) ) throw new Compiler_Package_Error( 'Native style validation would change a supplied design value. Recompile or review that style first.' );
			}
		}
		$style = $next['bricks_theme_style'] ?? [];
		$universal = Tokens::export_for_bricks( Tokens::tokens_with_overrides( $next['overrides'] ?? [] ),[ 'id'=>$style['id'] ?? 'kiwe-global-design','label'=>$style['label'] ?? 'Kiwe Framework' ] );
		$native_plan = Framework_Style_Service::plan( Framework_Style_Service::snapshot(),$universal,$project,! empty( $style['enabled'] ) );
		$class_ids = [];
		foreach ( $native_plan['after']['classes']['value'] as $class ) if ( is_array( $class ) && isset( $class['id'] ) ) $class_ids[$class['id']] = true;
		$warnings = $inspection['warnings']; $blockers = [];
		foreach ( $inspection['assets'] as $asset ) if ( $asset['file'] && ( $asset['policy'] ?? '' ) !== 'import' ) {
			if ( ( $asset['mime'] ?? '' ) === 'image/svg+xml' && ( $asset['policy'] ?? '' ) === 'review' ) $warnings[] = 'Embedded SVG stays in its native control after Bricks sanitization review; no upload permission is inferred.';
			else $blockers[] = 'A supplied resource needs a supported native adapter: ' . $asset['file'];
		}
		foreach ( $inspection['issues'] ?? [] as $issue ) if ( ( $issue['code'] ?? '' ) !== 'asset-native-review' ) $blockers[] = substr( (string) ( $issue['message'] ?? 'An unresolved dependency needs review.' ),0,300 );
		$types = \DSA\Access\Template_Workspace_Service::types(); $template_checks = [];
		foreach ( $inspection['templateFiles'] as $file ) {
			$document = $inspection['documents'][$file];
			if ( ! isset( $types[$document['templateType'] ?? ''] ) || ! is_string( $document['title'] ?? null ) || '' === trim( $document['title'] ) || strlen( $document['title'] ) > 200 ) throw new Compiler_Package_Error( 'A template type or title is unsupported by this Bricks installation.' );
			$template_checks[$file] = self::validate_document( $document );
			foreach ( [ 'content','header','footer' ] as $area ) foreach ( $document[$area] ?? [] as $element ) foreach ( $element['settings']['_cssGlobalClasses'] ?? [] as $id ) if ( ! is_string( $id ) || ! isset( $class_ids[$id] ) ) throw new Compiler_Package_Error( 'A template refers to a native class that is not installed or supplied by this profile.' );
		}
		return [ 'tokensBefore'=>$before_tokens,'tokensAfter'=>$next,'styles'=>$native_plan,'warnings'=>array_values( array_unique( $warnings ) ),'blockers'=>array_values( array_unique( $blockers ) ),'templates'=>$template_checks,'expectedTokens'=>hash( 'sha256',serialize( $before_tokens ) ) ];
	}
	public static function validate_document( array $document ): array {
		$runtime = Source_Runtime_Bridge::dependency_error( $document ); if ( $runtime ) throw new Compiler_Package_Error( $runtime );
		if ( ! is_string( $document['version'] ?? null ) || ! preg_match( '/^2\.3(?:\.|$)/D',$document['version'] ) ) throw new Compiler_Package_Error( 'This import adapter requires a template for the verified Bricks 2.3 family.' );
		$elements = []; $ids = []; $needs_html = self::guard_values( $document );
		foreach ( [ 'content','header','footer' ] as $area ) if ( isset( $document[$area] ) ) {
			if ( ! is_array( $document[$area] ) || ! array_is_list( $document[$area] ) ) throw new Compiler_Package_Error( 'Native template content must be an element list.' );
			foreach ( $document[$area] as $element ) {
				$id = $element['id'] ?? null; $name = $element['name'] ?? null;
				if ( ! is_string( $id ) || ! preg_match( '/^[a-z0-9_-]{1,80}$/iD',$id ) || isset( $ids[$id] ) || ! is_string( $name ) || ! isset( \Bricks\Elements::$elements[$name] ) || $name === 'code' ) throw new Compiler_Package_Error( 'A template contains an unknown, duplicated or executable native element.' );
				$ids[$id] = $element; $elements[] = $element;
			}
		}
		if ( ! $elements || count( $elements ) > 12000 ) throw new Compiler_Package_Error( 'The template needs between one and 12,000 native elements.' );
		foreach ( $elements as $element ) {
			$parent = $element['parent'] ?? 0; $children = $element['children'] ?? [];
			if ( ( ! is_string( $parent ) && ! is_int( $parent ) ) || ( $parent && ( ! isset( $ids[$parent] ) || ! in_array( $element['id'],$ids[$parent]['children'] ?? [],true ) ) ) ) throw new Compiler_Package_Error( 'A native element has a missing or inconsistent parent.' );
			if ( ! is_array( $children ) || ! array_is_list( $children ) || count( array_unique( $children,SORT_REGULAR ) ) !== count( $children ) ) throw new Compiler_Package_Error( 'A native child list is invalid or duplicated.' );
			foreach ( $children as $child ) if ( ! is_string( $child ) || ! isset( $ids[$child] ) || ( $ids[$child]['parent'] ?? 0 ) !== $element['id'] ) throw new Compiler_Package_Error( 'A native element has a missing or inconsistent child.' );
		}
		$visiting = []; $done = [];
		$visit = static function( string $id,int $depth = 0 ) use ( &$visit,&$visiting,&$done,$ids ): void {
			if ( isset( $done[$id] ) ) return;
			if ( $depth > 100 || isset( $visiting[$id] ) ) throw new Compiler_Package_Error( 'The native element tree is circular or nested too deeply.' );
			$visiting[$id] = true; foreach ( $ids[$id]['children'] ?? [] as $child ) $visit( $child,$depth + 1 ); unset( $visiting[$id] ); $done[$id] = true;
		};
		foreach ( array_keys( $ids ) as $id ) $visit( $id );
		return [ 'title'=>trim( $document['title'] ),'type'=>$document['templateType'],'elements'=>count( $elements ),'sourceCode'=>$needs_html ];
	}
	private static function guard_values( array $document ): bool {
		$nodes = 0; $needs_html = false;
		$walk = static function( $value,int $depth = 0 ) use ( &$walk,&$nodes,&$needs_html ): void {
			if ( ++$nodes > 250000 || $depth > 50 ) throw new Compiler_Package_Error( 'The native document exceeds its authoring limit.' );
			if ( is_array( $value ) ) foreach ( $value as $key=>$item ) {
				if ( is_string( $key ) && preg_match( '/^(?:signature|_signature|executeCode|codeExecute|queryEditor|useQueryEditor|phpQueryEditor|_phpCode)$/iD',$key ) && $item ) throw new Compiler_Package_Error( 'Imported PHP/query code or signatures need a separate native code review.' );
				if ( is_string( $key ) && preg_match( '/customJs|_jsCustom/i',$key ) && $item ) $needs_html = true;
				$walk( $item,$depth + 1 );
			}
			elseif ( is_string( $value ) ) {
				if ( preg_match( '/<\?(?:php|=)|\{echo:/i',$value ) ) throw new Compiler_Package_Error( 'Imported PHP or echo tags need a separate native code review.' );
				if ( preg_match( '/<script\b|\bon[a-z]+\s*=|javascript\s*:/i',$value ) ) $needs_html = true;
			}
		};
		$walk( $document );
		if ( ! empty( $document['pageSettings']['customJs'] ) || ! empty( $document['pageSettings']['customJsHeader'] ) || ! empty( $document['pageSettings']['customJsFooter'] ) ) $needs_html = true;
		// Bricks' native non-Code element path uses WordPress HTML authority for JS.
		// Never require or grant PHP execution merely to import source JavaScript.
		if ( $needs_html && ! current_user_can( 'unfiltered_html' ) ) throw new Compiler_Package_Error( 'This design contains source JavaScript/HTML that requires native unfiltered HTML authoring permission.' );
		return $needs_html;
	}
	public static function validate_embedded_svg( string $bytes ): void {
		if ( strlen( $bytes ) > 262144 || ! current_user_can( 'unfiltered_html' ) || ! is_callable( [ '\Bricks\Svg','load_libraries' ] ) ) throw new Compiler_Package_Error( 'This embedded SVG needs native review by an authorized designer.' );
		\Bricks\Svg::load_libraries();
		$sanitizer = new \enshrined\svgSanitize\Sanitizer();
		$sanitizer->setAllowedTags( new \Bricks\Integrations\Svg_Sanitizer\Allowed_Tags() );
		$sanitizer->setAllowedAttrs( new \Bricks\Integrations\Svg_Sanitizer\Allowed_Attributes() );
		$sanitizer->removeRemoteReferences( true ); $clean = $sanitizer->sanitize( $bytes );
		$before = new \DOMDocument(); $after = new \DOMDocument(); $before->preserveWhiteSpace = false; $after->preserveWhiteSpace = false;
		$previous = libxml_use_internal_errors( true );
		try {
			$ok = is_string( $clean ) && ! preg_match( '/<!DOCTYPE|<!ENTITY/i',$bytes ) && $before->loadXML( $bytes,LIBXML_NONET ) && $after->loadXML( $clean,LIBXML_NONET ) && $before->documentElement->localName === 'svg' && $before->C14N() === $after->C14N();
			// Bricks' sanitizer is retained, but its remote-reference option is not a complete asset graph.
			// Only local fragment references are admitted inside an otherwise embedded vector.
			if ( $ok ) foreach ( $before->getElementsByTagName( '*' ) as $node ) {
				if ( in_array( strtolower( $node->localName ),[ 'style','script','foreignobject','iframe','object','embed' ],true ) ) { $ok = false; break; }
				foreach ( $node->attributes as $attribute ) {
					$value = trim( $attribute->value );
					if ( str_starts_with( strtolower( $attribute->localName ),'on' ) || ( in_array( strtolower( $attribute->localName ),[ 'href','src','base' ],true ) && $value && ! str_starts_with( $value,'#' ) ) || str_contains( $value,'\\' ) || preg_match( '/@import/i',$value ) ) { $ok = false; break 2; }
					preg_match_all( '/url\(([^)]*)\)/i',$value,$urls );
					foreach ( $urls[1] as $url ) if ( ! str_starts_with( trim( $url," \t\r\n\"'" ),'#' ) ) { $ok = false; break 3; }
				}
			}
			if ( $ok && ( new \DOMXPath( $before ) )->query( '//processing-instruction()' )->length ) $ok = false;
		}
		finally { libxml_clear_errors(); libxml_use_internal_errors( $previous ); }
		if ( ! $ok ) throw new Compiler_Package_Error( 'Bricks sanitization changes this SVG. Replace or review the source; the importer will not silently alter it.' );
	}
	/** The reader already proved each original pointer and byte hash. Only those strings change. */
	public static function remap( array $inspection,array $media ): array {
		$documents = $inspection['documents']; $seen = [];
		foreach ( $inspection['bindings'] as $binding ) {
			if ( ! isset( $documents[$binding['artifact']] ) ) continue;
			$asset = $inspection['assets'][$binding['assetId']]; if ( ! isset( $media[$asset['id']] ) ) continue;
			$file = $binding['artifact']; $pointer = $binding['pointer']; $identity = $file . '#' . $pointer . '#' . $binding['referenceHash']; if ( isset( $seen[$identity] ) ) continue; $seen[$identity] = true;
			$parts = explode( '/',substr( $pointer,1 ) ); $value = &$documents[$file];
			foreach ( $parts as $part ) { $part = str_replace( [ '~1','~0' ],[ '/','~' ],$part ); $value = &$value[$part]; }
			foreach ( Compiler_Package_Reader::embedded_references( $value ) as $reference ) if ( 'sha256:' . hash( 'sha256',$reference ) === $binding['referenceHash'] ) $value = str_replace( $reference,$media[$asset['id']]['url'],$value );
			unset( $value );
			// Native image controls need the real target ID; arbitrary CSS/text never receives one.
			if ( preg_match( '~/(?:image|(?:items/)?images/[0-9]+)/url$~D',$pointer ) ) {
				$parent = &$documents[$file]; foreach ( array_slice( $parts,0,-1 ) as $part ) { $part = str_replace( [ '~1','~0' ],[ '/','~' ],$part ); $parent = &$parent[$part]; }
				$parent['id'] = $media[$asset['id']]['id']; if ( array_key_exists( 'external',$parent ) ) $parent['external'] = false; unset( $parent );
			}
		}
		return $documents;
	}
}
