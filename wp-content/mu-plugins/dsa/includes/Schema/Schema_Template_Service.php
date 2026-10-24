<?php

namespace DSA\Schema;

use DSA\Apps\SEO_App_Service;

if ( ! defined( 'ABSPATH' ) ) exit;

/** Bounded declarative schema. No arbitrary code, remote contexts or provider callbacks. */
final class Schema_Template_Service {
	public const META = '_kiwe_schema_graph_v1';
	public const BYTES = 65536;
	private const TYPES = [ 'Article', 'NewsArticle', 'BlogPosting', 'WebPage', 'WebSite', 'Organization', 'OnlineStore', 'Person', 'ImageObject', 'BreadcrumbList', 'ListItem', 'PostalAddress', 'FAQPage', 'Question', 'Answer', 'SearchAction', 'ReadAction', 'SpeakableSpecification', 'CollectionPage', 'ContactPage', 'AboutPage', 'ProfilePage' ];
	private const FIELDS = [ '@context', '@graph', '@type', '@id', 'name', 'headline', 'description', 'url', 'image', 'logo', 'author', 'publisher', 'datePublished', 'dateModified', 'uploadDate', 'inLanguage', 'mainEntityOfPage', 'mainEntity', 'isPartOf', 'hasPart', 'sameAs', 'address', 'email', 'telephone', 'legalName', 'foundingDate', 'streetAddress', 'addressLocality', 'addressRegion', 'postalCode', 'addressCountry', 'articleSection', 'keywords', 'wordCount', 'copyrightHolder', 'copyrightYear', 'thumbnailUrl', 'caption', 'width', 'height', 'contentUrl', 'encodingFormat', 'breadcrumb', 'itemListElement', 'item', 'position', 'potentialAction', 'target', 'query-input', 'acceptedAnswer', 'text', 'speakable', 'cssSelector', 'xpath', 'identifier', 'alternateName', 'about', 'subjectOf', 'knowsAbout', 'jobTitle', 'contentLocation', 'locationCreated', 'genre', 'accessMode', 'isAccessibleForFree' ];
	public const VARIABLES = [ 'title', 'seo_title', 'seo_description', 'description', 'sitename', 'siteurl', 'permalink', 'url', 'excerpt', 'post_author', 'name', 'post_thumbnail', 'date(Y-m-dTH:i:sP)', 'date(Y-m-d\TH:i:sP)', 'modified(Y-m-dTH:i:sP)', 'modified(Y-m-d\TH:i:sP)' ];
	private const NATIVE_ONLY = [ 'permalink', 'siteurl', 'description' ];

	public static function error( string $message ): \WP_Error { return new \WP_Error( 'kiwe_schema_unsupported', $message ); }
	public static function decode( $raw ) {
		if ( is_array( $raw ) ) return $raw;
		if ( ! is_string( $raw ) || strlen( $raw ) > self::BYTES || str_contains( $raw, "\0" ) || ! preg_match( '//u', $raw ) ) return self::error( 'Schema input exceeds its safe text boundary.' );
		try {
			if ( preg_match( '/^\s*[\[{]/', $raw ) ) return json_decode( $raw, true, 12, JSON_THROW_ON_ERROR );
			$value = @unserialize( $raw, [ 'allowed_classes'=>false, 'max_depth'=>12 ] );
			return is_array( $value ) ? $value : self::error( 'Schema input is not a declarative graph.' );
		} catch ( \Throwable $e ) { return self::error( 'Schema input is malformed.' ); }
	}

	public static function validate( $raw ) {
		$data = self::decode( $raw ); if ( is_wp_error( $data ) ) return $data;
		if ( ! is_array( $data ) || ! $data || strlen( (string) wp_json_encode( $data ) ) > self::BYTES ) return self::error( 'A bounded schema graph is required.' );
		$remaining = 512;
		$walk = static function( $value, int $depth = 0 ) use ( &$walk, &$remaining ) {
			if ( $depth > 10 || --$remaining < 0 || is_object( $value ) || is_resource( $value ) ) return self::error( 'Schema structure exceeds its safe boundary.' );
			if ( is_string( $value ) ) {
				if ( strlen( $value ) > 8192 || str_contains( $value, "\0" ) || ! preg_match( '//u', $value ) ) return self::error( 'Schema text exceeds its safe boundary.' );
				preg_match_all( '/%([^%]+)%/', $value, $tokens );
				foreach ( $tokens[1] as $token ) if ( ! in_array( $token, self::VARIABLES, true ) ) return self::error( 'A source schema variable needs a supported binding before handover.' );
				return $value;
			}
			if ( is_array( $value ) ) {
				if ( count( $value ) > 64 ) return self::error( 'Schema node cardinality exceeds its safe boundary.' );
				$list = array_is_list( $value ); $out = [];
				foreach ( $value as $key=>$item ) {
					if ( ! $list && ! in_array( $key, self::FIELDS, true ) ) return self::error( 'A source schema field needs explicit support before handover.' );
					if ( '@context' === $key && ! in_array( $item, [ 'https://schema.org', 'http://schema.org' ], true ) ) return self::error( 'Remote schema contexts are unsupported.' );
					if ( '@type' === $key ) foreach ( is_array( $item ) ? $item : [ $item ] as $type ) if ( ! is_string( $type ) || ! in_array( $type, self::TYPES, true ) ) return self::error( 'A source schema type needs explicit support before handover.' );
					$clean = $walk( $item, $depth + 1 ); if ( is_wp_error( $clean ) ) return $clean; $out[$key] = $clean;
				}
				return $out;
			}
			return is_null( $value ) || is_bool( $value ) || is_int( $value ) || ( is_float( $value ) && is_finite( $value ) ) ? $value : self::error( 'Schema values must be plain JSON data.' );
		};
		$clean = $walk( $data ); if ( is_wp_error( $clean ) ) return $clean;
		if ( isset( $clean['@graph'] ) && array_diff( array_keys( $clean ), [ '@context', '@graph' ] ) ) return self::error( 'Graph envelopes cannot silently discard additional behavior.' );
		$graph = isset( $clean['@graph'] ) ? $clean['@graph'] : ( array_is_list( $clean ) ? $clean : [ $clean ] );
		if ( ! is_array( $graph ) || ! array_is_list( $graph ) || ! $graph || count( $graph ) > 32 ) return self::error( 'A bounded list of schema nodes is required.' );
		$ids = [];
		foreach ( $graph as $node ) {
			if ( ! is_array( $node ) || empty( $node['@type'] ) ) return self::error( 'Every root schema node needs a supported type.' );
			if ( isset( $node['@id'] ) ) {
				if ( ! is_string( $node['@id'] ) || isset( $ids[$node['@id']] ) ) return self::error( 'Duplicate or invalid root identities require review.' );
				$ids[$node['@id']] = true;
			}
		}
		return $graph;
	}

	/** Rank Math metadata is editor configuration, never public JSON-LD behavior. */
	public static function source_node( $raw ) {
		$node = self::decode( $raw ); if ( is_wp_error( $node ) ) return $node;
		$encoded=(string)wp_json_encode($node);foreach(self::NATIVE_ONLY as$variable)if(str_contains($encoded,'%'.$variable.'%'))return self::error('A native-only variable cannot be assumed to preserve source provider behavior.');
		if ( isset( $node['metadata'] ) ) {
			$meta = $node['metadata'];
			if ( ! is_array( $meta ) || array_diff( array_keys( $meta ), [ 'title', 'type', 'isPrimary', 'shortcode', 'name', 'description' ] ) || ! in_array( $meta['type'] ?? 'custom', [ 'custom', 'template' ], true ) ) return self::error( 'Conditional schema metadata needs review before handover.' );
			foreach ( $meta as $key=>$value ) if ( 'isPrimary' === $key ? ! is_bool( $value ) : ( ! is_string( $value ) || strlen( $value ) > 8192 ) ) return self::error( 'Schema editor metadata is malformed.' );
			unset( $node['metadata'] );
		}
		return self::validate( $node );
	}

	public static function resolve( array $graph, array $facts ) {
		$clean = self::validate( $graph ); if ( is_wp_error( $clean ) ) return $clean;
		$bindings = []; foreach ( self::VARIABLES as $key ) {
			$value = $facts[$key] ?? '';
			if ( ! is_string( $value ) || strlen( $value ) > 8192 || str_contains( $value, "\0" ) || ! preg_match( '//u', $value ) ) return self::error( 'Current schema facts exceed their safe text boundary.' );
			$bindings[ '%' . $key . '%' ] = $value;
		}
		$walk = static function( $value ) use ( &$walk, $bindings ) {
			if ( is_string( $value ) ) return strtr( $value, $bindings );
			if ( ! is_array( $value ) ) return $value;
			$out=[]; foreach ( $value as $key=>$item ) { $resolved=$walk( $item ); if ( '' !== $resolved && null !== $resolved && [] !== $resolved ) $out[$key]=$resolved; } return array_is_list( $value ) ? array_values( $out ) : $out;
		};
		$result = $walk( $clean );
		return strlen( (string) wp_json_encode( $result ) ) <= self::BYTES ? $result : self::error( 'Expanded schema exceeds its output budget.' );
	}

	public static function graph( int $id, array $facts ) {
		if ( ! $id || ! SEO_App_Service::enabled() ) return null;
		if ( ! metadata_exists( 'post', $id, self::META ) ) return null;
		$value = get_post_meta( $id, self::META, true );
		$clean = self::validate( $value ); return is_wp_error( $clean ) ? $clean : self::resolve( $clean, $facts );
	}
}
