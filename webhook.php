<?php
declare(strict_types=1);

require __DIR__ . '/app/bootstrap.php';

/*
|--------------------------------------------------------------------------
| Simple logger
|--------------------------------------------------------------------------
*/
function webhook_log(string $message, array $context = []): void
{
    global $config;

    $logDir = $config['paths']['log_dir'] ?? (__DIR__ . '/logs');
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0777, true);
    }

    $line = '[' . date('Y-m-d H:i:s') . '] ' . $message;
    if (!empty($context)) {
        $line .= ' ' . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    $line .= PHP_EOL;

    @file_put_contents($logDir . '/webhook.log', $line, FILE_APPEND);
}

/*
|--------------------------------------------------------------------------
| LINE reply helper
|--------------------------------------------------------------------------
*/
function line_reply(string $replyToken, string $message): void
{
    global $config;

    $accessToken = trim((string)($config['line']['channel_access_token'] ?? ''));
    if ($accessToken === '') {
        webhook_log('LINE access token is empty');
        return;
    }

    $url = 'https://api.line.me/v2/bot/message/reply';

    $payload = [
        'replyToken' => $replyToken,
        'messages' => [
            [
                'type' => 'text',
                'text' => $message,
            ]
        ],
    ];

    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    if ($json === false) {
        webhook_log('json_encode failed', [
            'json_error' => json_last_error_msg(),
            'payload' => $payload,
        ]);
        return;
    }

    $headers = [
        'Content-Type: application/json; charset=UTF-8',
        'Authorization: Bearer ' . $accessToken,
        'Content-Length: ' . strlen($json),
    ];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);

    $response = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);

    curl_close($ch);

    webhook_log('LINE reply result', [
        'http_code' => $httpCode,
        'curl_error' => $curlError,
        'request_json' => $json,
        'response' => $response,
    ]);
}

/*
|--------------------------------------------------------------------------
| Browser direct access test
|--------------------------------------------------------------------------
*/
$body = file_get_contents('php://input');
$signature = $_SERVER['HTTP_X_LINE_SIGNATURE'] ?? '';

if ($body === '' && $signature === '') {
    header('Content-Type: text/plain; charset=UTF-8');
    echo "webhook ready";
    exit;
}

/*
|--------------------------------------------------------------------------
| Signature validation
|--------------------------------------------------------------------------
*/
$channelSecret = $config['line']['channel_secret'] ?? '';
if ($channelSecret === '') {
    http_response_code(500);
    echo 'LINE channel_secret is empty';
    webhook_log('LINE channel_secret is empty');
    exit;
}

$hash = base64_encode(hash_hmac('sha256', $body, $channelSecret, true));

if (!hash_equals($hash, $signature)) {
    http_response_code(400);
    echo 'Invalid signature';
    webhook_log('Invalid signature', [
        'received_signature' => $signature,
    ]);
    exit;
}

/*
|--------------------------------------------------------------------------
| Parse event payload
|--------------------------------------------------------------------------
*/
$data = json_decode($body, true);

if (!is_array($data) || !isset($data['events']) || !is_array($data['events'])) {
    http_response_code(400);
    echo 'Invalid payload';
    webhook_log('Invalid payload', ['body' => $body]);
    exit;
}

webhook_log('Webhook received', ['events_count' => count($data['events'])]);

/*
|--------------------------------------------------------------------------
| Storage initialization
|--------------------------------------------------------------------------
*/
$userRepo      = null;
$taskRepo      = null;
$convStateRepo = null;
try {
    $db            = new \FujiraManager\Storage\Database($config['db']);
    $userRepo      = new \FujiraManager\Storage\UserRepository($db);
    $taskRepo      = new \FujiraManager\Storage\TaskRepository($db);
    $convStateRepo = new \FujiraManager\Storage\ConvStateRepository($db);
} catch (\Throwable $e) {
    webhook_log('DB init failed', ['error' => $e->getMessage()]);
}

/*
|--------------------------------------------------------------------------
| Handle events
|--------------------------------------------------------------------------
*/
foreach ($data['events'] as $event) {
    $eventType = $event['type'] ?? '';

    // Follow event
    if ($eventType === 'follow') {
        $replyToken = $event['replyToken'] ?? '';
        if ($replyToken !== '') {
            line_reply($replyToken, '友だち追加ありがとうございます。Fujira Manager 起動確認OKです。');
        }
        continue;
    }

    // Only text message for now
    if ($eventType !== 'message') {
        continue;
    }

    if (($event['message']['type'] ?? '') !== 'text') {
        continue;
    }

    $replyToken = $event['replyToken'] ?? '';
    $text = trim((string) ($event['message']['text'] ?? ''));
    $lineUserId = (string) ($event['source']['userId'] ?? '');

    webhook_log('Text message received', [
        'user_id' => $lineUserId,
        'text' => $text,
    ]);

    // Auto-register user and resolve owner_id
    $ownerId = null;
    if ($lineUserId !== '' && $userRepo !== null) {
        try {
            $user = $userRepo->findByLineUserId($lineUserId);
            if ($user === null) {
                $ownerId = $userRepo->create($lineUserId);
                webhook_log('user created', ['line_user_id' => $lineUserId, 'owner_id' => $ownerId]);
            } else {
                $ownerId = (int) $user['id'];
                webhook_log('user exists', ['line_user_id' => $lineUserId, 'owner_id' => $ownerId]);
            }
        } catch (\Throwable $e) {
            webhook_log('user registration failed', ['error' => $e->getMessage()]);
        }
    }

    // Task list command
    if ($text === '一覧' || $text === '/list') {
        $replyText = '現在 open のタスクはありません';
        if ($ownerId !== null && $taskRepo !== null) {
            try {
                $tasks = $taskRepo->getOpenTasksByOwner($ownerId);
                if (!empty($tasks)) {
                    $map   = [];
                    $lines = ['現在のタスク:'];
                    foreach ($tasks as $i => $t) {
                        $num        = $i + 1;
                        $map[(string)$num] = (int)$t['id'];
                        $lines[]    = $num . '. ' . $t['title'];
                    }
                    $replyText = implode("\n", $lines);

                    if ($convStateRepo !== null) {
                        webhook_log('list map save attempt', ['owner_id' => $ownerId, 'map' => $map]);
                        try {
                            $state = $convStateRepo->getState($ownerId);
                            $state['last_task_list_map'] = $map;
                            $convStateRepo->saveState($ownerId, $state);
                            webhook_log('list map save result', ['owner_id' => $ownerId, 'saved' => true]);
                        } catch (\Throwable $e) {
                            webhook_log('conv_state save failed', ['error' => $e->getMessage()]);
                        }
                    }
                }
            } catch (\Throwable $e) {
                webhook_log('task list failed', ['error' => $e->getMessage()]);
                $replyText = 'タスク取得に失敗しました';
            }
        }
        if ($replyToken !== '') {
            line_reply($replyToken, $replyText);
        }
        continue;
    }

    // Task complete command
    if (preg_match('/^(?:完了|\/done)\s+(\d+)$/', $text, $matches)) {
        $num    = (int) $matches[1];
        $taskId = $num;

        // Resolve list number → task_id via conv_state
        if ($ownerId !== null && $convStateRepo !== null) {
            try {
                $state = $convStateRepo->getState($ownerId);
                webhook_log('state loaded', ['owner_id' => $ownerId, 'state' => $state]);
                $map   = $state['last_task_list_map'] ?? [];
                if (isset($map[(string)$num])) {
                    $taskId = (int) $map[(string)$num];
                }
                webhook_log('resolved taskId', ['input' => $num, 'resolved' => $taskId]);
            } catch (\Throwable $e) {
                webhook_log('conv_state get failed', ['error' => $e->getMessage()]);
            }
        }

        $replyText = '該当する open task が見つかりません';
        if ($ownerId !== null && $taskRepo !== null) {
            try {
                $done = $taskRepo->completeOpenTaskById($ownerId, $taskId);
                if ($done !== null) {
                    $replyText = "完了にしました:\n・" . $done['title'];
                    webhook_log('task completed', ['owner_id' => $ownerId, 'task_id' => $taskId]);
                }
            } catch (\Throwable $e) {
                webhook_log('task complete failed', ['error' => $e->getMessage()]);
                $replyText = 'タスク完了処理に失敗しました';
            }
        }
        if ($replyToken !== '') {
            line_reply($replyToken, $replyText);
        }
        continue;
    }

    // Task delete command
    if (preg_match('/^(?:削除|\/delete|\/del)\s+(\d+)$/', $text, $matches)) {
        $num    = (int) $matches[1];
        $taskId = $num;

        // Resolve list number → task_id via conv_state
        if ($ownerId !== null && $convStateRepo !== null) {
            try {
                $state = $convStateRepo->getState($ownerId);
                $map   = $state['last_task_list_map'] ?? [];
                if (isset($map[(string)$num])) {
                    $taskId = (int) $map[(string)$num];
                }
            } catch (\Throwable $e) {
                webhook_log('conv_state get failed', ['error' => $e->getMessage()]);
            }
        }

        $replyText = '該当する open task が見つかりません';
        if ($ownerId !== null && $taskRepo !== null) {
            try {
                $deleted = $taskRepo->deleteOpenTaskById($ownerId, $taskId);
                if ($deleted !== null) {
                    $replyText = "削除しました:\n・" . $deleted['title'];
                    webhook_log('task deleted', ['owner_id' => $ownerId, 'task_id' => $taskId]);
                }
            } catch (\Throwable $e) {
                webhook_log('task delete failed', ['error' => $e->getMessage()]);
                $replyText = 'タスク削除処理に失敗しました';
            }
        }
        if ($replyToken !== '') {
            line_reply($replyToken, $replyText);
        }
        continue;
    }

    // Save task
    $isCommand = ($text === '一覧'
        || $text === '/list'
        || $text === '/ping'
        || $text === '/brief'
        || preg_match('/^(?:完了|\/done)\s+\d+$/', $text) === 1
        || preg_match('/^(?:削除|\/delete|\/del)\s+\d+$/', $text) === 1);

    webhook_log('task attempt', ['owner_id' => $ownerId, 'text' => $text, 'is_command' => $isCommand]);

    if ($ownerId === null) {
        webhook_log('task skipped: owner_id is null', ['line_user_id' => $lineUserId]);
    }

    if (!$isCommand && $ownerId !== null && $taskRepo !== null && $text !== '') {
        try {
            $taskId = $taskRepo->create($ownerId, $text);
            webhook_log('task created', ['owner_id' => $ownerId, 'title' => $text, 'task_id' => $taskId]);
        } catch (\Throwable $e) {
            webhook_log('task create failed', ['error' => $e->getMessage()]);
        }
    }

    if ($replyToken === '') {
        continue;
    }

    // simple test responses
    if ($text === '/ping') {
        line_reply($replyToken, 'pong');
        continue;
    }

    if ($text === '/brief') {
        line_reply($replyToken, "これはテスト版 brief です。\nFujira Manager は正常に動作しています。");
        continue;
    }

    // default echo response
    line_reply($replyToken, '受信: ' . $text);
}

http_response_code(200);
echo 'OK';