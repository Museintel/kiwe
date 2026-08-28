<?php
/** Theme lifecycle + disabled-runtime contract. Each mode runs in a fresh PHP process. */
define('ABSPATH', __DIR__);
$mode = $argv[1] ?? 'parent';
$theme = match ($mode) {
    'parent' => ['bricks', 'bricks', 'Bricks', true],
    'child' => ['bricks', 'client-child', 'Bricks', true],
    'renamed' => ['builder-parent', 'client-child', 'Bricks', true],
    'inactive' => ['twentytwentyfive', 'twentytwentyfive', 'Twenty Twenty-Five', false],
    'missing' => ['removed-theme', 'removed-theme', '', false],
    default => throw new RuntimeException('Unknown theme mode'),
};
$hooks = []; $checks = 0;
function get_template() { global $theme; return $theme[0]; }
function get_stylesheet() { global $theme; return $theme[1]; }
function wp_get_theme($template) {
    global $theme;
    if ($template !== $theme[0]) { throw new RuntimeException('Must inspect the active parent, not an installed theme'); }
    return new class($theme[2]) {
        public function __construct(private string $name) {}
        public function exists() { return $this->name !== ''; }
        public function get($key) { return $key === 'Name' ? $this->name : ''; }
    };
}
function add_filter($name, $callback, $priority = 10, $args = 1) { global $hooks; $hooks[$name][$priority][] = $callback; }
function add_action($name, $callback, $priority = 10, $args = 1) { add_filter($name, $callback, $priority, $args); }
function apply_filters($name, $value) {
    global $hooks; $callbacks = $hooks[$name] ?? []; ksort($callbacks);
    foreach ($callbacks as $group) { foreach ($group as $callback) { $value = $callback($value); } }
    return $value;
}
function __($text, $domain = '') { return $text; }
function esc_html__($text, $domain = '') { return $text; }
function update_option(...$args) { throw new RuntimeException('Registration must not write settings'); }
function wp_enqueue_script(...$args) { throw new RuntimeException('Disabled runtime must not load scripts'); }
function wp_enqueue_style(...$args) { throw new RuntimeException('Disabled runtime must not load styles'); }
function check($label, $pass) { global $checks; ++$checks; if (!$pass) { throw new RuntimeException($label); } }

$root = dirname(__DIR__, 2);
require $root . '/wp-content/mu-plugins/dsa/includes/Bricks/Surface_Style_Controls.php';
require $root . '/wp-content/mu-plugins/dsa/includes/Bricks/Bricks_Integration.php';
use DSA\Bricks\Bricks_Integration as Integration;
use DSA\Bricks\Surface_Style_Controls as Controls;

$settings = new class {
    public array $data = [
        'enabled' => false, 'secure' => ['enabled' => false], 'phonekey' => ['enabled' => false],
        'site_graph' => ['enabled' => true],
        'bricks' => [
            'add_to_cart_enhancer_enabled' => false, 'mini_cart_adapter_enabled' => false,
            'quantity_stepper_enabled' => false, 'stock_badge_enabled' => false,
            'linked_products_controls_enabled' => false, 'dsa_icon_launcher_enabled' => false,
            'dynamic_tags_enabled' => false,
        ],
    ];
    public function all() { return $this->data; }
    public function get($key, $default = null) { return $this->data[$key] ?? $default; }
};
$before = $settings->all();
$reflection = new ReflectionClass(Integration::class);
$integration = $reflection->newInstanceWithoutConstructor();
$reflection->getProperty('settings')->setValue($integration, $settings);
check('Active parent theme detection', Integration::is_active_theme() === $theme[3]);
check('No capabilities before registration', Controls::capabilities(true) === []);
$integration->register();
$registered = $hooks;
$integration->register();
check('Registration is idempotent', $hooks === $registered);
check('No settings changes during registration', $before === $settings->all());

if (!$theme[3]) {
    check('No Bricks hooks for an inactive/absent theme', $hooks === []);
    check('No advertised controls for an inactive theme', Controls::capabilities(true) === []);
    $settings->data['bricks'] = array_fill_keys(array_keys($settings->data['bricks']), true);
    $integration->register();
    check('Saved enabled flags cannot activate Bricks on another theme', $hooks === []);
} else {
    $total = 0;
    foreach (Controls::capabilities(true) as $element => $keys) {
        $controls = apply_filters('bricks/elements/' . $element . '/controls', ['original' => ['sentinel' => true]]);
        $groups = apply_filters('bricks/elements/' . $element . '/control_groups', []);
        check('Native controls preserved', $controls['original'] === ['sentinel' => true]);
        foreach ($keys as $key) {
            ++$total;
            check('Advertised control exists: ' . $key, isset($controls[$key]));
            check('Advertised styles have no default: ' . $key, !array_key_exists('default', $controls[$key]));
            check('Style group resolves: ' . $key, ($groups[$controls[$key]['group']]['tab'] ?? '') === 'style');
        }
    }
    check('All 38 catalog additions plus 15 existing ATC styles available', $total === 53);
    $mini = apply_filters('bricks/elements/woocommerce-mini-cart/controls', []);
    check('Mini cart editor includes quantity, stock and linked controls', isset($mini['brxMcStepperEnable'], $mini['brxMcBadgeEnable'], $mini['kiweMiniCartRuntimeInfo']));
    check('Linked product controls available', isset(apply_filters('bricks/elements/product-upsells/controls', [])['brxKiweLinkedProductsMode']));
    check('DSA launcher control available without enabling dock', isset(apply_filters('bricks/elements/icon/controls', [])['dsaOpenModule']));
    check('Contact action controls available', isset(apply_filters('bricks/elements/button/controls', [])['kiweContactAction']));
    check('Search bridge control available', isset(apply_filters('bricks/elements/filter-search/controls', [])['dsaSearchBridge']));
    check('Dynamic data switch remains respected', $integration->add_dynamic_tags(['sentinel']) === ['sentinel']);
    $runtime = $reflection->getMethod('add_to_cart_runtime_enabled');
    check('Element behaviour cannot bypass disabled runtime', !$runtime->invoke($integration, ['brxPlusOnly' => true]));
    check('Disabled mini-cart retains native output', $integration->render_mini_cart_quantity('native', [], 'key') === 'native');
    check('Disabled mini-cart settings are not persisted', $integration->bridge_element_settings(['brxMcStepperEnable' => true], (object) ['name' => 'woocommerce-mini-cart']) === ['brxMcStepperEnable' => true]);
    check('Disabled linked behaviour preserves the native query', $integration->bridge_element_settings(['type' => 'upsells', 'brxKiweLinkedProductsMode' => 'cross_sells'], (object) ['name' => 'product-upsells'])['type'] === 'upsells');
    check('Disabled ATC behaviour leaves attributes untouched', $integration->add_add_to_cart_attributes(['sentinel' => true], (object) ['name' => 'product-add-to-cart']) === ['sentinel' => true]);
    $integration->enqueue_mini_cart_adapter();
    check('No settings changes while rendering controls', $before === $settings->all());
    $settings->data['bricks']['add_to_cart_enhancer_enabled'] = true;
    check('Enabling runtime alone does not alter every element', !$runtime->invoke($integration, []));
    check('Explicit element opt-in still works', $runtime->invoke($integration, ['brxPlusOnly' => true]));
}

$bootstrap = file_get_contents($root . '/wp-content/mu-plugins/dsa/includes/Plugin.php');
check('Bootstrap waits for theme setup', str_contains($bootstrap, "add_action( 'after_setup_theme', [ \$bricks_integration, 'register' ], 20 )"));
check('Bootstrap is not gated by saved Bricks settings', !str_contains($bootstrap, '$bricks_enabled'));
$graph = file_get_contents($root . '/wp-content/mu-plugins/dsa/includes/AI/Site_Graph_Service.php');
check('Calibration uses active-theme capability, not behaviour switch', str_contains($graph, "'surfaceStyleControls' => \\DSA\\Bricks\\Bricks_Integration::is_active_theme() ? \\DSA\\Bricks\\Surface_Style_Controls::capabilities( true ) : []"));
echo json_encode(['pass' => true, 'mode' => $mode, 'checks' => $checks]) . PHP_EOL;
