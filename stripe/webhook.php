<?php
declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';

$vendorAutoload = __DIR__ . '/../vendor/autoload.php';
if (!is_file($vendorAutoload)) {
    http_response_code(500);
    exit('Stripe SDK not installed.');
}
require $vendorAutoload;

/*
|--------------------------------------------------------------------------
| Logger
|--------------------------------------------------------------------------
*/
function stripe_log(string $message, array $context = []): void
{
    global $config;
    $logDir = $config['paths']['log_dir'] ?? (__DIR__ . '/../logs');
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0777, true);
    }
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $message;
    if (!empty($context)) {
        $line .= ' ' . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    $line .= PHP_EOL;
    @file_put_contents($logDir . '/stripe_webhook.log', $line, FILE_APPEND);
}

/*
|--------------------------------------------------------------------------
| Validate config
|--------------------------------------------------------------------------
*/
$webhookSecret = trim((string) ($config['stripe']['webhook_secret'] ?? ''));
$secretKey     = trim((string) ($config['stripe']['secret_key']     ?? ''));

if ($webhookSecret === '' || $secretKey === '') {
    http_response_code(500);
    stripe_log('Stripe config missing (webhook_secret or secret_key)');
    exit;
}

/*
|--------------------------------------------------------------------------
| Verify Stripe signature
|--------------------------------------------------------------------------
*/
$payload   = (string) file_get_contents('php://input');
$sigHeader = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';

\Stripe\Stripe::setApiKey($secretKey);

try {
    $event = \Stripe\Webhook::constructEvent($payload, $sigHeader, $webhookSecret);
} catch (\Stripe\Exception\SignatureVerificationException $e) {
    http_response_code(400);
    stripe_log('signature verification failed', ['error' => $e->getMessage()]);
    exit;
} catch (\Throwable $e) {
    http_response_code(400);
    stripe_log('webhook parse failed', ['error' => $e->getMessage()]);
    exit;
}

stripe_log('event received', ['type' => $event->type]);

/*
|--------------------------------------------------------------------------
| Initialize repositories
|--------------------------------------------------------------------------
*/
try {
    $db       = new \FujiraManager\Storage\Database($config['db']);
    $userRepo = new \FujiraManager\Storage\UserRepository($db);
} catch (\Throwable $e) {
    http_response_code(500);
    stripe_log('db init failed', ['error' => $e->getMessage()]);
    exit;
}

/*
|--------------------------------------------------------------------------
| Handle events
|--------------------------------------------------------------------------
*/
try {
    switch ($event->type) {

        /*
         * checkout.session.completed
         * Save stripe_customer_id / stripe_subscription_id.
         * is_paid is NOT set here; wait for invoice.payment_succeeded.
         */
        case 'checkout.session.completed':
            $session = $event->data->object;
            $ownerId = (int) ($session->metadata->owner_id
                ?? $session->client_reference_id
                ?? 0);
            if ($ownerId > 0) {
                $userRepo->updateSubscription($ownerId, [
                    'stripe_customer_id'     => $session->customer,
                    'stripe_subscription_id' => $session->subscription,
                ]);
                stripe_log('checkout completed', [
                    'owner_id'        => $ownerId,
                    'customer_id'     => $session->customer,
                    'subscription_id' => $session->subscription,
                ]);
            }
            break;

        /*
         * invoice.payment_succeeded
         * Payment confirmed → is_paid=1, update expires_at to period end.
         * This is the ONLY event that sets is_paid=1.
         */
        case 'invoice.payment_succeeded':
            $invoice    = $event->data->object;
            $customerId = $invoice->customer;
            $periodEnd  = $invoice->lines->data[0]->period->end ?? null;
            $expiresAt  = $periodEnd ? date('Y-m-d H:i:s', (int) $periodEnd) : null;
            $user       = $userRepo->findByStripeCustomerId($customerId);
            if ($user !== null && $expiresAt !== null) {
                $userRepo->updateSubscription((int) $user['id'], [
                    'is_paid'                 => 1,
                    'subscription_status'     => 'active',
                    'subscription_expires_at' => $expiresAt,
                ]);
                stripe_log('payment succeeded', [
                    'owner_id'   => $user['id'],
                    'expires_at' => $expiresAt,
                ]);
            }
            break;

        /*
         * customer.subscription.updated
         * Update subscription_status and subscription_expires_at.
         * is_paid is NOT changed here — expires_at handles paid/free transition.
         */
        case 'customer.subscription.updated':
            $sub        = $event->data->object;
            $customerId = $sub->customer;
            $expiresAt  = date('Y-m-d H:i:s', (int) $sub->current_period_end);
            $user       = $userRepo->findByStripeCustomerId($customerId);
            if ($user !== null) {
                $userRepo->updateSubscription((int) $user['id'], [
                    'subscription_status'     => $sub->status,
                    'subscription_expires_at' => $expiresAt,
                    // is_paid intentionally not updated
                ]);
                stripe_log('subscription updated', [
                    'owner_id'   => $user['id'],
                    'status'     => $sub->status,
                    'expires_at' => $expiresAt,
                ]);
            }
            break;

        /*
         * customer.subscription.deleted
         * Mark as canceled but keep expires_at so the user retains access
         * until the end of their paid period. is_paid is NOT set to 0 here.
         * is_paid_user() will return false once expires_at passes.
         */
        case 'customer.subscription.deleted':
            $sub        = $event->data->object;
            $customerId = $sub->customer;
            $expiresAt  = date('Y-m-d H:i:s', (int) $sub->current_period_end);
            $user       = $userRepo->findByStripeCustomerId($customerId);
            if ($user !== null) {
                $userRepo->updateSubscription((int) $user['id'], [
                    'subscription_status'     => 'canceled',
                    'subscription_expires_at' => $expiresAt,
                    // is_paid intentionally not updated
                ]);
                stripe_log('subscription deleted', [
                    'owner_id'   => $user['id'],
                    'expires_at' => $expiresAt,
                ]);
            }
            break;

        default:
            stripe_log('unhandled event', ['type' => $event->type]);
            break;
    }
} catch (\Throwable $e) {
    http_response_code(500);
    stripe_log('event processing failed', [
        'type'  => $event->type,
        'error' => $e->getMessage(),
    ]);
    exit;
}

http_response_code(200);
echo 'OK';
