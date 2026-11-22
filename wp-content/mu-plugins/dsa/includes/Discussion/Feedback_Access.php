<?php
namespace DSA\Discussion;
if ( ! defined( 'ABSPATH' ) ) exit;

/** Built-in community feature. Native policy and role permissions control each task. */
final class Feedback_Access {
	public static function enabled(): bool { return true; }
	public static function allowed(): bool { return current_user_can('manage_options') || current_user_can('kiwe_manage_context') && current_user_can('moderate_comments'); }
	public static function notification_visible_for(int $actor,array $item): bool { return Discussion_Notifications::visible_for($actor,$item); }
	public static function dispatch(string $event,int $object): void { Discussion_Notifications::dispatch($event,$object); }
}
