<?php

namespace DSA;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Autoloader {
	public static function register(): void {
		spl_autoload_register( [ __CLASS__, 'load' ] );
	}

	public static function load( string $class ): void {
		static $reported_misses = [];

		if ( 0 !== strpos( $class, 'DSA\\' ) ) {
			return;
		}

		$relative = substr( $class, 4 );
		$relative = str_replace( '\\', DIRECTORY_SEPARATOR, $relative );
		$base     = DSA_DIR . 'includes' . DIRECTORY_SEPARATOR;
		$file     = $base . $relative . '.php';

		if ( file_exists( $file ) ) {
			require_once $file;
			return;
		}

		if ( function_exists( 'kiwe_mu_debug_log' ) && empty( $reported_misses[ $class ] ) ) {
			$reported_misses[ $class ] = true;
			\kiwe_mu_debug_log( 'Autoload miss', [ 'class' => $class ] );
		}
	}
}
