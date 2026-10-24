<?php
/** Offline audit of schema-only target observations. Never opens a database. */
declare(strict_types=1);
define('ABSPATH', __DIR__ . '/');
foreach (['Archive_Error', 'Portable_Table_Definition', 'Portable_Store_Recovery'] as $class) {
    require_once __DIR__ . '/../../wp-content/mu-plugins/dsa/includes/Backup/' . $class . '.php';
}
use DSA\Backup\Portable_Table_Definition as Definition;
use DSA\Backup\Portable_Store_Recovery as Store;

if (count($argv) !== 4 || !preg_match('/^[a-zA-Z0-9_]{1,32}$/D', $argv[3])) {
    fwrite(STDERR, "Usage: php audit-store-target-schema.php input.json new-receipt.json table_prefix\n");
    exit(2);
}
try {
    if (is_file($argv[2])) throw new RuntimeException('Receipt already exists.');
    $raw = file_get_contents($argv[1]);
    if ($raw === false || strlen($raw) > 2097152) throw new RuntimeException('Invalid bounded input.');
    $input = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
    if (($input['schema'] ?? null) !== 1 || !is_array($input['tables'] ?? null)
        || !array_is_list($input['tables']) || count($input['tables']) < 1 || count($input['tables']) > 512) {
        throw new RuntimeException('Invalid schema-only observation.');
    }
    $proof = [
        'schema' => 'kiwe.store-target-schema-audit.v1', 'recordedAt' => gmdate('c'),
        'passed' => false, 'nativeSqlExecuted' => false, 'dataRowsAudited' => false,
        'actualSourceRestoreAccepted' => false, 'targetCompatibilityAccepted' => false,
        'inputSha256' => hash('sha256', $raw), 'inputBytes' => strlen($raw),
        'sourceExportSha256' => $input['sourceSha256'] ?? null,
        'normalization' => $input['normalization'] ?? null,
        'tablePrefix' => $argv[3], 'sourceHashes' => [], 'tables' => [],
    ];
    foreach (['Portable_Table_Definition', 'Portable_Store_Recovery'] as $class) {
        $proof['sourceHashes'][$class] = hash_file('sha256', __DIR__ . '/../../wp-content/mu-plugins/dsa/includes/Backup/' . $class . '.php');
    }
    $seen = [];
    foreach ($input['tables'] as $table) {
        $name = $table['name'] ?? null;
        $sql = $table['sql'] ?? null;
        if (!is_string($name) || !str_starts_with($name, $argv[3]) || isset($seen[$name])
            || !preg_match('/^[a-zA-Z0-9_]{1,64}$/D', $name) || !is_string($sql) || strlen($sql) > 65536) {
            throw new RuntimeException('Invalid or repeated table observation.');
        }
        $seen[$name] = true;
        $suffix = substr($name, strlen($argv[3]));
        $row = ['table' => $name, 'suffix' => $suffix, 'sqlSha256' => hash('sha256', $sql),
            'grammarSupported' => false, 'storeDefinitionDeclared' => isset(Store::definitions()[$suffix]),
            'storeDefinitionValidated' => null];
        try {
            $ast = Definition::parse($sql, $name);
            $row['grammarSupported'] = true;
            $row['columns'] = array_column($ast['columns'], 'name');
            $primary = array_values(array_filter($ast['indexes'], static fn(array $i): bool => $i['kind'] === 'primary'));
            $row['primary'] = array_column($primary[0]['columns'], 'name');
            $row['indexes'] = $ast['indexes'];
            $row['engine'] = $ast['engine'];
            $row['astSha256'] = hash('sha256', json_encode($ast, JSON_THROW_ON_ERROR));
            if ($row['storeDefinitionDeclared']) {
                // Zero is a metadata-only descriptor; no source row-count claim is made.
                Store::definition($suffix, ['name' => $name, 'columns' => $row['columns'], 'primary' => $row['primary'], 'rows' => 0], $sql);
                $row['storeDefinitionValidated'] = true;
            }
        } catch (DSA\Backup\Archive_Error $error) {
            $row['refusal'] = $row['grammarSupported'] ? 'Store definition differs from the closed native contract.' : 'Definition is outside the recovery grammar.';
            if ($row['storeDefinitionDeclared']) $row['storeDefinitionValidated'] = false;
        }
        $proof['tables'][] = $row;
    }
    $proof['tableCount'] = count($proof['tables']);
    $proof['grammarSupportedTableCount'] = count(array_filter($proof['tables'], static fn(array $t): bool => $t['grammarSupported']));
    $proof['declaredStoreTableCount'] = count(array_filter($proof['tables'], static fn(array $t): bool => $t['storeDefinitionDeclared']));
    $proof['validatedStoreTableCount'] = count(array_filter($proof['tables'], static fn(array $t): bool => $t['storeDefinitionValidated'] === true));
    $proof['passed'] = $proof['grammarSupportedTableCount'] === $proof['tableCount']
        && $proof['declaredStoreTableCount'] > 0 && $proof['validatedStoreTableCount'] === $proof['declaredStoreTableCount'];
    $out = fopen($argv[2], 'x');
    if ($out === false) throw new RuntimeException('Cannot create new receipt.');
    try { fwrite($out, json_encode($proof, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n"); }
    finally { fclose($out); }
    echo json_encode(array_intersect_key($proof, array_flip(['passed', 'tableCount', 'grammarSupportedTableCount', 'declaredStoreTableCount', 'validatedStoreTableCount'])), JSON_THROW_ON_ERROR) . "\n";
    exit($proof['passed'] ? 0 : 1);
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(2);
}
