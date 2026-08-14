<?php

declare(strict_types=1);

define( 'ABSPATH', dirname( __DIR__, 2 ) . '/' );
require_once dirname( __DIR__, 2 ) . '/wp-content/mu-plugins/dsa/includes/Design/Seam_Token_Service.php';

use DSA\Design\Seam_Token_Service;

$input = [
	'id'     => 'human-palette',
	'name'   => 'Human palette',
	'custom' => 'preserve-me',
	'colors' => [
		[ 'id' => 'primary', 'name' => 'Brand Primary', 'light' => '#eb5b37', 'raw' => 'var(--brand-primary)' ],
		[ 'id' => 'text', 'name' => 'Text Primary', 'light' => '#151515', 'raw' => 'var(--text-primary)' ],
		[ 'id' => 'chosen', 'name' => 'Surface', 'light' => '#ffffff', 'dark' => '#123456' ],
		[ 'id' => 'legacy', 'name' => 'Legacy', 'light' => '#ffffff', 'hex' => '#ffffff' ],
		[ 'id' => 'dynamic', 'name' => 'Dynamic', 'light' => 'var(--client-color)' ],
	],
];

$result = Seam_Token_Service::add_missing_dark_values_to_bricks_palette( $input );
$assertions = [
	$result['generated'] === 2,
	$result['preserved'] === 1,
	$result['skipped'] === 2,
	$result['palette']['id'] === $input['id'],
	$result['palette']['custom'] === $input['custom'],
	$result['palette']['colors'][0]['light'] === $input['colors'][0]['light'],
	isset( $result['palette']['colors'][0]['dark'] ),
	isset( $result['palette']['colors'][1]['dark'] ),
	$result['palette']['colors'][2]['dark'] === '#123456',
	! isset( $result['palette']['colors'][3]['dark'] ),
	! isset( $result['palette']['colors'][4]['dark'] ),
];

if ( in_array( false, $assertions, true ) ) {
	fwrite( STDERR, "Native Bricks dark-palette test failed.\n" );
	exit( 1 );
}

echo "Native Bricks dark-palette test passed.\n";
