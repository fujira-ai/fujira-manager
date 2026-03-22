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
$baseUrl   = rtrim((string) ($config['app']['base_url']    ?? ''), '/');

if ($secretKey === '') {
    http_response_code(500);
    exit('Stripe configuration is missing. Set secret_key in config.secret.php.');
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

$customerId = trim((string) ($user['stripe_customer_id'] ?? ''));
if ($customerId === '') {
    http_response_code(400);
    exit('有料プランへの登録が確認できませんでした。先にご登録ください。');
}

/*
|--------------------------------------------------------------------------
| Create Stripe Billing Portal Session
|--------------------------------------------------------------------------
*/
\Stripe\Stripe::setApiKey($secretKey);

try {
    $session = \Stripe\BillingPortal\Session::create([
        'customer'   => $customerId,
        'return_url' => $baseUrl,
    ]);
} catch (\Throwable $e) {
    http_response_code(500);
    exit('Portal session creation failed: ' . $e->getMessage());
}

header('Location: ' . $session->url, true, 303);
exit;
