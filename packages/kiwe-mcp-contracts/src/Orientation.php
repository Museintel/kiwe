<?php
declare(strict_types=1);
namespace Kiwe\MCP\Contracts;

// M01's pure composer is the optional App's runtime owner. Retain this source
// entry point without a second implementation or onboarding state store.
require_once dirname(__DIR__,3).'/wp-content/mu-plugins/dsa/includes/MCP/Orientation.php';
final class Orientation {
 public static function compose(array $facts,?array $journey): array { return \DSA\MCP\Orientation::compose($facts,$journey); }
}
