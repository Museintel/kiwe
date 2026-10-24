<?php
namespace DSA\Access;

use DSA\PhoneKey\PhoneKey_Bridge;

if ( ! defined( 'ABSPATH' ) ) exit;

/** The existing Key enrollment reminder, projected without changing security state. */
final class Account_Setup_Notice_Service {
	public function register(): void {
		add_action( 'admin_notices', [ $this, 'render' ], 5 );
		add_action( 'admin_enqueue_scripts', [ $this, 'assets' ] );
	}

	public function steps(): array {
		return PhoneKey_Bridge::admin_enrollment_steps();
	}

	public function assets(): void { if ( $this->steps() ) Workspace_Notice_UI::enqueue(); }

	public function render(): void {
		$steps = $this->steps();
		if ( ! $steps ) return;
		Workspace_Notice_UI::render( 'warning', __( 'Finish securing your account', 'dsa' ), __( 'Your account security setup is incomplete.', 'dsa' ), PhoneKey_Bridge::admin_enrollment_url(), __( 'Continue secure setup', 'dsa' ), $steps );
	}
}
