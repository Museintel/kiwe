<?php
namespace DSA\Apps;
if ( ! defined( 'ABSPATH' ) ) exit;
final class SiteGraph_App_Service extends Optional_App_Service {
	public const ID='sitegraph',NAME='SiteGraph',CAPABILITY='manage_options',PAGE='kiwe-sitegraph',DESCRIPTION='Advanced graph setup, export, pairing and validation. Complete Design Context and factual authoring remain in Core.';
}
