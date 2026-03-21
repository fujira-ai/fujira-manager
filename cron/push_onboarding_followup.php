<?php
declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';

/*
|--------------------------------------------------------------------------
| Logger
|--------------------------------------------------------------------------
*/
function cron_log(string $message, array $context = []): void
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

    @file_put_contents($logDir . '/cron_onboarding_followup.log', $line, FILE_APPEND);
}

/*
|--------------------------------------------------------------------------
| Main
|--------------------------------------------------------------------------
*/
cron_log('onboarding followup cron started');

try {
    $db            = new \FujiraManager\Storage\Database($config['db']);
    $userRepo      = new \FujiraManager\Storage\UserRepository($db);
    $taskRepo      = new \FujiraManager\Storage\TaskRepository($db);
    $convStateRepo = new \FujiraManager\Storage\ConvStateRepository($db);
    $line          = new \FujiraManager\Services\LineService($config);
} catch (\Throwable $e) {
    cron_log('onboarding followup init failed', ['error' => $e->getMessage()]);
    exit(1);
}

$users = $userRepo->getAllUsers();
cron_log('onboarding followup user scan', ['total_users' => count($users)]);

$tz  = new DateTimeZone('Asia/Tokyo');
$now = new DateTime('now', $tz);

$candidateCount = 0;
$sentCount      = 0;

foreach ($users as $user) {
    $ownerId    = (int) $user['id'];
    $lineUserId = (string) $user['line_user_id'];

    try {
        $state        = $convStateRepo->getState($ownerId);
        $startedAt    = $state['onboarding_started_at'] ?? null;
        $followupSent = $state['onboarding_followup_sent'] ?? null;

        // [DIAG] dump raw state for every user
        cron_log('onboarding followup user check', [
            'owner_id'               => $ownerId,
            'onboarding_started_at'  => $startedAt,
            'onboarding_followup_sent_raw' => $followupSent,
            'followup_sent_type'     => gettype($followupSent),
        ]);

        // Skip: onboarding not started
        if (empty($startedAt)) {
            cron_log('onboarding followup skip: no onboarding_started_at', ['owner_id' => $ownerId]);
            continue;
        }

        // Skip: followup already sent
        if ($followupSent === true || $followupSent === 1) {
            cron_log('onboarding followup skip: already sent', ['owner_id' => $ownerId, 'followup_sent_value' => $followupSent]);
            continue;
        }

        $candidateCount++;

        // Skip: less than 1 hour since follow
        $startDt = new DateTime($startedAt, $tz);
        $elapsed = $now->getTimestamp() - $startDt->getTimestamp();
        if ($elapsed < 3600) {
            cron_log('onboarding followup skip: too early', [
                'owner_id'    => $ownerId,
                'elapsed_sec' => $elapsed,
                'started_at'  => $startedAt,
                'now'         => $now->format('Y-m-d H:i:s'),
            ]);
            continue;
        }

        // Skip: already has tasks
        $taskCount = $taskRepo->countOpenTasksByOwner($ownerId);
        if ($taskCount > 0) {
            cron_log('onboarding followup skip: has tasks', ['owner_id' => $ownerId, 'task_count' => $taskCount]);
            $state['onboarding_followup_sent'] = true;
            $convStateRepo->saveState($ownerId, $state);
            continue;
        }

        // Send followup
        $message = implode("\n", [
            'まだ使っていなければ、これだけ試してみてください👇',
            '',
            '「今日 やること1つ」',
            '',
            '↓',
            '',
            '「今日」',
            '',
            '↓',
            '',
            '「完了1」',
            '',
            'これで一通り使えます。',
        ]);

        cron_log('onboarding followup push attempt', [
            'owner_id'    => $ownerId,
            'line_user_id'=> $lineUserId,
            'elapsed_sec' => $elapsed,
            'task_count'  => $taskCount,
        ]);

        $line->pushMessage($lineUserId, $message);

        $state['onboarding_followup_sent'] = true;
        $convStateRepo->saveState($ownerId, $state);

        $sentCount++;
        cron_log('onboarding followup sent', ['owner_id' => $ownerId, 'line_user_id' => $lineUserId]);

    } catch (\Throwable $e) {
        cron_log('onboarding followup error', [
            'owner_id'     => $ownerId,
            'line_user_id' => $lineUserId,
            'error'        => $e->getMessage(),
        ]);
    }
}

cron_log('onboarding followup cron finished', [
    'total_users'    => count($users),
    'candidates'     => $candidateCount,
    'sent'           => $sentCount,
]);
