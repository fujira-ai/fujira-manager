<?php
declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';

$vendorAutoload = __DIR__ . '/../vendor/autoload.php';
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
| Identify user from uid query param
|--------------------------------------------------------------------------
*/
$uid = trim((string) ($_GET['uid'] ?? ''));
if ($uid === '') {
    http_response_code(400);
    exit('Missing uid');
}

try {
    $db       = new \FujiraManager\Storage\Database($config['db']);
    $userRepo = new \FujiraManager\Storage\UserRepository($db);
} catch (\Throwable $e) {
    http_response_code(500);
    exit('DB init failed');
}

$user = $userRepo->findByLineUserId($uid);
if ($user === null) {
    http_response_code(400);
    exit('User not found');
}

$ownerId = (int) $user['id'];

/*
|--------------------------------------------------------------------------
| Create Stripe Checkout Session
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
        'metadata'            => ['owner_id' => $ownerId],
        'client_reference_id' => (string) $ownerId,
    ]);
} catch (\Throwable $e) {
    http_response_code(500);
    exit('Checkout session creation failed: ' . $e->getMessage());
}

header('Location: ' . $session->url, true, 303);
exit;
