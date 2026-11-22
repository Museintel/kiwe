<?php
namespace DSA\Bricks;

if ( ! defined( 'ABSPATH' ) ) exit;

/** Native saved field definitions establish meaning; labels and submitted field names are not authority. */
final class Form_Contact_Adapter {
	public static function fields( array $settings ): array {
		$actions = (array) ( $settings['actions'] ?? [] );
		if ( array_intersect( $actions, [ 'user-login','user-registration','login','register','registration','reset-password' ] ) ) return [];
		$map = []; $duplicates = [];
		foreach ( (array) ( $settings['fields'] ?? [] ) as $field ) {
			if ( ! is_array( $field ) ) continue;
			$type = $field['type'] ?? '';
			if ( 'password' === $type ) return [];
			if ( ! in_array( $type, [ 'text','email','tel' ], true ) || ! empty( $field['isHoneypot'] ) ) continue;
			$id = $field['id'] ?? ''; if ( ! is_string( $id ) || ! preg_match( '/^[a-zA-Z0-9_-]{1,64}$/D', $id ) ) continue;
			$complete = strtolower( trim( is_string( $field['autocomplete'] ?? null ) ? $field['autocomplete'] : '' ) );
			$tokens = preg_split( '/\s+/', $complete ); $complete = end( $tokens );
			$named = strtolower( (string) ( $field['name'] ?? $id ) );
			$role = match ( $complete ) { 'name'=>'name', 'given-name'=>'first_name', 'family-name'=>'last_name', 'email'=>'email', 'tel'=>'phone', default=>'' };
			if ( ! $role ) $role = match ( $named ) { 'name','full_name','fullname'=>'name', 'first_name','firstname'=>'first_name', 'last_name','lastname'=>'last_name', 'email'=>'email', 'phone','telephone','tel'=>'phone', default=>'' };
			if ( ! $role ) $role = match ( $type ) { 'email'=>'email', 'tel'=>'phone', default=>'' };
			if ( ! $role ) continue;
			if ( isset( $map[$role] ) ) $duplicates[$role] = true;
			$map[$role] = $id;
		}
		return array_diff_key( $map, $duplicates );
	}
	public static function values( array $data, array $settings ): array {
		$out = [];
		foreach ( self::fields( $settings ) as $role=>$id ) if ( is_string( $data[$id]['value'] ?? null ) ) $out[$role] = $data[$id]['value'];
		return \DSA\Identity\Form_Contact_Service::clean( $out );
	}
	public static function response( $response, $form ) {
		// Confirm an actual saved native record even when a later email action reports a failure.
		Form_Identity_Service::confirmed();
		if ( ! $form instanceof \Bricks\Integrations\Form\Init ) return $response;
		$results = $form->get_results();
		if ( ! is_array( $results ) || empty( $results['success'] ) || ! empty( $results['error'] ) || ! empty( $results['danger'] ) ) return $response;
		$data = []; $settings = $form->get_settings();
		if ( ! is_array( $settings ) ) return $response;
		// Saved forms were already captured after their exact insert. Other successful native actions use the same owner.
		if ( in_array( 'save-submission', (array) ( $settings['actions'] ?? [] ), true ) ) return $response;
		foreach ( self::fields( $settings ) as $role=>$id ) $data[$id] = [ 'value'=>$form->get_field_value( $id ) ];
		\DSA\Identity\Form_Contact_Service::capture( self::values( $data, $settings ) );
		return $response;
	}
}
