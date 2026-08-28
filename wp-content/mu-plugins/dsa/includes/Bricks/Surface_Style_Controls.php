<?php
namespace DSA\Bricks;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Editor-only additions: no default values, frontend script, or global CSS. */
final class Surface_Style_Controls {

	private static bool $registered = false;
	private const GROUP = 'kiweSurfaceStyles';
	private const TYPES = [
		'typography' => [ 'typography', 'font' ],
		'background' => [ 'color', 'background-color' ],
		'border' => [ 'border', 'border' ],
		'padding' => [ 'spacing', 'padding' ],
		'margin' => [ 'spacing', 'margin' ],
		'shadow' => [ 'box-shadow', 'box-shadow' ],
		'width' => [ 'number', 'width' ],
		'height' => [ 'number', 'height' ],
	];

	public static function catalog(): array {
		static $catalog;
		if ( null === $catalog ) {
			$data = json_decode( (string) file_get_contents( __DIR__ . '/surface-style-controls.json' ), true );
			$catalog = is_array( $data['elements'] ?? null ) ? $data['elements'] : [];
		}
		return $catalog;
	}

	public static function register(): void {
		if ( self::$registered ) { return; }
		self::$registered = true;
		foreach ( self::catalog() as $element => $parts ) {
			add_filter( 'bricks/elements/' . $element . '/control_groups', static function ( array $groups ): array {
				$groups[ self::GROUP ] = [ 'title' => __( 'Kiwe surface styles', 'dsa' ), 'tab' => 'style' ];
				return $groups;
			}, 30 );
			add_filter( 'bricks/elements/' . $element . '/controls', static fn( array $controls ): array => self::extend( $controls, $parts ), 30 );
		}
	}

	public static function extend( array $controls, array $parts ): array {
		foreach ( $parts as $part ) {
			foreach ( $part['extensions'] as $kind => $key ) {
				if ( isset( $controls[ $key ] ) || ! isset( self::TYPES[ $kind ] ) ) { continue; }
				[ $type, $property ] = self::TYPES[ $kind ];
				$control = [
					'tab' => 'style', 'group' => self::GROUP, 'type' => $type,
					'label' => $part['label'] . ': ' . ucfirst( $kind ),
					'css' => [ [ 'property' => $property, 'selector' => $part['target'] ] ],
				];
				if ( 'number' === $type ) { $control['units'] = true; }
				$controls[ $key ] = $control;
			}
		}
		return $controls;
	}

	/** Advertise installed style keys, not a generic "Kiwe exists" assumption. */
	public static function capabilities(): array {
		if ( ! self::$registered ) { return []; }
		$result = [];
		foreach ( self::catalog() as $element => $parts ) {
			$result[ $element ] = [];
			foreach ( $parts as $part ) {
				$result[ $element ] = array_merge( $result[ $element ], array_values( $part['extensions'] ) );
			}
		}
		return $result;
	}
}
