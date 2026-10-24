<?php
// Build-only projection of the canonical public metadata owners. No site state.
define('ABSPATH', __DIR__.'/');
function sanitize_key($s){return preg_replace('/[^a-z0-9_\-]/','',strtolower((string)$s));}
function wp_strip_all_tags($s){return strip_tags((string)$s);}
require dirname(__DIR__,2).'/wp-content/mu-plugins/dsa/includes/Design/Seam_Token_Service.php';
require dirname(__DIR__,2).'/wp-content/mu-plugins/dsa/includes/Design/Seam_Vocabulary_Schema.php';
echo json_encode(['tokens'=>array_slice(DSA\Design\Seam_Token_Service::tokens_with_overrides(),0,80),'vocabulary'=>DSA\Design\Seam_Vocabulary_Schema::contract()],JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
