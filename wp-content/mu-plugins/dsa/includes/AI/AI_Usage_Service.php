<?php

namespace DSA\AI;

if ( ! defined( 'ABSPATH' ) ) exit;

/** Pure accounting helpers. No tokenizer dependency, network lookup or pricing claim. */
final class AI_Usage_Service {
	/** UTF-8 payload bytes plus output allowance and reasoning/framing headroom, not a tokenizer. */
	public static function estimate( array $payload, int $max_output ): int {
		$encoded = wp_json_encode( $payload );
		if ( ! is_string( $encoded ) || strlen( $encoded ) > 250000 ) return 0;
		return strlen( $encoded ) + max( 200, min( 12000, $max_output ) ) + 8192;
	}

	/** Only supported response shapes release a reservation; unknown adapters retain it. */
	public static function reported( string $provider, string $model, array $body ): ?array {
		if ( 'gemini' !== $provider ) return null;
		$interactions = str_starts_with( $model, 'gemini-3' );
		$data = $body[ $interactions ? 'usage' : 'usageMetadata' ] ?? null;
		if ( ! is_array( $data ) ) return null;
		$keys = $interactions
			? [ 'input'=>'total_input_tokens', 'output'=>'total_output_tokens', 'thinking'=>'total_thought_tokens', 'total'=>'total_tokens' ]
			: [ 'input'=>'promptTokenCount', 'output'=>'candidatesTokenCount', 'thinking'=>'thoughtsTokenCount', 'total'=>'totalTokenCount' ];
		$out = [];
		foreach ( $keys as $key=>$field ) {
			$value = $data[ $field ] ?? ( 'thinking' === $key && ! $interactions ? 0 : null );
			if ( ! is_int( $value ) || $value < 0 || $value > 1000000000 ) return null;
			$out[ $key ] = $value;
		}
		$tools = $data[ $interactions ? 'total_tool_use_tokens' : 'toolUsePromptTokenCount' ] ?? 0;
		if ( ! is_int( $tools ) || $tools < 0 || $tools > 1000000000 ) return null;
		// Cache counts are a subset of input. Thinking is separate from visible output here.
		if ( $out['total'] < 1 || $out['total'] < $out['input'] + $out['output'] + $out['thinking'] ) return null;
		// Count tool usage conservatively even if an adapter's total excludes it.
		$out['total'] = max( $out['total'], $out['input'] + $out['output'] + $out['thinking'] + $tools );
		return $out;
	}
}
