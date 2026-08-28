<?php
/** Isolated contract test; no WordPress/database writes. */
define( 'ABSPATH', __DIR__ );
$hooks = [];
function add_filter( $name, $callback, $priority = 10 ) { global $hooks; $hooks[$name][] = $callback; }
function __( $text, $domain = '' ) { return $text; }
function esc_html__( $text, $domain = '' ) { return $text; }
require dirname(__DIR__, 2) . '/wp-content/mu-plugins/dsa/includes/Bricks/Surface_Style_Controls.php';
use DSA\Bricks\Surface_Style_Controls as Controls;
$checks = 0;
function check( $label, $pass ) { global $checks; ++$checks; if (!$pass) { throw new RuntimeException($label); } }
check('Capabilities absent before registration', Controls::capabilities() === []);
Controls::register();
$count = count($hooks);
Controls::register();
check('Registration is idempotent', count($hooks) === $count);
$keys = [];
foreach (Controls::catalog() as $element => $parts) {
    $controls = Controls::extend(['untouched' => ['default' => 'existing']], $parts);
    check('Existing controls preserved', $controls['untouched']['default'] === 'existing');
    if (isset($hooks['bricks/elements/' . $element . '/controls'])) {
        $hook = $hooks['bricks/elements/' . $element . '/controls'][0];
        check('Live filter uses identical catalog', $hook(['untouched' => ['default' => 'existing']]) === $controls);
    }
    foreach ($parts as $part) {
        foreach ($part['extensions'] as $kind => $key) {
            check('Unique extension key', !isset($keys[$key])); $keys[$key] = true;
            check('No default CSS effect', !array_key_exists('default', $controls[$key]));
            check('Native style-tab control', $controls[$key]['tab'] === 'style');
            check('Exact scoped target', $controls[$key]['css'][0]['selector'] === $part['target']);
            check('Advertised capability', in_array($key, Controls::capabilities()[$element], true));
            check('Does not overwrite other extension', Controls::extend([$key => ['existing' => true]], $parts)[$key] === ['existing' => true]);
        }
        foreach ($part['native'] as $key) { check('Does not duplicate native controls', !isset($controls[$key])); }
    }
}
check('Standalone surface registration does not invent ATC controls', Controls::capabilities()['product-add-to-cart'] === []);
check('Integrated ATC registration advertises existing exact controls', count(Controls::capabilities(true)['product-add-to-cart']) === 15);
require dirname(__DIR__, 2) . '/wp-content/mu-plugins/dsa/includes/Bricks/Bricks_Integration.php';
$reflection = new ReflectionClass(\DSA\Bricks\Bricks_Integration::class);
$integration = $reflection->newInstanceWithoutConstructor();
$reflection->getProperty('settings')->setValue($integration, new class {
    public function all() { return ['bricks' => ['add_to_cart_enhancer_enabled' => false]]; }
    public function get($key, $default = null) { return $this->all()[$key] ?? $default; }
});
$groups = $integration->add_add_to_cart_control_group([]);
$atc = $integration->add_add_to_cart_controls([]);
foreach (Controls::capabilities(true)['product-add-to-cart'] as $key) {
    check('Advertised ATC control really exists: ' . $key, isset($atc[$key]));
    check('ATC styling is in a style-tab group', $groups[$atc[$key]['group']]['tab'] === 'style');
}
check('Behavior stays in content', $groups[$atc['brxPlusOnly']['group']]['tab'] === 'content');
echo json_encode(['pass' => true, 'checks' => $checks, 'elements' => count(Controls::catalog()), 'extensions' => count($keys)]) . PHP_EOL;
