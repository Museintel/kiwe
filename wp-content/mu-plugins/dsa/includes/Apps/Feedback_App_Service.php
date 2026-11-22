<?php
namespace DSA\Apps;
if ( ! defined( 'ABSPATH' ) ) exit;

/** Compatibility for earlier Store packages; Feedback is built into Core, never an optional App. */
final class Feedback_App_Service {
	public static function enabled(): bool { return \DSA\Discussion\Feedback_Access::enabled(); }
	public static function allowed(): bool { return \DSA\Discussion\Feedback_Access::allowed(); }
}
