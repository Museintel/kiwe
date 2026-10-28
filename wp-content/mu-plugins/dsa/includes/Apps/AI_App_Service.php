<?php
namespace DSA\Apps;
if ( ! defined( 'ABSPATH' ) ) exit;
final class AI_App_Service extends Optional_App_Service {
	public const ID='ai',NAME='AI',CAPABILITY='kiwe_manage_ai_preferences',PAGE='kiwe-ai-features',DESCRIPTION='Provider setup, protected credentials, allowances and optional AI assistance. Browser-AI authoring stays in Core.';
	public static function option(): string { return 'kiwe_ai_opt_in_global_v1'; }
}
