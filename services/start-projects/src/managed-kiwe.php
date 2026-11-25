<?php

if (!defined('WP_CLI') || !WP_CLI || untrailingslashit(home_url()) !== getenv('KIWE_MANAGED_SITE_ORIGIN') || is_multisite()) {
    exit(1);
}

function kiwe_managed_core(string $directory, array $release): void
{
    $archive = $directory . '/kiwe-mu-plugin.zip';
    if (!hash_equals($release['sha256'], getenv('KIWE_MANAGED_CORE_HASH') ?: '')
        || !hash_equals($release['sha256'], hash_file('sha256', $archive))
        || version_compare(PHP_VERSION, $release['requiresPhp'], '<')
        || version_compare(get_bloginfo('version'), $release['requiresWp'], '<')) {
        throw new RuntimeException('The approved Core package does not match this WordPress runtime.');
    }
    $manifestFile = 'dsa/package-manifest.json';
    $zip = new ZipArchive();
    if ($zip->open($archive) !== true) throw new RuntimeException('The signed Core archive could not be opened.');
    $stage = null;
    try {
        $manifest = $zip->getFromName($manifestFile);
        if (!is_string($manifest) || !hash_equals($release['packageManifestSha256'], hash('sha256', $manifest))) {
            throw new RuntimeException('The Core file inventory does not match the release.');
        }
        $inventory = json_decode($manifest, true, flags: JSON_THROW_ON_ERROR);
        $files = $inventory['files'] ?? [];
        if ($inventory['version'] !== $release['version'] || count($files) + 3 !== $zip->numFiles || $zip->numFiles > 2000) {
            throw new RuntimeException('The signed Core archive has an invalid inventory.');
        }
        $members = [];
        $bytes = 0;
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $entry = $zip->statIndex($index);
            $name = $entry['name'] ?? '';
            $size = $entry['size'] ?? -1;
            $os = $attributes = 0;
            $zip->getExternalAttributesIndex($index, $os, $attributes);
            $kind = ($attributes >> 16) & 0170000;
            if (isset($members[$name]) || $size < 0 || $size > 8388608 || !empty($entry['encryption_method'])
                || !preg_match('~^(?:[A-Za-z0-9_.-]+/)*[A-Za-z0-9_.-]+$~D', $name)
                || array_intersect(explode('/', $name), ['.', '..']) || ($os === ZipArchive::OPSYS_UNIX && !in_array($kind, [0, 0100000], true))
                || (!in_array($name, ['dsa.php', 'kiwe-incident-guard.php', $manifestFile], true)
                    && (!str_starts_with($name, 'dsa/') || ($files[substr($name, 4)]['bytes'] ?? null) !== $size))) {
                throw new RuntimeException('The Core archive contains an unsupported member.');
            }
            $bytes += $size;
            if ($bytes > 134217728) throw new RuntimeException('The Core archive exceeds its extraction limit.');
            $members[$name] = $index;
        }
        foreach (['dsa.php', 'kiwe-incident-guard.php', $manifestFile] as $name) {
            if (!array_key_exists($name, $members)) throw new RuntimeException('The signed Core loader is incomplete.');
        }
        if (!is_dir(WPMU_PLUGIN_DIR) && !wp_mkdir_p(WPMU_PLUGIN_DIR)) throw new RuntimeException('The MU plugin directory is unavailable.');
        if (is_link(WPMU_PLUGIN_DIR) || realpath(WPMU_PLUGIN_DIR) !== WPMU_PLUGIN_DIR) throw new RuntimeException('The MU plugin directory is not the assigned WordPress root.');
        $stage = dirname(untrailingslashit(ABSPATH)) . '/.kiwe-core-' . bin2hex(random_bytes(10));
        if (!mkdir($stage, 0700) || !$zip->extractTo($stage)) throw new RuntimeException('Core could not be prepared in the private site directory.');
        foreach ($members as $name => $index) {
            $proof = $name !== $manifestFile && str_starts_with($name, 'dsa/') ? $files[substr($name, 4)]['sha256'] : null;
            if ($proof !== null && !hash_equals($proof, hash_file('sha256', $stage . '/' . $name))) {
                throw new RuntimeException('A Core file differs from the signed inventory.');
            }
        }
        foreach (['dsa', 'kiwe-incident-guard.php', 'dsa.php'] as $entry) {
            $target = WPMU_PLUGIN_DIR . '/' . $entry;
            if (!file_exists($target) && !is_link($target)) continue;
            if ($entry === 'dsa') {
                if (is_link($target) || !is_dir($target)) throw new RuntimeException('The site contains different Kiwe files.');
                foreach ($members as $name => $index) {
                    if (!str_starts_with($name, 'dsa/')) continue;
                    $saved = WPMU_PLUGIN_DIR . '/' . $name;
                    if (!is_file($saved) || realpath($saved) !== $saved
                        || !hash_equals(hash_file('sha256', $stage . '/' . $name), hash_file('sha256', $saved))) {
                        throw new RuntimeException('The site contains different Kiwe files.');
                    }
                }
            } elseif (!is_file($target) || is_link($target)
                || !hash_equals(hash_file('sha256', $stage . '/' . $entry), hash_file('sha256', $target))) {
                throw new RuntimeException('The site contains a different Kiwe loader.');
            }
        }
        foreach (['dsa', 'kiwe-incident-guard.php', 'dsa.php'] as $name) {
            $target = WPMU_PLUGIN_DIR . '/' . $name;
            if (!file_exists($target) && !rename($stage . '/' . $name, $target)) {
                throw new RuntimeException('The managed Core files could not be placed.');
            }
        }
    } finally {
        $zip->close();
        if ($stage !== null && is_dir($stage)) {
            $entries = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($stage, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($entries as $entry) {
                if ($entry->isDir()) rmdir($entry->getPathname());
                else unlink($entry->getPathname());
            }
            rmdir($stage);
        }
    }
}

function kiwe_managed_mcp(string $directory, array $release): void
{
    $app = json_decode(file_get_contents($directory . '/release.json'), true, flags: JSON_THROW_ON_ERROR);
    if ($app['id'] !== 'mcp' || !defined('DSA_VERSION') || DSA_VERSION !== $release['version']
        || empty(\DSA\Runtime\Package_Manifest::verify(true)['complete'])) {
        throw new RuntimeException('The verified managed Core must boot before the MCP App.');
    }
    $archive = $directory . '/kiwe-mcp.zip';
    \DSA\Apps\Package_Verifier::archive($archive, $app);
    $target = WP_PLUGIN_DIR . '/kiwe-mcp';
    require_once ABSPATH . 'wp-admin/includes/plugin.php';
    if (!is_dir($target)) {
        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/misc.php';
        if (is_link($target)) throw new RuntimeException('The MCP App target is not a native plugin directory.');
        $upgrader = new Plugin_Upgrader(new Automatic_Upgrader_Skin());
        $installed = $upgrader->install($archive);
        if (is_wp_error($installed) || !$installed) throw new RuntimeException('WordPress could not install the signed MCP App.');
    }
    \DSA\Apps\Package_Verifier::directory($target, $app);
    $result = activate_plugin('kiwe-mcp/kiwe-mcp.php');
    if (is_wp_error($result)) throw new RuntimeException('WordPress could not activate the MCP App.');
}

$directory = getenv('KIWE_MANAGED_RELEASE_DIR') ?: '';
$release = json_decode(file_get_contents($directory . '/manifest.json'), true, flags: JSON_THROW_ON_ERROR);
$lock = fopen(dirname(untrailingslashit(ABSPATH)) . '/.kiwe-managed-release.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) WP_CLI::error('The signed stack is being prepared. Check again to resume.');
try {
    switch ($args[0] ?? '') {
        case 'core':
            kiwe_managed_core($directory, $release);
            echo 'core ready';
            break;
        case 'mcp':
            kiwe_managed_mcp($directory, $release);
            echo 'mcp ready';
            break;
        case 'boot':
            if (!\DSA\Apps\MCP_App_Service::enabled()) {
                $owner = \DSA\Access\Site_Owner_Service::owner_id();
                if (!$owner) {
                    $admins = get_users(['role' => 'administrator', 'fields' => 'ID', 'number' => 2]);
                    if (count($admins) !== 1) throw new RuntimeException('Resolve the native owner before enabling MCP.');
                    $owner = (int) $admins[0];
                }
                wp_set_current_user($owner);
                $changed = \DSA\Apps\MCP_App_Service::change(true, \DSA\Apps\MCP_App_Service::expected());
                if (is_wp_error($changed)) throw new RuntimeException('The native Kiwe owner could not enable MCP.');
            }
            if (!\DSA\Apps\MCP_App_Service::enabled()) throw new RuntimeException('Kiwe MCP did not finish its native setup.');
            echo 'kiwe ready';
            break;
        default:
            throw new RuntimeException('Choose a managed release step.');
    }
} catch (RuntimeException $error) {
    WP_CLI::error($error->getMessage());
} finally {
    fclose($lock);
}
