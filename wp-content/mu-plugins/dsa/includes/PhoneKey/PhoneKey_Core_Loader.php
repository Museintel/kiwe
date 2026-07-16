<?php

namespace DSA\PhoneKey;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class PhoneKey_Core_Loader {
	public function register(): void {
		if ( ! defined( 'KIWE_AUTH_SURFACE_UI' ) ) {
			define( 'KIWE_AUTH_SURFACE_UI', true );
		}

		if ( ! defined( 'KIWE_AUTH_ADMIN_UI' ) ) {
			define( 'KIWE_AUTH_ADMIN_UI', true );
		}

		require_once DSA_DIR . 'includes/PhoneKey/phonekey-core.php';
	}
}
