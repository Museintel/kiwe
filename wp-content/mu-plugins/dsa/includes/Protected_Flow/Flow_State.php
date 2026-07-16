<?php

namespace DSA\Protected_Flow;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final readonly class Flow_State {
	public function __construct(
		public Flow_Context $context,
		public string $message
	) {}

	public function to_array(): array {
		$active = $this->context->is_protected();

		return [
			'active'           => $active,
			'context'          => $this->context->value,
			'fragmentAllowed'  => ! $active,
			'outsideDismiss'   => ! $active,
			'requiresFullPage' => $active,
			'message'          => $this->message,
		];
	}
}
