<?php
declare(strict_types=1);

require __DIR__ . '/app/bootstrap.php';

$vendorAutoload = __DIR__ . '/vendor/autoload.php';
if (!is_file($vendorAutoload)) {
    http_response_code(500);
    exit('Stripe SDK not installed. Run: composer require stripe/stripe-php');
}
require $vendorAutoload;

/*
|--------------------------------------------------------------------------
| Validate config
|--------------------------------------------------------------------------
*/
$secretKey = trim((string) ($config['stripe']['secret_key'] ?? ''));
$priceId   = trim((string) ($config['stripe']['price_id']   ?? ''));
$baseUrl   = rtrim((string) ($config['app']['base_url']     ?? ''), '/');

if ($secretKey === '' || $priceId === '') {
    http_response_code(500);
    exit('Stripe configuration is missing. Set secret_key and price_id in config.secret.php.');
}

/*
|--------------------------------------------------------------------------
| Resolve user via one-time token
|--------------------------------------------------------------------------
*/
$token = trim((string) ($_GET['token'] ?? ''));
if ($token === '') {
    http_response_code(400);
    exit('LINEからアクセスしてください');
}

try {
    $db        = new \FujiraManager\Storage\Database($config['db']);
    $userRepo  = new \FujiraManager\Storage\UserRepository($db);
    $tokenRepo = new \FujiraManager\Storage\TokenRepository($db);
} catch (\Throwable $e) {
    http_response_code(500);
    exit('DB init failed');
}

$userId = $tokenRepo->consumeToken($token, 'upgrade');
if ($userId === null) {
    http_response_code(400);
    exit('リンクの有効期限が切れました。LINEからもう一度お試しください。');
}

$user = $userRepo->findById($userId);
if ($user === null) {
    http_response_code(400);
    exit('ユーザー情報が見つかりません。');
}

/*
|--------------------------------------------------------------------------
| Create Stripe Checkout Session and redirect
|--------------------------------------------------------------------------
*/
\Stripe\Stripe::setApiKey($secretKey);

try {
    $session = \Stripe\Checkout\Session::create([
        'mode'                => 'subscription',
        'line_items'          => [[
            'price'    => $priceId,
            'quantity' => 1,
        ]],
        'success_url'         => $baseUrl . '/stripe/success.php',
        'cancel_url'          => $baseUrl . '/stripe/cancel.php',
        'metadata'            => ['owner_id' => $userId],
        'client_reference_id' => (string) $userId,
    ]);
} catch (\Throwable $e) {
    http_response_code(500);
    exit('Checkout session creation failed: ' . $e->getMessage());
}

header('Location: ' . $session->url, true, 303);
exit;
