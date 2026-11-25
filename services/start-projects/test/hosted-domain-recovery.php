<?php

declare(strict_types=1);

// Run only in a private Hostinger fixture. The recovery factory allocates the WordPress roots and tables.
if (PHP_SAPI !== 'cli' || PHP_OS_FAMILY !== 'Linux'
    || !preg_match('~^/home/[^/]+/\.kiwe-owned-domain-qa\.[A-Za-z0-9]+$~D', getenv('KIWE_DOMAIN_FIXTURE') ?: '')) {
    exit(2);
}
$kiweFixture = getenv('KIWE_DOMAIN_FIXTURE');
$kiweWorkspace = dirname(__DIR__, 3);
if (realpath($kiweFixture) !== $kiweFixture || $kiweWorkspace !== $kiweFixture || (fileperms($kiweFixture) & 0077)) {
    exit(2);
}
define('ABSPATH', $kiweFixture . '/');

$backup = $kiweFixture . '/package/dsa/includes/Backup/';
foreach ([
    'Archive_Error', 'Archive_Cipher', 'Portable_Archive_Cipher', 'Portable_File_Inventory',
    'Portable_Archive_Container', 'Portable_Database_Capture', 'Portable_Source_Compatibility',
    'Portable_Source_Observation', 'Portable_Recovery_Set', 'Portable_Recovery_Stage', 'Portable_Table_Definition',
    'Portable_Recovery_Migration', 'Portable_Recovery_Ownership', 'Portable_Recovery_Database',
    'Portable_Recovery_Configuration', 'Portable_Recovery_Activation',
] as $class) {
    require $backup . $class . '.php';
}
$run = require $kiweWorkspace . '/tools/release/test-portable-roundtrip-native.php';
$contract = json_decode(file_get_contents($kiweFixture . '/verified-release.json'), true, flags: JSON_THROW_ON_ERROR);
$release = $contract['directory'];
$connection = DSA\Backup\Portable_Recovery_Database::connection_file($kiweFixture . '/connection.json');
$key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
$details = $key ? openssl_pkey_get_details($key) : false;
if (!$details) exit(2);

$config = [
    'connection' => $connection,
    'worker' => $kiweWorkspace . '/tools/release/fixtures/portable-roundtrip-wordpress.php',
    'installedRoot' => dirname($kiweFixture) . '/domains/plum-giraffe-539524.hostingersite.com/public_html',
    'bricksRoot' => $kiweFixture . '/theme/bricks',
    'package' => $release . '/kiwe-mu-plugin.zip',
    'packageSha256' => $contract['sha256'],
    'sourceVersion' => $contract['version'],
    'publicKey' => $details['key'],
    'downloadUrl' => 'https://backup.qualification.invalid/',
    'uploadedRoot' => $kiweFixture . '/verified-backup',
];

function checksPassed(array $proof): int
{
    $count = count(array_filter($proof['checks'] ?? []));
    foreach (['sourceInstallation', 'source', 'destination', 'capture', 'nativeBootstrap'] as $child) {
        if (isset($proof[$child]['checks'])) $count += checksPassed($proof[$child]);
    }
    return $count;
}

$captured = $run($config, 'capture');
if (!$captured['proof']['passed']) {
    $proof = $captured['proof'];
    echo json_encode(['passed' => false, 'phase' => 'capture', 'checks' => checksPassed($proof),
        'error' => $proof['error'] ?? 'capture did not complete', 'child' => $proof['failedChild'] ?? null,
        'cleanup' => $proof['cleanup']]), PHP_EOL;
    exit(1);
}
$state = $captured['state'];
$restore = null;
$copyFailure = null;
try {
    $encoded = $captured['proof']['offline']['encryptedCredentials'];
    if (!openssl_private_decrypt(base64_decode($encoded), $data, $key, OPENSSL_PKCS1_OAEP_PADDING)
        || json_decode($data, true, flags: JSON_THROW_ON_ERROR)['phrase'] !== $state['phrase']) {
        throw new RuntimeException('The local recovery credentials did not authenticate.');
    }
    mkdir($config['uploadedRoot'], 0700);
    foreach ($captured['proof']['offline']['members'] as $name => $member) {
        $copy = $config['uploadedRoot'] . '/' . $name;
        if (!copy($state['root'] . '/capture/' . $name, $copy) || hash_file('sha256', $copy) !== $member['sha256']) {
            throw new RuntimeException('The staged recovery pair did not match its capture.');
        }
    }
    set_error_handler(static function (int $severity, string $message, string $file) use (&$copyFailure): bool {
        if ($severity === E_WARNING && basename($file) === 'Portable_Recovery_Activation.php'
            && preg_match('~^fopen\([^)]*/next/([a-zA-Z0-9._/-]+)\): Failed to open stream: ([a-zA-Z ]+)$~D', $message, $match)) {
            $copyFailure = ['fixtureFile' => $match[1], 'reason' => $match[2]];
        }
        return false;
    });
    try {
        $restore = $run($config, 'restore', $state)['proof'];
    } finally {
        restore_error_handler();
    }
} finally {
    if ($restore === null) $run($config, 'retire', $state);
}
echo json_encode([
    'passed' => $restore['passed'],
    'cleanup' => $restore['cleanup'],
    'captureChecks' => checksPassed($captured['proof']),
    'restoreChecks' => checksPassed($restore),
    'wordpress' => $state['capture']['wordpress'],
    'bricks' => $state['capture']['bricks'],
    'kiwe' => $contract['version'],
    'files' => $state['capture']['files'],
    'archiveBytes' => $state['capture']['bytes'],
    'error' => $restore['error'] ?? null,
    'failureSite' => $restore['failureSite'] ?? null,
    'copyFailure' => $copyFailure,
    'child' => $restore['failedChild'] ?? null,
], JSON_THROW_ON_ERROR), PHP_EOL;
exit($restore['passed'] && $restore['cleanup'] ? 0 : 1);
