<?php
namespace DSA\Access;
if ( ! defined( 'ABSPATH' ) ) exit;

/** The advanced App view is included by its original authorized native controller. */
final class SiteGraph_Workspace_Service {
	public static function template(): string { return __DIR__ . '/views/sitegraph-workspace.php'; }
}
