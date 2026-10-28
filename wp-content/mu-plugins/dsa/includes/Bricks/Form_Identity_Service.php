<?php
namespace DSA\Bricks;

if ( ! defined( 'ABSPATH' ) ) exit;

/** Identity is read from the saved native form, never from a submitted field. */
final class Form_Identity_Service {
	public const META = '_kiwe_form_identity_v1';
	private static array $pending = [];
	public static function contract(): array {
		return [ 'schema'=>'kiwe.form-identity.v1', 'available'=>class_exists( '\\Bricks\\Integrations\\Form\\Submission_Database' ), 'authority'=>'saved-native-bricks-form', 'attributes'=>[ 'key'=>'data-kiwe-form', 'label'=>'data-kiwe-form-label' ], 'keyPattern'=>'^[a-z][a-z0-9_-]{0,63}$', 'bricks'=>[ 'element'=>'form', 'nameControl'=>'submissionFormName', 'saveAction'=>'save-submission', 'emailRecipientTag'=>'{kiwe_store_email}' ], 'requires'=>[ 'native Bricks submission storage', 'the Save submission action on each form', 'a deliberately configured native email action for email delivery' ], 'daily'=>[ 'formFilters'=>'automatic from saved native page/form identities', 'notifications'=>'role-derived private team inbox; optional opted-in delivery' ], 'privacy'=>[ 'publicEnquiries'=>false, 'submittedLabelsAreAuthority'=>false, 'marketingConsentImplied'=>false ], 'authorRule'=>'Give each form a stable slug and descriptive label. Reuse the native Bricks form and its storage/delivery owners. A preview does not send enquiries; a saved message does not prove email delivery. Never invent recipients, store sign-in secrets or expose enquiry answers in public context.' ];
	}
	public static function identity( array $settings, int $post, string $form ): array {
		$attributes = [];
		foreach ( (array) ( $settings['_attributes'] ?? [] ) as $attribute ) {
			if ( ! is_array( $attribute ) || ! in_array( $attribute['name'] ?? '', [ 'data-kiwe-form', 'data-kiwe-form-label' ], true ) ) continue;
			$name = $attribute['name'];
			$attributes[$name][] = is_string( $attribute['value'] ?? null ) ? $attribute['value'] : '';
		}
		$key = count( $attributes['data-kiwe-form'] ?? [] ) === 1 ? $attributes['data-kiwe-form'][0] : '';
		$key = preg_match( '/^[a-z][a-z0-9_-]{0,63}$/D', $key ) ? $key : 'native-' . $post . '-' . $form;
		$label = count( $attributes['data-kiwe-form-label'] ?? [] ) === 1 ? $attributes['data-kiwe-form-label'][0] : '';
		$label = self::label( $label ?: ( $settings['submissionFormName'] ?? $settings['formName'] ?? '' ) );
		return [ 'key'=>$key, 'label'=>$label ?: sprintf( __( 'Form %s', 'dsa' ), $form ), 'postId'=>$post, 'formId'=>$form ];
	}
	private static function label( $value ): string {
		$value = is_string( $value ) ? sanitize_text_field( $value ) : '';
		return function_exists( 'mb_substr' ) ? mb_substr( $value, 0, 160 ) : substr( $value, 0, 160 );
	}
	public static function stored_identity( array $data, int $post, string $form ): ?array {
		$record = $data[self::META] ?? null;
		if ( ! is_array( $record ) || ( $record['type'] ?? '' ) !== 'kiwe-internal' || ! is_string( $record['value'] ?? null ) || strlen( $record['value'] ) > 2048 ) return null;
		$identity = json_decode( $record['value'], true );
		if ( ! is_array( $identity ) || ( $identity['postId'] ?? null ) !== $post || ( $identity['formId'] ?? null ) !== $form || ! is_string( $identity['key'] ?? null ) || ! preg_match( '/^[a-zA-Z0-9_-]{1,100}$/D', $identity['key'] ) ) return null;
		return [ 'key'=>$identity['key'], 'label'=>self::label( $identity['label'] ?? '' ), 'postId'=>$post, 'formId'=>$form ];
	}
	public static function capture( array $data, $form_id, $post_id ): array {
		$class = '\\Bricks\\Integrations\\Form\\Submission_Database';
		if ( ! class_exists( $class ) || ! is_string( $form_id ) || ! preg_match( '/^[a-zA-Z0-9_-]{1,64}$/D', $form_id ) ) return $data;
		$post = max( 0, (int) $post_id );
		$settings = $class::get_form_settings( $post, $form_id );
		if ( ! is_array( $settings ) || ! $settings ) { unset( $data[self::META] ); return $data; }
		global $wpdb;
		$table = $class::get_table_name();
		if ( $table !== $wpdb->prefix . 'bricks_form_submissions' ) { unset( $data[self::META] ); return $data; }
		$after = (int) $wpdb->get_var( "SELECT COALESCE(MAX(id),0) FROM `{$table}`" );
		if ( $wpdb->last_error ) { unset( $data[self::META] ); return $data; }
		$identity = self::identity( $settings, $post, $form_id );
		$identity['receipt'] = bin2hex( random_bytes( 16 ) );
		$data[self::META] = [ 'type'=>'kiwe-internal', 'value'=>wp_json_encode( $identity, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) ];
		self::$pending[$identity['receipt']] = [ 'identity'=>$identity, 'after'=>$after ];
		if ( 1 === count( self::$pending ) ) add_action( 'shutdown', [ self::class, 'confirmed' ], 5 );
		return $data;
	}
	/** Confirm the exact native insert before queuing a notice, even if a later mail action fails. */
	public static function confirmed(): void {
		$class = '\\Bricks\\Integrations\\Form\\Submission_Database';
		if ( ! self::$pending || ! class_exists( $class ) ) return;
		global $wpdb;
		$table = $class::get_table_name();
		if ( $table !== $wpdb->prefix . 'bricks_form_submissions' ) return;
		foreach ( self::$pending as $receipt=>$pending ) {
			$identity = $pending['identity'];
			// The native primary-key floor limits this one submission's search window.
			// Exact random receipt matching cannot lose a saved record to later arrivals.
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id,LEFT(form_data,131073) AS form_data FROM `{$table}` WHERE id>%d AND post_id=%d AND form_id=%s AND form_data LIKE %s ORDER BY id ASC LIMIT 1", $pending['after'], $identity['postId'], $identity['formId'], '%' . $wpdb->esc_like( $receipt ) . '%' ), ARRAY_A );
			if ( $wpdb->last_error ) continue;
			foreach ( (array) $rows as $row ) {
				$record = json_decode( (string) $row['form_data'], true );
				$metadata = is_array( $record ) ? ( $record[self::META] ?? [] ) : [];
				$saved = is_string( $metadata['value'] ?? null ) ? json_decode( $metadata['value'], true ) : null;
				if ( ! is_array( $saved ) || ! hash_equals( $receipt, (string) ( $saved['receipt'] ?? '' ) ) || $saved !== $identity ) continue;
				do_action( 'kiwe_form_submission_saved', (int) $row['id'] );
				break;
			}
		}
		self::$pending = [];
	}
}
