<?php

declare(strict_types=1);

const KIWE_LAUNCH_KINDS = ['demo', 'partner'];

function kiwe_launch_form_field(array $input, string $key, int $max, string $label, bool $required = true): string
{
    $value = $input[$key] ?? '';
    if (is_string($value)) {
        $value = trim($value);
        $length = preg_match_all('/./us', $value);
        if ($length !== false && $length <= $max && (!$required || $value !== '') && !str_contains($value, "\0")) {
            return $value;
        }
    }
    throw new InvalidArgumentException("Check the {$label} field and try again.");
}

function kiwe_launch_validate_inquiry(array $input): array
{
    $kind = $input['kind'] ?? null;
    if (!in_array($kind, KIWE_LAUNCH_KINDS, true) || ($input['website_code'] ?? '') !== '') {
        throw new InvalidArgumentException('Open the form again and try sending your request.');
    }
    if (($input['consent'] ?? null) !== 'yes') {
        throw new InvalidArgumentException('Confirm that Kiwe may contact you about your request.');
    }
    $email = kiwe_launch_form_field($input, 'email', 254, 'email address');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new InvalidArgumentException('Enter a valid email address.');
    }
    $phone = kiwe_launch_form_field($input, 'phone', 24, 'phone number', false);
    if ($phone !== '' && (!preg_match('/^\+?[0-9 ().-]+$/D', $phone) || strlen(preg_replace('/\D/', '', $phone)) < 7)) {
        throw new InvalidArgumentException('Enter a valid phone number or leave it blank.');
    }
    return [
        'kind' => $kind,
        'name' => kiwe_launch_form_field($input, 'name', 100, 'name'),
        'email' => strtolower($email),
        'business' => kiwe_launch_form_field($input, 'business', 150, 'business name'),
        'phone' => $phone,
        'message' => kiwe_launch_form_field($input, 'message', 1200, 'message', false),
    ];
}

function kiwe_launch_record_inquiry(string $office, array $inquiry, string $address): void
{
    umask(0077);
    $storage = $office . '/data';
    if (!is_dir($storage) || !is_readable($storage . '/network-key')) {
        throw new RuntimeException('Launch inbox has no private storage configuration.');
    }
    $secret = file_get_contents($storage . '/network-key');
    if (!is_string($secret) || strlen($secret) !== 32) {
        throw new RuntimeException('Launch inbox key is invalid.');
    }
    $db = new PDO('sqlite:' . $storage . '/inquiries.sqlite');
    $db->exec('PRAGMA busy_timeout = 5000');
    $db->exec('CREATE TABLE IF NOT EXISTS inquiries (
        id TEXT PRIMARY KEY, kind TEXT NOT NULL, received_at INTEGER NOT NULL, data TEXT NOT NULL,
        fingerprint TEXT NOT NULL, network TEXT
    )');
    $db->exec('CREATE INDEX IF NOT EXISTS recent_fingerprint ON inquiries (fingerprint, received_at)');
    $db->exec('CREATE INDEX IF NOT EXISTS recent_network ON inquiries (network, received_at)');
    $db->exec('CREATE INDEX IF NOT EXISTS inquiry_date ON inquiries (received_at)');

    $now = time();
    $data = json_encode($inquiry, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    $fingerprint = hash_hmac('sha256', $data, $secret);
    $network = hash_hmac('sha256', $address, $secret);
    $db->exec('BEGIN IMMEDIATE');
    try {
        $prior = $db->prepare('SELECT 1 FROM inquiries WHERE fingerprint = ? AND received_at >= ? LIMIT 1');
        $prior->execute([$fingerprint, $now - 900]);
        if (!$prior->fetchColumn()) {
            $recent = $db->prepare('SELECT COUNT(*) FROM inquiries WHERE network = ? AND received_at >= ?');
            $recent->execute([$network, $now - 1800]);
            if ((int) $recent->fetchColumn() >= 6) {
                throw new DomainException('Too many requests were sent from this connection. Try again in 30 minutes.');
            }
            $insert = $db->prepare('INSERT INTO inquiries VALUES (?, ?, ?, ?, ?, ?)');
            $insert->execute([bin2hex(random_bytes(16)), $inquiry['kind'], $now, $data, $fingerprint, $network]);
        }
        $db->prepare('DELETE FROM inquiries WHERE received_at < ?')->execute([$now - 90 * 86400]);
        $db->prepare('UPDATE inquiries SET network = NULL WHERE received_at < ? AND network IS NOT NULL')
            ->execute([$now - 1800]);
        $db->exec('COMMIT');
    } catch (Throwable $error) {
        $db->exec('ROLLBACK');
        throw $error;
    }
}

function kiwe_launch_list_inquiries(string $office, ?string $kind, int $limit): array
{
    $database = $office . '/data/inquiries.sqlite';
    if (!is_file($database)) {
        return [];
    }
    $db = new PDO('sqlite:' . $database);
    $db->exec('PRAGMA query_only = ON');
    $db->exec('PRAGMA busy_timeout = 5000');
    $query = $db->prepare('SELECT id, received_at, data FROM inquiries
        WHERE received_at >= :cutoff AND (:kind IS NULL OR kind = :kind)
        ORDER BY received_at DESC, id DESC LIMIT :limit');
    $query->bindValue(':cutoff', time() - 90 * 86400, PDO::PARAM_INT);
    $query->bindValue(':kind', $kind, $kind === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
    $query->bindValue(':limit', $limit, PDO::PARAM_INT);
    $query->execute();
    return array_map(static fn (array $row): array => [
        'id' => $row['id'],
        'receivedAt' => gmdate(DATE_ATOM, (int) $row['received_at']),
        ...json_decode($row['data'], true, flags: JSON_THROW_ON_ERROR),
    ], $query->fetchAll(PDO::FETCH_ASSOC));
}
