<?php
declare(strict_types=1);

function kiwe_fleet_core_files(string $root): array {
    $files = ['dsa.php', 'kiwe-incident-guard.php'];
    $core = $root . '/dsa';
    if (is_link($core) || !is_dir($core)) {
        throw new RuntimeException('The Core folder is missing or linked.');
    }
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($core, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if ($file->isLink() || !$file->isFile()) {
            throw new RuntimeException('The Core folder contains an unsupported file.');
        }
        $files[] = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
    }
    sort($files, SORT_STRING);
    $hashes = [];
    foreach ($files as $file) {
        $path = $root . '/' . $file;
        if (is_link($path) || !is_file($path) || ($hash = hash_file('sha256', $path)) === false) {
            throw new RuntimeException('A Core file could not be verified.');
        }
        $hashes[$file] = $hash;
    }
    return $hashes;
}

function kiwe_fleet_fingerprint(string $root): string {
    return hash('sha256', json_encode(kiwe_fleet_core_files($root), JSON_THROW_ON_ERROR));
}

function kiwe_fleet_restore_files(string $work, string $wordpressRoot): bool {
    $work = realpath($work) ?: '';
    $private = dirname($work);
    if (!preg_match('~[/\\\\]kiwe-private-releases[/\\\\]site-[a-f0-9]{16}[/\\\\]core-fleet-[a-f0-9-]{36}$~D', $work)) {
        throw new RuntimeException('The private recovery checkpoint path is invalid.');
    }
    $record = json_decode(file_get_contents($work . '/checkpoint.json'), true, 32, JSON_THROW_ON_ERROR);
    $root = realpath($wordpressRoot);
    $mu = $record['muRoot'] ?? '';
    $fingerprint = $record['fingerprint'] ?? '';
    if (!$root || $root !== $record['wordpressRoot'] || !$mu || realpath($mu) !== $mu || !is_dir($mu)
        || !preg_match('/^[a-f0-9]{64}$/D', $fingerprint)) {
        throw new RuntimeException('The checkpoint belongs to a different WordPress installation.');
    }
    try {
        if (hash_equals($fingerprint, kiwe_fleet_fingerprint($mu))) {
            return false;
        }
    } catch (RuntimeException) {
        // An interrupted swap may have left no bootable Core folder.
    }

    $backup = realpath($record['backup'] ?? '');
    if (!$backup || dirname($backup) !== $private || !preg_match('/^kiwe-backup-[a-f0-9-]{36}$/D', basename($backup))) {
        throw new RuntimeException('The private previous Core copy is missing.');
    }
    $contents = is_file($mu . '/dsa/dsa.php') ? file_get_contents($mu . '/dsa/dsa.php') : '';
    preg_match("/define\(\s*'DSA_VERSION'\s*,\s*'([^']+)'\s*\)/", $contents, $version);
    $pendingFile = $mu . '/kiwe-update-transaction.json';
    $pending = is_file($pendingFile) ? json_decode(file_get_contents($pendingFile), true) : null;
    $target = $record['startedTarget'] ?? '';
    $matchingSwap = ($pending['oldVersion'] ?? null) === $record['current'] && ($pending['newVersion'] ?? null) === $target;
    if (!$target || (($version[1] ?? null) !== $target && !$matchingSwap)) {
        throw new RuntimeException('The installed files no longer belong to the attempted release.');
    }
    $files = kiwe_fleet_core_files($backup);
    if (!hash_equals($fingerprint, hash('sha256', json_encode($files, JSON_THROW_ON_ERROR)))) {
        throw new RuntimeException('The previous Core copy failed recovery verification.');
    }

    $recovery = $work . '/restore-' . bin2hex(random_bytes(8));
    $staged = $recovery . '/prior';
    $retired = $recovery . '/attempted';
    if (!mkdir($recovery, 0700) || !mkdir($staged, 0755) || !mkdir($retired, 0700)) {
        throw new RuntimeException('Could not prepare private recovery storage.');
    }
    foreach ($files as $file => $hash) {
        $destination = $staged . '/' . $file;
        $directory = dirname($destination);
        if ((!is_dir($directory) && !mkdir($directory, 0755, true))
            || !copy($backup . '/' . $file, $destination) || !chmod($destination, 0644)
            || !hash_equals($hash, hash_file('sha256', $destination))) {
            throw new RuntimeException('The staged recovery copy did not verify.');
        }
    }

    $moved = [];
    try {
        foreach (['kiwe-update-transaction.json', 'dsa', 'dsa.php', 'kiwe-incident-guard.php'] as $name) {
            $installed = $mu . '/' . $name;
            if (is_link($installed)) {
                throw new RuntimeException('A linked Core entry cannot be replaced.');
            }
            if (file_exists($installed)) {
                kiwe_fleet_recovery_move($installed, $retired . '/' . $name, $moved);
            }
            if (file_exists($staged . '/' . $name)) {
                kiwe_fleet_recovery_move($staged . '/' . $name, $installed, $moved);
            }
        }
        if (!hash_equals($fingerprint, kiwe_fleet_fingerprint($mu))) {
            throw new RuntimeException('The recovered Core copy did not verify.');
        }
    } catch (Throwable $error) {
        foreach (array_reverse($moved) as [$from, $to]) {
            if (!@rename($to, $from)) {
                throw new RuntimeException('File recovery needs operator attention. Both package copies were retained.', 0, $error);
            }
        }
        throw $error;
    }
    return true;
}

function kiwe_fleet_recovery_move(string $from, string $to, array &$moves): void {
    if (!@rename($from, $to)) {
        throw new RuntimeException('The Core file move failed.');
    }
    $moves[] = [$from, $to];
}

if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    try {
        $restored = kiwe_fleet_restore_files($argv[1] ?? '', $argv[2] ?? '');
        echo json_encode(['filesRestored' => $restored], JSON_THROW_ON_ERROR) . PHP_EOL;
    } catch (Throwable $error) {
        echo json_encode(['error' => $error->getMessage()]) . PHP_EOL;
        exit(1);
    }
}
