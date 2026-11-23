<?php
declare(strict_types=1);

use DSA\Access\Dock_Workspace_Service;
use DSA\Apps\Migration_Service;
use DSA\Apps\Native_State;
use DSA\Apps\Package_Layout;
use DSA\Backup\Portable_Archive_Cipher;
use DSA\Backup\Portable_Database_Capture;
use DSA\Runtime\Private_Storage;
use DSA\Runtime\Update_Service;
use DSA\Settings;

if (!defined('WP_CLI') || !WP_CLI || !defined('ABSPATH')) {
    exit(2);
}

function kiwe_fleet_state(string $work, ?array $values = null): array {
    $record = $work . '/checkpoint.json';
    if ($values === null) {
        return is_file($record) ? json_decode(file_get_contents($record), true, 32, JSON_THROW_ON_ERROR) : [];
    }
    $json = wp_json_encode($values, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    if (file_put_contents($record . '.next', $json, LOCK_EX) !== strlen($json) || !rename($record . '.next', $record)) {
        throw new RuntimeException('The recovery checkpoint could not be recorded.');
    }
    return $values;
}

function kiwe_fleet_dock(): bool {
    return !empty((new Settings())->get('dock', [])['reveal_on_scroll']);
}

function kiwe_fleet_dock_change(bool $requested): void {
    if (kiwe_fleet_dock() === $requested) {
        return;
    }
    $dock = new Dock_Workspace_Service();
    $result = $dock->apply_profile([
        'expected' => $dock->snapshot()['expected'],
        'profile' => ['schema' => 'kiwe.dock-profile.v1', 'choices' => ['dock' => ['reveal_on_scroll' => $requested]]],
    ]);
    if (is_wp_error($result) || kiwe_fleet_dock() !== $requested) {
        throw new RuntimeException('The Kiwe Dock setting was not confirmed.');
    }
}

function kiwe_fleet_recovery(string $root, string $work, string $choice): array {
    global $wpdb;
    if (kiwe_fleet_state($work)) {
        throw new RuntimeException('A recovery checkpoint already exists for this run.');
    }
    $passphrase = rtrim(stream_get_contents(STDIN, 2048), "\r\n");
    $cipher = new Portable_Archive_Cipher(Portable_Archive_Cipher::new_header(), $passphrase);
    umask(0077);
    $database = $work . '/database.kiwedb';
    $stream = fopen($database, 'x+b');
    if ($stream === false) {
        throw new RuntimeException('The private database backup could not be opened.');
    }
    try {
        $capture = Portable_Database_Capture::capture($wpdb, $stream, $cipher);
        rewind($stream);
        $verified = Portable_Database_Capture::verify($stream, $passphrase);
    } finally {
        fclose($stream);
    }
    unset($passphrase);
    if ($verified['archiveId'] !== $capture['archiveId'] || $verified['rows'] !== $capture['rows']
        || count($verified['tables']) !== $capture['tables'] || !hash_equals($capture['sha256'], hash_file('sha256', $database))) {
        throw new RuntimeException('The database backup failed read-back verification.');
    }

    require_once ABSPATH . 'wp-admin/includes/file.php';
    $backup = $root . '/kiwe-backup-' . wp_generate_uuid4();
    if (get_filesystem_method() !== 'direct' || !WP_Filesystem() || !wp_mkdir_p($backup . '/dsa')) {
        throw new RuntimeException('The previous Core package could not be backed up.');
    }
    foreach (['dsa.php', 'kiwe-incident-guard.php'] as $name) {
        if (!copy(WPMU_PLUGIN_DIR . '/' . $name, $backup . '/' . $name)
            || hash_file('sha256', WPMU_PLUGIN_DIR . '/' . $name) !== hash_file('sha256', $backup . '/' . $name)) {
            throw new RuntimeException('An original Kiwe entry file failed backup verification.');
        }
    }
    if (copy_dir(WPMU_PLUGIN_DIR . '/dsa', $backup . '/dsa') !== true
        || hash_file('sha256', WPMU_PLUGIN_DIR . '/dsa/package-manifest.json') !== hash_file('sha256', $backup . '/dsa/package-manifest.json')) {
        throw new RuntimeException('The original Kiwe package failed backup verification.');
    }
    return kiwe_fleet_state($work, [
        'current' => DSA_VERSION, 'backup' => wp_normalize_path($backup), 'databaseSha256' => $capture['sha256'],
        'choice' => $choice, 'dock' => kiwe_fleet_dock(), 'updateState' => get_option(Update_Service::STATE_OPTION, null),
    ]);
}

try {
    [$step, $work, $domain, $choice] = $args;
    $root = wp_normalize_path(Private_Storage::directory('releases'));
    if (!$root || wp_normalize_path((string) realpath($work)) !== $work || dirname($work) !== $root
        || !preg_match('/^core-fleet-[a-f0-9-]{36}$/D', basename($work))
        || wp_parse_url(home_url(), PHP_URL_HOST) !== $domain || is_multisite() || !defined('DSA_VERSION')
        || Package_Layout::BUNDLED
        || !in_array($choice, ['keep', 'on', 'off'], true)) {
        throw new RuntimeException('The destination is not a qualified independent Kiwe Core installation.');
    }
    if ($choice !== 'keep' && !Dock_Workspace_Service::allowed()) {
        throw new RuntimeException('The configured WordPress operator cannot change the Dock setting.');
    }

    $release = json_decode(file_get_contents($work . '/manifest.json'), true, 32, JSON_THROW_ON_ERROR);
    $archive = $work . '/kiwe-mu-plugin.zip';
    if (!is_file($archive) || !hash_equals($release['sha256'], hash_file('sha256', $archive))) {
        throw new RuntimeException('The transferred archive does not match the signed package.');
    }
    $zip = new ZipArchive();
    if ($zip->open($archive, ZipArchive::RDONLY) !== true) {
        throw new RuntimeException('The signed archive could not be read.');
    }
    $manifest = $zip->getFromName('dsa/package-manifest.json');
    $zip->close();
    if (!is_string($manifest) || !hash_equals($release['packageManifestSha256'], hash('sha256', $manifest))) {
        throw new RuntimeException('The signed Core profile does not match the package.');
    }
    $profile = json_decode($manifest, true, 64, JSON_THROW_ON_ERROR);
    if (($profile['apps']['profile'] ?? '') !== 'core' || $profile['version'] !== $release['version']) {
        throw new RuntimeException('A signed independent Core profile is required.');
    }
    if ($step === 'inspect') {
        Migration_Service::assert_core_profile($profile);
        $current = DSA_VERSION;
        if (version_compare($current, $release['version'], '>')
            || version_compare(PHP_VERSION, $release['requiresPhp'], '<')
            || version_compare(get_bloginfo('version'), $release['requiresWp'], '<')
            || ($current === $release['version'] && hash_file('sha256', WPMU_PLUGIN_DIR . '/dsa/package-manifest.json') !== $release['packageManifestSha256'])) {
            throw new RuntimeException('The current Core version, package identity, PHP or WordPress cannot accept this release.');
        }
        $result = [
            'domain' => $domain, 'current' => $current, 'target' => $release['version'], 'ready' => true,
            'settingsChange' => $choice !== 'keep' && kiwe_fleet_dock() !== ($choice === 'on'),
            'apps' => array_filter(Native_State::snapshot()['apps'], static fn(array $app) => $app['active']),
        ];
    } elseif ($step === 'purge-local') {
        do_action('litespeed_purge_all');
        $result = ['pageCachePurgeSent' => true];
    } elseif ($step === 'backup') {
        $checkpoint = kiwe_fleet_recovery($root, $work, $choice);
        $result = ['current' => $checkpoint['current'], 'ready' => true];
    } else {
        $checkpoint = kiwe_fleet_state($work);
        if (empty($checkpoint['current']) || $checkpoint['choice'] !== $choice) {
            throw new RuntimeException('A matching recovery checkpoint is required.');
        }
        if ($step === 'apply') {
            if (DSA_VERSION !== $checkpoint['current'] || !hash_equals($checkpoint['databaseSha256'], hash_file('sha256', $work . '/database.kiwedb'))) {
                throw new RuntimeException('The live package changed or the database backup was not verified.');
            }
            add_filter('pre_option_' . Update_Service::SETTINGS_OPTION,
                static fn() => ['channel' => $release['channel'], 'automatic' => false]);
            add_filter('pre_http_request', static function ($result, array $request, string $url) use ($archive, $release) {
                if ($url !== $release['packageUrl']) {
                    return $result;
                }
                if (empty($request['filename']) || !copy($archive, $request['filename'])) {
                    return new WP_Error('kiwe_fleet_archive', 'The verified private archive could not be supplied to the updater.');
                }
                return ['headers' => [], 'body' => '', 'response' => ['code' => 200, 'message' => 'OK'],
                    'filename' => $request['filename'], 'cookies' => []];
            }, 10, 3);
            $result = (new Update_Service())->install($release);
        } elseif ($step === 'settings') {
            if (DSA_VERSION !== $release['version'] || $choice === 'keep') {
                throw new RuntimeException('The selected release must boot before changing its Dock setting.');
            }
            kiwe_fleet_dock_change($choice === 'on');
            $result = ['dock' => kiwe_fleet_dock()];
        } elseif ($step === 'rollback') {
            if ($choice !== 'keep') {
                kiwe_fleet_dock_change($checkpoint['dock']);
            }
            if (DSA_VERSION !== $checkpoint['current']
                && !kiwe_mu_rollback_update(['backup' => $checkpoint['backup'], 'oldVersion' => $checkpoint['current']])) {
                throw new RuntimeException('The previous Kiwe package could not be restored.');
            }
            if ($checkpoint['updateState'] === null) {
                delete_option(Update_Service::STATE_OPTION);
            } else {
                update_option(Update_Service::STATE_OPTION, $checkpoint['updateState'], false);
            }
            $result = ['restored' => $checkpoint['current']];
        } else {
            throw new RuntimeException('Unknown fleet release operation.');
        }
    }
    echo wp_json_encode($result, JSON_THROW_ON_ERROR) . PHP_EOL;
} catch (Throwable $error) {
    echo wp_json_encode(['error' => $error->getMessage()]) . PHP_EOL;
    exit(1);
}
