<?php
namespace DSA\AI;
use DSA\Settings;
if ( ! defined( 'ABSPATH' ) ) exit;
/** Deterministic authoring remains Core even without a paid AI engine. */
final class AI_Prompt_Service {
 private const MAX_PROMPT_BYTES=90000;
 public function __construct(private Settings $settings) {}
 private function settings(): array { return (array)$this->settings->get('ai',[]); }
	public function build_prompt( array $context, string $task, string $mode = 'combined' ): array {
		$settings = $this->settings();
		$system = implode(
			"\n",
			[
				'You are Kiwe Studio AI, a bounded WordPress/Bricks/Kiwe AppShell design assistant.',
				'Use the supplied Kiwe context packet only. Do not ask to read the whole repository.',
				'Keep Seam semantic/headless. Use custom page/theme CSS for visual art direction.',
				'Respect Kiwe authority boundaries: WordPress/Bricks own page content, Kiwe owns AppShell, WooCommerce owns money/cart/order runtime.',
				'Return concise, implementation-ready output. If asked for files, keep to the Kiwe output shape and avoid extra reports unless requested.',
			]
		);
		$user = [
			'task'       => $this->trim_bytes( $task, 12000 ),
			'mode'       => sanitize_key( $mode ),
			'context'    => $context,
			'budget'     => [
				'maxOutputTokens' => max( 200, absint( $settings['max_native_tokens'] ?? 3000 ) ),
				'tokenSaver'      => ! empty( $settings['token_saver_enabled'] ),
			],
		];

		$encoded_user = wp_json_encode( $user, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		$encoded_user = false === $encoded_user ? '{}' : (string) $encoded_user;

		return [
			'system' => $system,
			'user'   => $this->trim_bytes( $encoded_user, self::MAX_PROMPT_BYTES ),
		];
	}

	private function trim_bytes( string $value, int $limit ): string {
		$value = trim( $value );
		if ( strlen( $value ) <= $limit ) {
			return $value;
		}

		return substr( $value, 0, max( 0, $limit - 28 ) ) . "\n...[trimmed by Kiwe]...";
	}
}
