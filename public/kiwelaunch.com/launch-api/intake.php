<?php

declare(strict_types=1);

header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function kiwe_launch_reply(int $status, string $message): never
{
    http_response_code($status);
    if (str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json')) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => $status === 200, 'message' => $message], JSON_THROW_ON_ERROR);
        exit;
    }
    header('Content-Type: text/html; charset=utf-8');
    $back = ($_POST['kind'] ?? '') === 'partner' ? '/partners/' : '/#demo';
    $message = htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    echo <<<HTML
<!doctype html>
<html lang="en-IN">
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex"><link rel="stylesheet" href="/assets/launch/site.css?v=2">
<title>Kiwe Launch request</title>
<main class="subpage"><section class="section wrap"><h1>Kiwe Launch</h1><p class="intro">$message</p>
<a class="button" href="$back">Return to the form</a></section></main>
</html>
HTML;
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    kiwe_launch_reply(405, 'Use the form to send a request.');
}

$configuredOrigin = getenv('KIWE_LAUNCH_ORIGIN');
$origins = $configuredOrigin ? [$configuredOrigin] : ['https://kiwelaunch.com', 'https://www.kiwelaunch.com'];
$requestOrigin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($requestOrigin === '') {
    $referer = $_SERVER['HTTP_REFERER'] ?? '';
    foreach ($origins as $origin) {
        if (str_starts_with($referer, $origin . '/')) {
            $requestOrigin = $origin;
            break;
        }
    }
}
if (!in_array($requestOrigin, $origins, true)) {
    kiwe_launch_reply(403, 'Open the form on Kiwe Launch and try again.');
}

if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 24000) {
    kiwe_launch_reply(413, 'Shorten your message and send it again.');
}

$office = getenv('KIWE_LAUNCH_OFFICE') ?: dirname(__DIR__, 4) . '/.kiwe-launch-office';
try {
    if (!is_readable($office . '/inquiries.php')) {
        throw new RuntimeException('Launch inbox is unavailable.');
    }
    require $office . '/inquiries.php';
    $inquiry = kiwe_launch_validate_inquiry($_POST);
    kiwe_launch_record_inquiry($office, $inquiry, $_SERVER['REMOTE_ADDR'] ?? 'unknown');
    $message = $inquiry['kind'] === 'demo'
        ? 'Your demo request is in. Kiwe will follow up using the details you shared.'
        : 'Your interest is registered. Kiwe will follow up about the Partner Program.';
    kiwe_launch_reply(200, $message);
} catch (InvalidArgumentException $error) {
    kiwe_launch_reply(422, $error->getMessage());
} catch (DomainException $error) {
    header('Retry-After: 1800');
    kiwe_launch_reply(429, $error->getMessage());
} catch (Throwable) {
    error_log('Kiwe Launch intake could not save an inquiry.');
    kiwe_launch_reply(503, 'Kiwe could not save your request. Please try again in a moment.');
}
