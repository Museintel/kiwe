<?php
namespace DSA\SEO;
if (!defined('ABSPATH')) exit;
// Compatibility name for the App; inert definitions and recovery remain in Core.
if(!class_exists(\DSA\Onboarding\Search_Defaults_Model::class))require_once dirname(__DIR__).'/Onboarding/Search_Defaults_Model.php';
class_alias(\DSA\Onboarding\Search_Defaults_Model::class,__NAMESPACE__.'\\SEO_Defaults_Model');
