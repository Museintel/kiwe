<?php
/** Extract named, unchanged global functions without booting the authentication plugin. */
function kiwe_phonekey_function( string $source, string $name ): ?string {
	$tokens = token_get_all( $source );$collect = false;$body = false;$depth = 0;$code = '';
	foreach ( $tokens as $i => $token ) {
		if ( ! $collect && is_array( $token ) && T_FUNCTION === $token[0] ) {
			$j = $i + 1;
			while ( isset( $tokens[$j] ) && is_array( $tokens[$j] ) && T_WHITESPACE === $tokens[$j][0] ) ++$j;
			$collect = is_array( $tokens[$j] ?? null ) && $tokens[$j][1] === $name;
		}
		if ( ! $collect ) continue;
		$part = is_array( $token ) ? $token[1] : $token;$code .= $part;
		if ( '{' === $part ) { $body = true;++$depth; }
		elseif ( '}' === $part && 0 === --$depth && $body ) return $code;
	}
	return null;
}
