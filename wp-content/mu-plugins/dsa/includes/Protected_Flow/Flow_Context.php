<?php

namespace DSA\Protected_Flow;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

enum Flow_Context: string {
	case None          = '';
	case Checkout      = 'checkout';
	case Payment       = 'payment';
	case OrderReceived = 'order_received';
	case Account       = 'account';
	case Login         = 'login';
	case PasswordReset = 'password_reset';
	case Cart          = 'cart';
	case CartCommit    = 'cart_commit';

	public function is_protected(): bool {
		return self::None !== $this;
	}
}
