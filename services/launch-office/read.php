<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/inquiries.php';

$kind = $argv[1] ?? 'all';
$limit = filter_var($argv[2] ?? 20, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 50]]);
if (($kind !== 'all' && !in_array($kind, KIWE_LAUNCH_KINDS, true)) || $limit === false) {
    fwrite(STDERR, "Use a kind of all, demo, or partner and a limit from 1 to 50.\n");
    exit(1);
}

try {
    $office = getenv('KIWE_LAUNCH_OFFICE') ?: __DIR__;
    echo json_encode(kiwe_launch_list_inquiries($office, $kind === 'all' ? null : $kind, $limit), JSON_THROW_ON_ERROR), PHP_EOL;
} catch (Throwable) {
    fwrite(STDERR, "The Kiwe Launch inbox could not be read.\n");
    exit(1);
}
