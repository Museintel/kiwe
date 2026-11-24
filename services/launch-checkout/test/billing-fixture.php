<?php

declare(strict_types=1);

function kiwe_test_gateway(string $root, string $method, string $route, array $body): array
{
    $file = $root . '/data/gateway.json';
    $state = json_decode(file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
    $fields = explode('?', $route, 2);
    $route = $fields[0];
    parse_str($fields[1] ?? '', $query);
    if ($route === '/payment_links') {
        if ($method === 'GET') {
            return ['payment_links' => array_values(array_filter($state['links'], static fn ($item) => $item['reference_id'] === $query['reference_id']))];
        }
        foreach ($state['links'] as $link) {
            if ($link['reference_id'] === $body['reference_id']) throw new RuntimeException('Duplicate payment link reference.');
        }
        $id = 'plink_Fixture' . (count($state['links']) + 1);
        $link = $body + ['id' => $id, 'status' => 'created', 'amount_paid' => 0, 'short_url' => 'https://rzp.io/i/' . $id];
        $state['links'][$id] = $link;
        $lost = $state['lose_link'] ?? false;
        $state['lose_link'] = false;
        file_put_contents($file, json_encode($state, JSON_THROW_ON_ERROR));
        if ($lost) throw new KiweCheckoutError('Razorpay is still confirming the payment link.', 503);
        return $link;
    }
    $path = explode('/', trim($route, '/'));
    if ($path[0] === 'payment_links') return $state['links'][$path[1]];
    if ($path[0] === 'payments') return $state['payments'][$path[1]] ?? throw new RuntimeException('Payment is missing from the fixture.');
    if ($path[0] === 'orders') {
        return ['items' => array_values(array_filter($state['payments'], static fn ($item) => $item['order_id'] === $path[1]))];
    }
    if ($path[0] === 'plans') {
        $plan = json_decode(file_get_contents($root . '/client-plan.json'), true, flags: JSON_THROW_ON_ERROR);
        $terms = $plan[$path[1] === 'plan_ReferralFixture' ? 'referred' : 'direct'];
        return ['id' => $path[1], 'period' => 'monthly', 'interval' => 1, 'item' => ['amount' => $terms['renewalMinor'], 'currency' => 'INR']];
    }
    if ($route === '/subscriptions' && $method === 'POST') {
        $id = 'sub_Fixture' . (count($state['subscriptions']) + 1);
        $state['subscriptions'][$id] = $body + ['id' => $id, 'status' => 'created', 'short_url' => 'https://rzp.io/rzp/' . $id];
        file_put_contents($file, json_encode($state, JSON_THROW_ON_ERROR));
        return $state['subscriptions'][$id];
    }
    if ($path[0] === 'subscriptions') {
        if ($method === 'POST' && ($path[2] ?? '') === 'cancel') {
            $state['subscriptions'][$path[1]]['status'] = 'cancelled';
            file_put_contents($file, json_encode($state, JSON_THROW_ON_ERROR));
        }
        return $state['subscriptions'][$path[1]];
    }
    throw new RuntimeException('Unexpected fixture gateway operation.');
}

function kiwe_test_start(string $root, string $origin, string $token, string $action, array $body): array
{
    $opts = stream_context_create(['http' => [
        'method' => 'POST', 'header' => "Content-Type: application/json\r\nAuthorization: Bearer {$token}\r\n",
        'content' => json_encode($body, JSON_THROW_ON_ERROR), 'timeout' => 5, 'ignore_errors' => true,
    ]]);
    $reply = file_get_contents($origin . '/projects/api/' . $action, false, $opts);
    if (!is_string($reply)) throw new KiweCheckoutError('Start is still opening your project.', 503);
    $data = json_decode($reply, true, flags: JSON_THROW_ON_ERROR);
    if (isset($data['error'])) throw new KiweCheckoutError('Start fixture response: ' . $data['error'], 503);
    $flag = $root . '/data/lose-start';
    if ($action === 'purchases' && is_file($flag)) {
        unlink($flag);
        throw new KiweCheckoutError('Start is still opening your project.', 503);
    }
    return $data;
}
