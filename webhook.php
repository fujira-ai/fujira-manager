<?php
declare(strict_types=1);

require __DIR__ . '/app/bootstrap.php';
require __DIR__ . '/lib/OpenAIClient.php';

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
function line_reply(string $replyToken, string $message, ?array $quickReply = null): void
{
    global $config;

    $accessToken = trim((string)($config['line']['channel_access_token'] ?? ''));
    if ($accessToken === '') {
        webhook_log('LINE access token is empty');
        return;
    }

    $url = 'https://api.line.me/v2/bot/message/reply';

    $msgObj = ['type' => 'text', 'text' => $message];
    if ($quickReply !== null) {
        $msgObj['quickReply'] = $quickReply;
    }
    $payload = [
        'replyToken' => $replyToken,
        'messages'   => [$msgObj],
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
| LINE push helper (for follow-up messages after reply token is used)
|--------------------------------------------------------------------------
*/
function line_push(string $userId, string $message): void
{
    global $config;

    $accessToken = trim((string)($config['line']['channel_access_token'] ?? ''));
    if ($accessToken === '') {
        webhook_log('LINE access token is empty');
        return;
    }

    $url     = 'https://api.line.me/v2/bot/message/push';
    $payload = [
        'to'       => $userId,
        'messages' => [['type' => 'text', 'text' => $message]],
    ];

    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        webhook_log('line_push json_encode failed', ['json_error' => json_last_error_msg()]);
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

    $response  = curl_exec($ch);
    $httpCode  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    webhook_log('LINE push result', [
        'http_code'  => $httpCode,
        'curl_error' => $curlError,
        'response'   => $response,
    ]);
}

/*
|--------------------------------------------------------------------------
| Quick reply helpers — list paging only
|--------------------------------------------------------------------------
*/
function qr_item(string $label, string $text): array
{
    return ['type' => 'action', 'action' => ['type' => 'message', 'label' => $label, 'text' => $text]];
}

function build_list_quick_reply(int $page, int $totalPages): array
{
    if ($totalPages <= 1) {
        $items = [qr_item('今日', '今日')];
    } elseif ($page === 1) {
        $items = [qr_item('次', '次'), qr_item('今日', '今日')];
    } elseif ($page >= $totalPages) {
        $items = [qr_item('前', '前'), qr_item('今日', '今日')];
    } else {
        $items = [qr_item('前', '前'), qr_item('次', '次'), qr_item('今日', '今日')];
    }
    return ['items' => $items];
}

function build_today_quick_reply(int $count): array
{
    $items = [];
    $n     = min($count, 3);
    for ($i = 1; $i <= $n; $i++) {
        $items[] = qr_item('完了' . $i, '完了' . $i);
    }
    $items[] = qr_item('一覧', '一覧');
    return ['items' => $items];
}

function build_nodue_quick_reply(int $page, int $totalPages): array
{
    if ($totalPages <= 1) {
        $items = [qr_item('一覧', '一覧'), qr_item('今日', '今日')];
    } elseif ($page === 1) {
        $items = [qr_item('次', '次'), qr_item('一覧', '一覧')];
    } elseif ($page >= $totalPages) {
        $items = [qr_item('前', '前'), qr_item('一覧', '一覧')];
    } else {
        $items = [qr_item('前', '前'), qr_item('次', '次'), qr_item('一覧', '一覧')];
    }
    return ['items' => $items];
}

function build_tag_quick_reply(int $page, int $totalPages): array
{
    if ($totalPages <= 1) {
        $items = [qr_item('一覧', '一覧'), qr_item('今日', '今日')];
    } elseif ($page === 1) {
        $items = [qr_item('次', '次'), qr_item('一覧', '一覧')];
    } elseif ($page >= $totalPages) {
        $items = [qr_item('前', '前'), qr_item('一覧', '一覧')];
    } else {
        $items = [qr_item('前', '前'), qr_item('次', '次'), qr_item('一覧', '一覧')];
    }
    return ['items' => $items];
}

/*
|--------------------------------------------------------------------------
| Billing helper
|--------------------------------------------------------------------------
*/
function is_paid_user(array $user): bool
{
    if (!(bool) ($user['is_paid'] ?? false)) {
        return false;
    }
    if (empty($user['subscription_expires_at'])) {
        return false;
    }
    return (new DateTime($user['subscription_expires_at'])) > new DateTime();
}

/*
|--------------------------------------------------------------------------
| Time string → minutes-since-midnight converter (for conflict check)
|--------------------------------------------------------------------------
*/
function parse_time_to_minutes(string $time): ?int
{
    if (preg_match('/^(\d{1,2}):(\d{2})$/u', $time, $m)) {
        return (int) $m[1] * 60 + (int) $m[2];
    }
    if (preg_match('/^(\d{1,2})時(\d{1,2})分$/u', $time, $m)) {
        return (int) $m[1] * 60 + (int) $m[2];
    }
    if (preg_match('/^(\d{1,2})時半$/u', $time, $m)) {
        return (int) $m[1] * 60 + 30;
    }
    if (preg_match('/^(\d{1,2})時$/u', $time, $m)) {
        return (int) $m[1] * 60;
    }
    return null;
}

function normalizeTime(string $raw): string
{
    if (preg_match('/^(\d{1,2}):(\d{2})$/', $raw, $m)) {
        return sprintf('%02d:%02d', (int) $m[1], (int) $m[2]);
    }
    if (preg_match('/^(\d{1,2})時$/u', $raw, $m)) {
        return sprintf('%02d:00', (int) $m[1]);
    }
    if (preg_match('/^(\d{1,2})時半$/u', $raw, $m)) {
        return sprintf('%02d:30', (int) $m[1]);
    }
    if (preg_match('/^(\d{1,2})時(\d{1,2})分$/u', $raw, $m)) {
        return sprintf('%02d:%02d', (int) $m[1], (int) $m[2]);
    }
    return $raw;
}

/**
 * Heuristically decide whether a message is a task to register.
 * Operates on the parsed title ($saveTitle) after date/time stripping.
 * Conservative default = true; only rejects clearly conversational text.
 */
function isLikelyTask(string $text): bool
{
    // Reject: short conversational phrases / acknowledgements
    // Normalize comparison text only — $text itself is NOT modified
    static $conversationalPhrases = [
        'ありがとう', 'ありがとうございます', 'ありがとうございました', 'ありがとうです',
        '了解', '了解です', '了解しました',
        'ok',
        'はい', 'いいえ',
        'なるほど', 'なるほどです',
        'たぶん', 'おそらく',
        'あとで', '後で',
        '無理', '無理です',
        '大丈夫', '大丈夫です',
        'お願い', 'おねがい', 'お願いします', 'おねがいします',
        'お願いいたします', 'おねがいいたします',
        'よろしく', 'よろしくお願いします',
        'すみません', 'すみませんでした', 'ごめんなさい', 'ごめん',
        'わかった', 'わかりました', 'わかってる',
    ];
    $normalized = mb_strtolower($text, 'UTF-8');                        // case-normalize
    $normalized = preg_replace('/[\s　]+/u', '', $normalized);          // remove all spaces
    $normalized = preg_replace('/[\p{P}\p{S}]+$/u', '', $normalized);  // strip trailing punct/symbols/emoji
    if (in_array($normalized, $conversationalPhrases, true)) {
        return false;
    }

    // Reject: compound conversational text — contains a conversational key phrase
    //         but has no task element (time / #tag / action verb)
    if (preg_match('/ありがとう|了解|わかりました|わかった|よろしく|すみません|ごめん/u', $normalized)) {
        $hasTaskElement = preg_match(
            '/\d{1,2}時|\d{1,2}:\d{2}|#\S+|する|します|行く|行き|買う|確認|連絡|送る|作る|まとめ|提出|返信|予約|準備|検討|報告|相談|修正|更新|追加|対応|整理|申請|依頼|払う|振込|送付/u',
            $normalized
        );
        if (!$hasTaskElement) {
            return false;
        }
    }

    // Reject: ends with conversational sentence-final particles
    if (preg_match('/(?:ね|よ|なあ|かな|じゃん)$/u', $text)) {
        return false;
    }
    // Reject: "です" ending without any action verb (meta-comment pattern)
    if (preg_match('/です$/u', $text)
        && !preg_match('/する|します|行く|行き|買う|確認|連絡|送る|作る|まとめ|提出|返信|予約|準備|検討|報告|相談|修正|更新|追加|対応|整理|申請|依頼|払う|振込|送付/u', $text)
    ) {
        return false;
    }
    // Reject: starts with referential or collective expressions
    if (preg_match('/^(?:全部|全て|それ|これ|あれ|三つ|二つ|一つ|みんな|全員)/u', $text)) {
        return false;
    }
    // Default: accept (conservative — prefer false negatives over false positives)
    return true;
}

/**
 * Returns a light conversational reply for acknowledgements/greetings, or null if not matched.
 * Operates on saveTitle (date/time-stripped).
 */
function isConversation(string $text): ?string
{
    $n = mb_strtolower($text, 'UTF-8');
    $n = preg_replace('/[\s　]+/u', '', $n);
    $n = preg_replace('/[\p{P}\p{S}]+$/u', '', $n);

    if (preg_match('/ありがとう/u', $n)) {
        return 'どういたしまして！タスクがあれば送ってください。';
    }
    if (preg_match('/^(?:ok|了解|わかりました|わかった)/u', $n)) {
        return '了解です！タスクがあれば登録しますよ。';
    }
    if (preg_match('/^(?:よろしく|おねがい|お願い)/u', $n)) {
        return 'こちらこそよろしくお願いします！';
    }
    if (preg_match('/^(?:はい|うん)$/u', $n)) {
        return 'タスクがあれば送ってください！';
    }
    if (preg_match('/^(?:いいえ|違う|ちがう)$/u', $n)) {
        return 'わかりました！';
    }
    return null;
}

/**
 * Returns guidance for unsupported bulk operations, or null if not matched.
 */
function isUnsupportedCommand(string $text): ?string
{
    if (preg_match('/全部削除|一括削除|全削除|全て削除|まとめて削除/u', $text)) {
        return implode("\n", [
            '一括削除には対応していません。',
            '削除するタスク番号を指定してください。',
            '',
            '例：「削除 1」',
        ]);
    }
    return null;
}

/*
|--------------------------------------------------------------------------
| "今のは今日" shortcut detector
|--------------------------------------------------------------------------
*/
function isModifyToToday(string $text): bool
{
    return (bool) preg_match('/^(?:今のは今日|今のやつ今日|さっきの今日)(?:です)?$/u', trim($text));
}

function isModifyToTomorrow(string $text): bool
{
    return (bool) preg_match('/^(?:今のは明日|今のやつ明日|さっきの明日)(?:です)?$/u', trim($text));
}

function isModifyToTime(string $text): bool
{
    return (bool) preg_match(
        '/^(?:今のは|今のやつ|さっきの)(\d{1,2}時半|\d{1,2}時\d{1,2}分|\d{1,2}時|\d{1,2}:\d{2})(?:です)?$/u',
        trim($text)
    );
}

function extractShortcutTime(string $text): ?string
{
    if (preg_match(
        '/^(?:今のは|今のやつ|さっきの)(\d{1,2}時半|\d{1,2}時\d{1,2}分|\d{1,2}時|\d{1,2}:\d{2})(?:です)?$/u',
        trim($text),
        $m
    )) {
        return $m[1];
    }
    return null;
}

/*
|--------------------------------------------------------------------------
| Task list page renderer
|--------------------------------------------------------------------------
*/
function render_task_list_page(
    array $allTasks,
    int $page,
    string $listToday,
    string $listTomorrow
): string {
    $pageSize   = 5;
    $total      = count($allTasks);
    $totalPages = max(1, (int) ceil($total / $pageSize));
    $page       = max(1, min($page, $totalPages));
    $offset     = ($page - 1) * $pageSize;
    $pageTasks  = array_slice($allTasks, $offset, $pageSize);

    // Group tasks on this page (today / 直近 / 期限なし)
    // tomorrow + other are merged into 直近の予定
    $groups = ['today' => [], 'kinsetsu' => [], 'none' => []];
    foreach ($pageTasks as $idx => $t) {
        $num = $offset + $idx + 1;
        if (empty($t['due_date'])) {
            $groups['none'][] = ['num' => $num, 'task' => $t];
        } elseif ($t['due_date'] === $listToday) {
            $groups['today'][] = ['num' => $num, 'task' => $t];
        } else {
            $groups['kinsetsu'][] = ['num' => $num, 'task' => $t];
        }
    }

    $header = '未完了タスク一覧（' . $page . '/' . $totalPages . '）';
    $lines = [$header];

    // ■ 今日
    if (!empty($groups['today'])) {
        $lines[] = '';
        $lines[] = '■ 今日（' . count($groups['today']) . '件）';
        foreach ($groups['today'] as $e) {
            $t    = $e['task'];
            $line = $e['num'] . '. ' . $t['title'];
            if (!empty($t['due_time'])) {
                $line .= '（' . $t['due_time'] . '）';
            }
            $lines[] = $line;
        }
    }

    // ■ 今後の予定（明日以降の期日あり）
    if (!empty($groups['kinsetsu'])) {
        $lines[] = '';
        $lines[] = '■ 今後の予定（' . count($groups['kinsetsu']) . '件）';
        foreach ($groups['kinsetsu'] as $e) {
            $t         = $e['task'];
            $d         = new DateTime($t['due_date']);
            $dateLabel = $d->format('n') . '/' . $d->format('j');
            $line      = $e['num'] . '. ' . $t['title'];
            if (!empty($t['due_time'])) {
                $line .= '（' . $dateLabel . ' ' . $t['due_time'] . '）';
            } else {
                $line .= '（' . $dateLabel . '）';
            }
            $lines[] = $line;
        }
    }

    // ■ 期限なし
    if (!empty($groups['none'])) {
        $lines[] = '';
        $lines[] = '■ 期限なし（' . count($groups['none']) . '件）';
        foreach ($groups['none'] as $e) {
            $line = $e['num'] . '. ' . $e['task']['title'];
            if (!empty($e['task']['due_time'])) {
                $line .= '（' . $e['task']['due_time'] . '）';
            }
            $lines[] = $line;
        }
    }

    // Action footer
    $firstNum = $offset + 1;
    $lines[]  = '';
    $lines[]  = '→ 完了する場合：「完了 ' . $firstNum . '」';
    if ($page < $totalPages) {
        $lines[] = '→ 続きを見る：「次」';
    }

    return implode("\n", $lines);
}

/*
|--------------------------------------------------------------------------
| No-due-date task list page renderer
|--------------------------------------------------------------------------
*/
function render_nodue_list_page(array $allTasks, int $page): string
{
    $pageSize   = 5;
    $total      = count($allTasks);
    $totalPages = max(1, (int) ceil($total / $pageSize));
    $page       = max(1, min($page, $totalPages));
    $offset     = ($page - 1) * $pageSize;
    $pageTasks  = array_slice($allTasks, $offset, $pageSize);

    $header = ($totalPages > 1)
        ? '期限なしタスク（' . $page . '/' . $totalPages . '）'
        : '期限なしタスク（' . $total . '件）';

    $lines = [$header, ''];
    foreach ($pageTasks as $idx => $t) {
        $lines[] = ($offset + $idx + 1) . '. ' . $t['title'];
    }

    $firstNum = $offset + 1;
    $lines[]  = '';
    $lines[]  = '→ 完了する場合：「完了 ' . $firstNum . '」';
    if ($page < $totalPages) {
        $lines[] = '→ 続きを見る：「次」';
    }

    return implode("\n", $lines);
}

/*
|--------------------------------------------------------------------------
| Tag task list page renderer
|--------------------------------------------------------------------------
*/
function render_tag_list_page(array $allTasks, int $page, string $tag, string $listToday): string
{
    $pageSize   = 5;
    $total      = count($allTasks);
    $totalPages = max(1, (int) ceil($total / $pageSize));
    $page       = max(1, min($page, $totalPages));
    $offset     = ($page - 1) * $pageSize;
    $pageTasks  = array_slice($allTasks, $offset, $pageSize);

    $header = ($totalPages > 1)
        ? '#' . $tag . ' のタスク（' . $page . '/' . $totalPages . '）'
        : '#' . $tag . ' のタスク（' . $total . '件）';

    $lines = [$header, ''];
    foreach ($pageTasks as $idx => $t) {
        $num    = $offset + $idx + 1;
        $line   = $num . '. ' . $t['title'];
        $detail = '';
        if (!empty($t['due_date'])) {
            if ($t['due_date'] === $listToday) {
                $detail = '今日';
            } else {
                $d      = new DateTime($t['due_date']);
                $detail = $d->format('n') . '/' . $d->format('j');
            }
        }
        if (!empty($t['due_time'])) {
            $detail = ($detail !== '') ? $detail . ' ' . $t['due_time'] : $t['due_time'];
        }
        if ($detail !== '') {
            $line .= '（' . $detail . '）';
        }
        $lines[] = $line;
    }

    $firstNum = $offset + 1;
    $lines[]  = '';
    $lines[]  = '→ 完了する場合：「完了 ' . $firstNum . '」';
    if ($page < $totalPages) {
        $lines[] = '→ 続きを見る：「次」';
    }

    return implode("\n", $lines);
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
$tokenRepo     = null;
try {
    $db            = new \FujiraManager\Storage\Database($config['db']);
    $userRepo      = new \FujiraManager\Storage\UserRepository($db);
    $taskRepo      = new \FujiraManager\Storage\TaskRepository($db);
    $convStateRepo = new \FujiraManager\Storage\ConvStateRepository($db);
    $tokenRepo     = new \FujiraManager\Storage\TokenRepository($db);
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
        $lineUserId = (string) ($event['source']['userId'] ?? '');

        // [DEBUG] checkpoint 1: follow branch entered
        webhook_log('follow event: entered', [
            'line_user_id'       => $lineUserId,
            'reply_token_exists' => ($replyToken !== ''),
            'userRepo_ready'     => ($userRepo !== null),
            'convStateRepo_ready'=> ($convStateRepo !== null),
        ]);

        // Register user and save onboarding state
        if ($lineUserId !== '' && $userRepo !== null && $convStateRepo !== null) {
            try {
                $user = $userRepo->findByLineUserId($lineUserId);
                if ($user === null) {
                    $followOwnerId = $userRepo->create($lineUserId);
                    webhook_log('user created on follow', ['line_user_id' => $lineUserId, 'owner_id' => $followOwnerId]);
                } else {
                    $followOwnerId = (int) $user['id'];
                }
                $state = $convStateRepo->getState($followOwnerId);
                if (empty($state['onboarding_started_at'])) {
                    $state['onboarding_started_at']    = (new DateTime('now', new DateTimeZone('Asia/Tokyo')))->format('Y-m-d H:i:s');
                    $state['onboarding_followup_sent'] = false;
                    $convStateRepo->saveState($followOwnerId, $state);
                    webhook_log('onboarding state saved', ['owner_id' => $followOwnerId]);
                } else {
                    // [DEBUG] checkpoint 2: already onboarded
                    webhook_log('follow event: onboarding already saved', ['owner_id' => $followOwnerId]);
                }
            } catch (\Throwable $e) {
                webhook_log('follow event state save failed', ['error' => $e->getMessage()]);
            }
        }

        // [DEBUG] checkpoint 3: about to reply
        webhook_log('follow event: about to reply', ['reply_token_exists' => ($replyToken !== '')]);

        if ($replyToken !== '') {
            $welcomeText = implode("\n", [
                'はじめまして、フジラマネージャーです。',
                '',
                'LINEでタスク管理ができます。',
                '',
                'まずは1つだけ送ってみてください👇',
                '',
                '「今日 やること1つ」',
                '「今から作業」',
                '「請求書確認」',
                '',
                'これだけで登録されます。',
                '',
                '---',
                '',
                '登録したら👇',
                '「今日」と送ると一覧が出ます。',
                'そのまま「完了1」で消せます。',
            ]);
            line_reply($replyToken, $welcomeText);
        }

        // [DEBUG] checkpoint 4: follow handler done
        webhook_log('follow event: done');
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
    $text = preg_replace('/\r\n|\r|\n/u', ' ', $text);
    $text = trim(preg_replace('/[ 　]+/u', ' ', $text));
    $text = preg_replace('/^(?:今から|とりあえず|まずは|今日やること[\s　]+|やること[\s　]+)/u', '', $text);
    if (mb_strlen($text, 'UTF-8') > 2) {
        $text = trim(preg_replace('/(だよ|だね|ね|よ)$/u', '', $text));
    }
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

    // Tutorial step: 3+ = complete (normal user), 0-2 = in-progress
    $tutorialStep = ($user !== null) ? (int) ($user['tutorial_step'] ?? 3) : 0;

    // History command
    if ($text === '履歴' || $text === '/history') {
        $replyText = '完了済みタスクはありません';
        if ($ownerId !== null && $taskRepo !== null) {
            try {
                $doneTasks = $taskRepo->getDoneTasksByOwner($ownerId);
                if (!empty($doneTasks)) {
                    $doneMap = [];
                    $lines   = ['完了済みタスク:'];
                    foreach ($doneTasks as $i => $t) {
                        $num           = $i + 1;
                        $doneMap[(string)$num] = (int)$t['id'];
                        $lines[]       = $num . '. ' . $t['title'];
                    }
                    $replyText = implode("\n", $lines);

                    if ($convStateRepo !== null) {
                        try {
                            $state = $convStateRepo->getState($ownerId);
                            $state['last_done_task_list_map'] = $doneMap;
                            $convStateRepo->saveState($ownerId, $state);
                        } catch (\Throwable $e) {
                            webhook_log('conv_state save failed', ['error' => $e->getMessage()]);
                        }
                    }
                }
            } catch (\Throwable $e) {
                webhook_log('task history failed', ['error' => $e->getMessage()]);
                $replyText = '履歴取得に失敗しました';
            }
        }
        if ($replyToken !== '') {
            line_reply($replyToken, $replyText);
        }
        continue;
    }

    // Undo command
    if ($text === '戻す') {
        if ($replyToken !== '') {
            line_reply($replyToken, '取り消すタスク番号を指定してください（例: 戻す 1）');
        }
        continue;
    }

    if (preg_match('/^(?:戻す|\/undo)\s+(\d+)$/u', $text, $matches)) {
        $num    = (int) $matches[1];
        $taskId = $num;

        if ($num < 1) {
            webhook_log('task command invalid', ['command' => 'undo', 'input' => $num, 'owner_id' => $ownerId]);
            if ($replyToken !== '') {
                line_reply($replyToken, '番号の指定が不正です');
            }
            continue;
        }

        // Resolve done list number → task_id via conv_state
        if ($ownerId !== null && $convStateRepo !== null) {
            try {
                $state   = $convStateRepo->getState($ownerId);
                $doneMap = $state['last_done_task_list_map'] ?? [];
                if (isset($doneMap[(string)$num])) {
                    $taskId = (int) $doneMap[(string)$num];
                }
                webhook_log('resolved taskId', ['command' => 'undo', 'input' => $num, 'resolved' => $taskId]);
            } catch (\Throwable $e) {
                webhook_log('task command resolve failed', ['command' => 'undo', 'input' => $num, 'owner_id' => $ownerId, 'error' => $e->getMessage()]);
            }
        }

        $replyText = '該当する完了済みタスクが見つかりません';
        if ($ownerId !== null && $taskRepo !== null) {
            try {
                $reopened = $taskRepo->reopenTaskById($ownerId, $taskId);
                if ($reopened !== null) {
                    $replyText = "未完了に戻しました:\n・" . $reopened['title'];
                    webhook_log('task reopened', ['owner_id' => $ownerId, 'task_id' => $taskId]);
                }
            } catch (\Throwable $e) {
                webhook_log('task undo failed', ['owner_id' => $ownerId, 'input' => $num, 'task_id' => $taskId, 'error' => $e->getMessage()]);
                $replyText = 'タスク取り消し処理に失敗しました';
            }
        }
        if ($replyToken !== '') {
            line_reply($replyToken, $replyText);
        }
        continue;
    }

    // Task list command
    if ($text === '一覧' || $text === '/list') {
        $replyText  = '現在のタスクはありません';
        $quickReply = null;
        if ($ownerId !== null && $taskRepo !== null) {
            try {
                $tz           = new DateTimeZone('Asia/Tokyo');
                $listToday    = (new DateTime('now', $tz))->format('Y-m-d');
                $listTomorrow = (new DateTime('tomorrow', $tz))->format('Y-m-d');
                $allTasks     = $taskRepo->getOpenTasksByOwner($ownerId, $listToday, $listTomorrow);
                if (!empty($allTasks)) {
                    $map = [];
                    foreach ($allTasks as $i => $t) {
                        $map[(string)($i + 1)] = (int) $t['id'];
                    }
                    $page       = 1;
                    $totalPages = max(1, (int) ceil(count($allTasks) / 5));
                    $replyText  = render_task_list_page($allTasks, $page, $listToday, $listTomorrow);
                    $quickReply = build_list_quick_reply($page, $totalPages);

                    if ($convStateRepo !== null) {
                        webhook_log('list map save attempt', ['owner_id' => $ownerId, 'total' => count($allTasks)]);
                        try {
                            $state                        = $convStateRepo->getState($ownerId);
                            $state['last_task_list_map']  = $map;
                            $state['last_list_page']      = $page;
                            $state['last_list_today']     = $listToday;
                            $state['last_list_tomorrow']  = $listTomorrow;
                            $state['last_list_mode']      = 'all';
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
            line_reply($replyToken, $replyText, $quickReply);
        }
        continue;
    }

    // No-due-date task list command
    if ($text === '期限なし' || $text === '未定') {
        $replyText  = '期限なしタスクはありません。';
        $quickReply = null;
        if ($ownerId !== null && $taskRepo !== null) {
            try {
                $allTasks = $taskRepo->getAllNoDueDateTasksByOwner($ownerId);
                if (!empty($allTasks)) {
                    $map = [];
                    foreach ($allTasks as $i => $t) {
                        $map[(string)($i + 1)] = (int) $t['id'];
                    }
                    $page       = 1;
                    $totalPages = max(1, (int) ceil(count($allTasks) / 5));
                    $replyText  = render_nodue_list_page($allTasks, $page);
                    $quickReply = build_nodue_quick_reply($page, $totalPages);

                    if ($convStateRepo !== null) {
                        try {
                            $state                       = $convStateRepo->getState($ownerId);
                            $state['last_task_list_map'] = $map;
                            $state['last_list_page']     = $page;
                            $state['last_list_mode']     = 'nodue';
                            $convStateRepo->saveState($ownerId, $state);
                        } catch (\Throwable $e) {
                            webhook_log('nodue list state save failed', ['error' => $e->getMessage()]);
                        }
                    }
                }
            } catch (\Throwable $e) {
                webhook_log('nodue list failed', ['error' => $e->getMessage()]);
                $replyText = 'タスク取得に失敗しました';
            }
        }
        if ($replyToken !== '') {
            line_reply($replyToken, $replyText, $quickReply);
        }
        continue;
    }

    // Next page command
    if ($text === '次' || $text === '/next') {
        $replyText  = '先に「一覧」を表示してから「次」を使ってください';
        $quickReply = null;
        if ($ownerId !== null && $taskRepo !== null && $convStateRepo !== null) {
            try {
                $state        = $convStateRepo->getState($ownerId);
                $currentPage  = (int) ($state['last_list_page'] ?? 0);
                $lastToday    = $state['last_list_today'] ?? null;
                $lastTomorrow = $state['last_list_tomorrow'] ?? null;

                $listMode = $state['last_list_mode'] ?? 'all';
                if ($listMode === 'tag' && $currentPage > 0) {
                    $lastTag    = $state['last_list_tag'] ?? null;
                    $lastToday2 = $state['last_list_today'] ?? null;
                    if ($lastTag !== null && $lastToday2 !== null) {
                        $allTasks   = $taskRepo->getOpenTasksByTag($ownerId, $lastTag);
                        $totalPages = max(1, (int) ceil(count($allTasks) / 5));

                        if ($currentPage >= $totalPages) {
                            $replyText = 'これが最後のページです。';
                        } else {
                            $newPage = $currentPage + 1;
                            $map     = [];
                            foreach ($allTasks as $i => $t) {
                                $map[(string)($i + 1)] = (int) $t['id'];
                            }
                            $replyText  = render_tag_list_page($allTasks, $newPage, $lastTag, $lastToday2);
                            $quickReply = build_tag_quick_reply($newPage, $totalPages);

                            $state['last_task_list_map'] = $map;
                            $state['last_list_page']     = $newPage;
                            $convStateRepo->saveState($ownerId, $state);
                        }
                        webhook_log('next page (tag)', ['owner_id' => $ownerId, 'tag' => $lastTag, 'page' => $currentPage, 'total_pages' => $totalPages]);
                    }
                } elseif ($listMode === 'nodue' && $currentPage > 0) {
                    $allTasks   = $taskRepo->getAllNoDueDateTasksByOwner($ownerId);
                    $totalPages = max(1, (int) ceil(count($allTasks) / 5));

                    if ($currentPage >= $totalPages) {
                        $replyText = 'これが最後のページです。';
                    } else {
                        $newPage = $currentPage + 1;
                        $map     = [];
                        foreach ($allTasks as $i => $t) {
                            $map[(string)($i + 1)] = (int) $t['id'];
                        }
                        $replyText  = render_nodue_list_page($allTasks, $newPage);
                        $quickReply = build_nodue_quick_reply($newPage, $totalPages);

                        $state['last_task_list_map'] = $map;
                        $state['last_list_page']     = $newPage;
                        $convStateRepo->saveState($ownerId, $state);
                    }
                    webhook_log('next page (nodue)', ['owner_id' => $ownerId, 'page' => $currentPage, 'total_pages' => $totalPages]);
                } elseif ($listMode === 'all' && $currentPage > 0 && $lastToday !== null && $lastTomorrow !== null) {
                    $allTasks   = $taskRepo->getOpenTasksByOwner($ownerId, $lastToday, $lastTomorrow);
                    $pageSize   = 5;
                    $totalPages = max(1, (int) ceil(count($allTasks) / $pageSize));

                    if ($currentPage >= $totalPages) {
                        $replyText = 'これが最後のページです。';
                    } else {
                        $newPage = $currentPage + 1;
                        $map     = [];
                        foreach ($allTasks as $i => $t) {
                            $map[(string)($i + 1)] = (int) $t['id'];
                        }
                        $replyText  = render_task_list_page($allTasks, $newPage, $lastToday, $lastTomorrow);
                        $quickReply = build_list_quick_reply($newPage, $totalPages);

                        $state['last_task_list_map'] = $map;
                        $state['last_list_page']     = $newPage;
                        $convStateRepo->saveState($ownerId, $state);
                    }
                    webhook_log('next page', ['owner_id' => $ownerId, 'page' => $currentPage, 'total_pages' => $totalPages]);
                }
            } catch (\Throwable $e) {
                webhook_log('next page failed', ['error' => $e->getMessage()]);
                $replyText = 'ページ表示に失敗しました';
            }
        }
        if ($replyToken !== '') {
            line_reply($replyToken, $replyText, $quickReply);
        }
        continue;
    }

    // Previous page command
    if ($text === '前' || $text === '/prev') {
        $replyText  = '先に「一覧」を表示してから「前」を使ってください';
        $quickReply = null;
        if ($ownerId !== null && $taskRepo !== null && $convStateRepo !== null) {
            try {
                $state        = $convStateRepo->getState($ownerId);
                $currentPage  = (int) ($state['last_list_page'] ?? 0);
                $lastToday    = $state['last_list_today'] ?? null;
                $lastTomorrow = $state['last_list_tomorrow'] ?? null;

                $listMode = $state['last_list_mode'] ?? 'all';
                if ($listMode === 'tag' && $currentPage > 0) {
                    $lastTag    = $state['last_list_tag'] ?? null;
                    $lastToday2 = $state['last_list_today'] ?? null;
                    if ($lastTag !== null && $lastToday2 !== null) {
                        if ($currentPage <= 1) {
                            $replyText = 'これが最初のページです。';
                        } else {
                            $allTasks   = $taskRepo->getOpenTasksByTag($ownerId, $lastTag);
                            $newPage    = $currentPage - 1;
                            $totalPages = max(1, (int) ceil(count($allTasks) / 5));
                            $map        = [];
                            foreach ($allTasks as $i => $t) {
                                $map[(string)($i + 1)] = (int) $t['id'];
                            }
                            $replyText  = render_tag_list_page($allTasks, $newPage, $lastTag, $lastToday2);
                            $quickReply = build_tag_quick_reply($newPage, $totalPages);

                            $state['last_task_list_map'] = $map;
                            $state['last_list_page']     = $newPage;
                            $convStateRepo->saveState($ownerId, $state);
                        }
                        webhook_log('prev page (tag)', ['owner_id' => $ownerId, 'tag' => $lastTag, 'page' => $currentPage]);
                    }
                } elseif ($listMode === 'nodue' && $currentPage > 0) {
                    if ($currentPage <= 1) {
                        $replyText = 'これが最初のページです。';
                    } else {
                        $allTasks   = $taskRepo->getAllNoDueDateTasksByOwner($ownerId);
                        $newPage    = $currentPage - 1;
                        $totalPages = max(1, (int) ceil(count($allTasks) / 5));
                        $map        = [];
                        foreach ($allTasks as $i => $t) {
                            $map[(string)($i + 1)] = (int) $t['id'];
                        }
                        $replyText  = render_nodue_list_page($allTasks, $newPage);
                        $quickReply = build_nodue_quick_reply($newPage, $totalPages);

                        $state['last_task_list_map'] = $map;
                        $state['last_list_page']     = $newPage;
                        $convStateRepo->saveState($ownerId, $state);
                    }
                    webhook_log('prev page (nodue)', ['owner_id' => $ownerId, 'page' => $currentPage]);
                } elseif ($listMode === 'all' && $currentPage > 0 && $lastToday !== null && $lastTomorrow !== null) {
                    if ($currentPage <= 1) {
                        $replyText = 'これが最初のページです。';
                    } else {
                        $allTasks   = $taskRepo->getOpenTasksByOwner($ownerId, $lastToday, $lastTomorrow);
                        $newPage    = $currentPage - 1;
                        $totalPages = max(1, (int) ceil(count($allTasks) / 5));
                        $map        = [];
                        foreach ($allTasks as $i => $t) {
                            $map[(string)($i + 1)] = (int) $t['id'];
                        }
                        $replyText  = render_task_list_page($allTasks, $newPage, $lastToday, $lastTomorrow);
                        $quickReply = build_list_quick_reply($newPage, $totalPages);

                        $state['last_task_list_map'] = $map;
                        $state['last_list_page']     = $newPage;
                        $convStateRepo->saveState($ownerId, $state);
                    }
                    webhook_log('prev page', ['owner_id' => $ownerId, 'page' => $currentPage]);
                }
            } catch (\Throwable $e) {
                webhook_log('prev page failed', ['error' => $e->getMessage()]);
                $replyText = 'ページ表示に失敗しました';
            }
        }
        if ($replyToken !== '') {
            line_reply($replyToken, $replyText, $quickReply);
        }
        continue;
    }

    // Normalize full-width digits and full-width space for 完了/削除 commands
    $normText = strtr($text, [
        '０' => '0', '１' => '1', '２' => '2', '３' => '3', '４' => '4',
        '５' => '5', '６' => '6', '７' => '7', '８' => '8', '９' => '9',
        '　' => ' ',
    ]);

    // Task complete command
    if ($text === '完了') {
        if ($replyToken !== '') {
            line_reply($replyToken, '完了するタスク番号を指定してください（例: 完了 1）');
        }
        continue;
    }

    if (preg_match('/^(?:完了|\/done)\s*(\d+)$/u', $normText, $matches)) {
        $num    = (int) $matches[1];
        $taskId = $num;

        if ($num < 1) {
            webhook_log('task command invalid', ['command' => 'complete', 'input' => $num, 'owner_id' => $ownerId]);
            if ($replyToken !== '') {
                line_reply($replyToken, '番号の指定が不正です');
            }
            continue;
        }

        // Resolve list number → task_id via conv_state
        // Raw ID fallback is intentionally removed: if no map or key is missing, reject the request.
        $resolvedTaskId = null;
        if ($ownerId !== null && $convStateRepo !== null) {
            try {
                $state = $convStateRepo->getState($ownerId);
                $map   = $state['last_task_list_map'] ?? [];
                if (isset($map[(string)$num])) {
                    $resolvedTaskId = (int) $map[(string)$num];
                }
                webhook_log('resolved taskId', ['input' => $num, 'resolved' => $resolvedTaskId, 'map_size' => count($map)]);
            } catch (\Throwable $e) {
                webhook_log('task command resolve failed', ['command' => 'complete', 'input' => $num, 'owner_id' => $ownerId, 'error' => $e->getMessage()]);
            }
        }

        if ($resolvedTaskId === null) {
            webhook_log('task complete rejected: no map entry', ['input' => $num, 'owner_id' => $ownerId]);
            if ($replyToken !== '') {
                line_reply($replyToken, "「今日」や「一覧」で表示した後に「完了{$num}」と送ってください");
            }
            continue;
        }
        $taskId = $resolvedTaskId;

        $replyText = '該当するタスクが見つかりません';
        if ($ownerId !== null && $taskRepo !== null) {
            try {
                $done = $taskRepo->completeOpenTaskById($ownerId, $taskId);
                if ($done !== null) {
                    $tz           = new DateTimeZone('Asia/Tokyo');
                    $doneToday    = (new DateTime('now', $tz))->format('Y-m-d');
                    $doneTomorrow = (new DateTime('tomorrow', $tz))->format('Y-m-d');

                    $suffix = '';
                    if (!empty($done['due_date'])) {
                        if ($done['due_date'] === $doneToday) {
                            $dateLbl = '今日';
                        } elseif ($done['due_date'] === $doneTomorrow) {
                            $dateLbl = '明日';
                        } else {
                            $dateLbl = $done['due_date'];
                        }
                        $suffix = !empty($done['due_time'])
                            ? '（' . $dateLbl . ' ' . $done['due_time'] . '）'
                            : '（' . $dateLbl . '）';
                    } elseif (!empty($done['due_time'])) {
                        $suffix = '（' . $done['due_time'] . '）';
                    }

                    $nextTasks = $taskRepo->getTodayTasksByOwner($ownerId, $doneToday);
                    if (empty($nextTasks)) {
                        $nextText = '今日のタスクはすべて完了です！🎉';
                    } else {
                        $next      = $nextTasks[0];
                        $nextLabel = (!empty($next['due_time'])) ? $next['due_time'] . ' ' . $next['title'] : $next['title'];
                        $nextText  = "次はこれです👇\n・" . $nextLabel . "\n\n「今日」で一覧を確認できます。";
                    }
                    $replyText = "ナイスです。\n・" . $done['title'] . $suffix . " を完了しました。\n\n" . $nextText;
                    webhook_log('task completed', ['owner_id' => $ownerId, 'task_id' => $taskId]);
                }
            } catch (\Throwable $e) {
                webhook_log('task complete failed', ['owner_id' => $ownerId, 'input' => $num, 'task_id' => $taskId, 'error' => $e->getMessage()]);
                $replyText = 'タスク完了処理に失敗しました';
            }
        }
        if ($replyToken !== '') {
            line_reply($replyToken, $replyText);
        }
        continue;
    }

    // Task delete command
    if ($text === '削除') {
        if ($replyToken !== '') {
            line_reply($replyToken, '削除するタスク番号を指定してください（例: 削除 1）');
        }
        continue;
    }

    if (preg_match('/^(?:削除|\/delete|\/del)\s*(\d+)$/u', $normText, $matches)) {
        $num    = (int) $matches[1];
        $taskId = $num;

        if ($num < 1) {
            webhook_log('task command invalid', ['command' => 'delete', 'input' => $num, 'owner_id' => $ownerId]);
            if ($replyToken !== '') {
                line_reply($replyToken, '番号の指定が不正です');
            }
            continue;
        }

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
                webhook_log('task command resolve failed', ['command' => 'delete', 'input' => $num, 'owner_id' => $ownerId, 'error' => $e->getMessage()]);
            }
        }

        $replyText = '該当するタスクが見つかりません';
        if ($ownerId !== null && $taskRepo !== null) {
            try {
                $deleted = $taskRepo->deleteOpenTaskById($ownerId, $taskId);
                if ($deleted !== null) {
                    $replyText = "削除しました:\n・" . $deleted['title'];
                    webhook_log('task deleted', ['owner_id' => $ownerId, 'task_id' => $taskId]);
                }
            } catch (\Throwable $e) {
                webhook_log('task delete failed', ['owner_id' => $ownerId, 'input' => $num, 'task_id' => $taskId, 'error' => $e->getMessage()]);
                $replyText = 'タスク削除処理に失敗しました';
            }
        }
        if ($replyToken !== '') {
            line_reply($replyToken, $replyText);
        }
        continue;
    }

    // Tomorrow command ("明日" alone — "明日 XXX" goes to task save)
    if ($text === '明日' || $text === '/tomorrow') {
        $replyText = '明日が期限のタスクはありません';
        if ($ownerId !== null && $taskRepo !== null) {
            try {
                $d = new DateTime('now', new DateTimeZone('Asia/Tokyo'));
                $d->modify('+1 day');
                $tomorrow      = $d->format('Y-m-d');
                $tomorrowTasks = $taskRepo->getTomorrowTasksByOwner($ownerId, $tomorrow);
                if (!empty($tomorrowTasks)) {
                    $lines = ['明日のタスク:'];
                    foreach ($tomorrowTasks as $i => $t) {
                        $lines[] = ($i + 1) . '. ' . $t['title'];
                    }
                    $replyText = implode("\n", $lines);
                }
            } catch (\Throwable $e) {
                webhook_log('task tomorrow failed', ['error' => $e->getMessage()]);
                $replyText = '明日のタスク取得に失敗しました';
            }
        }
        if ($replyToken !== '') {
            line_reply($replyToken, $replyText);
        }
        continue;
    }

    // Today command ("今日" alone — "今日 XXX" goes to task save)
    if ($text === '今日' || $text === '/today') {
        $nowJst   = new DateTime('now', new DateTimeZone('Asia/Tokyo'));
        $hour     = (int) $nowJst->format('H');
        if ($hour >= 5 && $hour < 11) {
            $greeting = 'おはようございます。';
        } elseif ($hour < 18) {
            $greeting = 'こんにちは。';
        } else {
            $greeting = 'こんばんは。';
        }

        $replyText  = $greeting . "\n\n今日のタスクはありません\n\n👉 1つ追加してみましょう";
        $quickReply = null;
        if ($ownerId !== null && $taskRepo !== null) {
            try {
                $today      = $nowJst->format('Y-m-d');
                $todayTasks = $taskRepo->getTodayTasksByOwner($ownerId, $today);
                if (!empty($todayTasks)) {
                    $map = [];
                    $lines = [$greeting, '', '今日のタスク:'];
                    foreach ($todayTasks as $i => $t) {
                        $num  = $i + 1;
                        $map[(string) $num] = (int) $t['id'];
                        $line = $num . '. ' . $t['title'];
                        if (!empty($t['due_time'])) {
                            $line .= '（' . $t['due_time'] . '）';
                        }
                        $lines[] = $line;
                    }
                    $replyText  = implode("\n", $lines);
                    $quickReply = build_today_quick_reply(count($todayTasks));

                    if ($convStateRepo !== null) {
                        try {
                            $state                       = $convStateRepo->getState($ownerId);
                            $state['last_task_list_map'] = $map;
                            $state['last_list_page']     = 1;
                            $state['last_list_mode']     = 'today';
                            $convStateRepo->saveState($ownerId, $state);
                        } catch (\Throwable $e) {
                            webhook_log('today map save failed', ['error' => $e->getMessage()]);
                        }
                    }
                }
            } catch (\Throwable $e) {
                webhook_log('task today failed', ['error' => $e->getMessage()]);
                $replyText = '今日のタスク取得に失敗しました';
            }
        }
        // Tutorial hint on 今日 command (STEP 0 → 1)
        if ($tutorialStep === 0 && $ownerId !== null && $userRepo !== null) {
            try {
                $userRepo->advanceTutorialStep($ownerId);
                $replyText .= "\n\n---\nタスクを送ると登録できます（例：「14時 歯医者」）";
            } catch (\Throwable $e) {
                webhook_log('tutorial advance failed', ['error' => $e->getMessage()]);
            }
        }
        if ($replyToken !== '') {
            line_reply($replyToken, $replyText, $quickReply);
        }
        continue;
    }

    // /ping
    if ($text === '/ping') {
        if ($replyToken !== '') {
            line_reply($replyToken, 'pong');
        }
        continue;
    }

    // Brief on/off
    if ($text === 'brief on' || $text === 'ブリーフオン') {
        $replyText = '朝ブリーフを有効にしました';
        if ($ownerId !== null && $userRepo !== null) {
            try {
                $userRepo->updateBriefEnabled($ownerId, 1);
                webhook_log('brief enabled', ['owner_id' => $ownerId]);
            } catch (\Throwable $e) {
                webhook_log('brief enable failed', ['owner_id' => $ownerId, 'error' => $e->getMessage()]);
                $replyText = '設定の更新に失敗しました';
            }
        }
        if ($replyToken !== '') {
            line_reply($replyToken, $replyText);
        }
        continue;
    }

    if ($text === 'brief off' || $text === 'ブリーフオフ') {
        $replyText = '朝ブリーフを停止しました';
        if ($ownerId !== null && $userRepo !== null) {
            try {
                $userRepo->updateBriefEnabled($ownerId, 0);
                webhook_log('brief disabled', ['owner_id' => $ownerId]);
            } catch (\Throwable $e) {
                webhook_log('brief disable failed', ['owner_id' => $ownerId, 'error' => $e->getMessage()]);
                $replyText = '設定の更新に失敗しました';
            }
        }
        if ($replyToken !== '') {
            line_reply($replyToken, $replyText);
        }
        continue;
    }

    // Date search: "M月D日" or "M/D" alone → return open tasks for that date
    if (preg_match('/^(\d{1,2})月(\d{1,2})日[ 　]*$/u', $text, $dm)
        || preg_match('/^(\d{1,2})\/(\d{1,2})[ 　]*$/u', $text, $dm)) {
        $tz    = new DateTimeZone('Asia/Tokyo');
        $year  = (int) (new DateTime('now', $tz))->format('Y');
        $date  = sprintf('%04d-%02d-%02d', $year, (int) $dm[1], (int) $dm[2]);
        $label = (int) $dm[1] . '/' . (int) $dm[2];
        $msg   = $label . ' の予定はありません。';
        if ($ownerId !== null && $taskRepo !== null) {
            try {
                $tasks = $taskRepo->getOpenTasksByDate($ownerId, $date);
                if (!empty($tasks)) {
                    $lines = [$label . ' の予定（' . count($tasks) . '件）', ''];
                    foreach ($tasks as $i => $t) {
                        $line = ($i + 1) . '. ' . $t['title'];
                        if (!empty($t['due_time'])) {
                            $line .= '（' . $t['due_time'] . '）';
                        }
                        $lines[] = $line;
                    }
                    $msg = implode("\n", $lines);
                }
                webhook_log('date search', ['owner_id' => $ownerId, 'date' => $date, 'count' => count($tasks)]);
            } catch (\Throwable $e) {
                webhook_log('date search failed', ['owner_id' => $ownerId, 'date' => $date, 'error' => $e->getMessage()]);
                $msg = '検索に失敗しました。';
            }
        }
        if ($replyToken !== '') {
            line_reply($replyToken, $msg);
        }
        continue;
    }

    // Tag search: "#タグ名" alone
    if (preg_match('/^#([\w\x{3000}-\x{9fff}\x{3040}-\x{309f}\x{30a0}-\x{30ff}\x{4e00}-\x{9fff}]+)$/u', $text, $tagMatch)) {
        $searchTag  = $tagMatch[1];
        $replyText  = '#' . $searchTag . ' のタスクはありません。';
        $quickReply = null;
        if ($ownerId !== null && $taskRepo !== null) {
            try {
                $tz        = new DateTimeZone('Asia/Tokyo');
                $listToday = (new DateTime('now', $tz))->format('Y-m-d');
                $allTasks  = $taskRepo->getOpenTasksByTag($ownerId, $searchTag);
                if (!empty($allTasks)) {
                    $map = [];
                    foreach ($allTasks as $i => $t) {
                        $map[(string)($i + 1)] = (int) $t['id'];
                    }
                    $page       = 1;
                    $totalPages = max(1, (int) ceil(count($allTasks) / 5));
                    $replyText  = render_tag_list_page($allTasks, $page, $searchTag, $listToday);
                    $quickReply = build_tag_quick_reply($page, $totalPages);

                    if ($convStateRepo !== null) {
                        try {
                            $state                       = $convStateRepo->getState($ownerId);
                            $state['last_task_list_map'] = $map;
                            $state['last_list_page']     = $page;
                            $state['last_list_mode']     = 'tag';
                            $state['last_list_tag']      = $searchTag;
                            $state['last_list_today']    = $listToday;
                            $convStateRepo->saveState($ownerId, $state);
                        } catch (\Throwable $e) {
                            webhook_log('tag list state save failed', ['error' => $e->getMessage()]);
                        }
                    }
                }
                webhook_log('tag search', ['owner_id' => $ownerId, 'tag' => $searchTag, 'count' => count($allTasks)]);
            } catch (\Throwable $e) {
                webhook_log('tag search failed', ['error' => $e->getMessage()]);
                $replyText = '検索に失敗しました。';
            }
        }
        if ($replyToken !== '') {
            line_reply($replyToken, $replyText, $quickReply);
        }
        continue;
    }

    // Conflict confirm: 登録する / やめる
    if ($text === '登録する' || $text === 'やめる') {
        $replyText = 'キャンセルしました';
        if ($ownerId !== null && $convStateRepo !== null) {
            try {
                $state = $convStateRepo->getState($ownerId);
                if (($state['mode'] ?? '') === 'confirm_conflict') {
                    if ($text === '登録する' && $taskRepo !== null) {
                        $pendingTitle = (string) ($state['pending_title']    ?? '');
                        $pendingDate  = $state['pending_due_date']  ?? null;
                        $pendingTime  = $state['pending_due_time']  ?? null;
                        $pendingTag   = $state['pending_tag']        ?? null;
                        if ($pendingTitle !== '') {
                            $taskId = $taskRepo->create($ownerId, $pendingTitle, $pendingDate, $pendingTime, $pendingTag);
                            webhook_log('conflict confirm: task created', ['owner_id' => $ownerId, 'task_id' => $taskId, 'title' => $pendingTitle, 'tag' => $pendingTag]);
                            $tz          = new DateTimeZone('Asia/Tokyo');
                            $msg         = "登録しました:\n・" . $pendingTitle;
                            if ($pendingDate !== null) {
                                $todayStr    = (new DateTime('now', $tz))->format('Y-m-d');
                                $tomorrowStr = (new DateTime('tomorrow', $tz))->format('Y-m-d');
                                $dateLbl     = ($pendingDate === $todayStr) ? '今日' : (($pendingDate === $tomorrowStr) ? '明日' : $pendingDate);
                                $msg        .= "\n期限：" . $dateLbl . ($pendingTime !== null ? ' ' . $pendingTime : '');
                            }
                            $replyText = $msg;
                        }
                    } else {
                        webhook_log('conflict confirm: cancelled', ['owner_id' => $ownerId]);
                    }
                    unset($state['mode'], $state['pending_title'], $state['pending_due_date'], $state['pending_due_time'], $state['pending_tag']);
                    $convStateRepo->saveState($ownerId, $state);
                }
            } catch (\Throwable $e) {
                webhook_log('conflict confirm failed', ['error' => $e->getMessage()]);
            }
        }
        if ($replyToken !== '') {
            line_reply($replyToken, $replyText);
        }
        continue;
    }

    // "今のは今日" / "今のやつ今日" / "さっきの今日" — set latest task due_date to today
    if (isModifyToToday($text) && $ownerId !== null && $taskRepo !== null) {
        try {
            $latestTask = $taskRepo->findLatestOpenTaskByOwnerId($ownerId);
            if ($latestTask === null) {
                if ($replyToken !== '') {
                    line_reply($replyToken, '更新できるタスクが見つかりませんでした。');
                }
                continue;
            }
            $todayDate = (new DateTime('now', new DateTimeZone('Asia/Tokyo')))->format('Y-m-d');
            $taskRepo->updateTaskSchedule((int) $latestTask['id'], $ownerId, ['due_date' => $todayDate]);
            webhook_log('task modified to today', ['owner_id' => $ownerId, 'task_id' => $latestTask['id'], 'title' => $latestTask['title']]);
            if ($replyToken !== '') {
                line_reply($replyToken, "更新しました：\n" . $latestTask['title'] . '（今日）');
            }
        } catch (\Throwable $e) {
            webhook_log('modify to today failed', ['owner_id' => $ownerId, 'error' => $e->getMessage()]);
            if ($replyToken !== '') {
                line_reply($replyToken, '修正処理中にエラーが発生しました。もう一度お試しください。');
            }
        }
        continue;
    }

    // "今のは明日" / "今のやつ明日" / "さっきの明日" — set latest task due_date to tomorrow
    if (isModifyToTomorrow($text) && $ownerId !== null && $taskRepo !== null) {
        try {
            $latestTask = $taskRepo->findLatestOpenTaskByOwnerId($ownerId);
            if ($latestTask === null) {
                if ($replyToken !== '') {
                    line_reply($replyToken, '更新できるタスクが見つかりませんでした。');
                }
                continue;
            }
            $tomorrowTz   = new DateTimeZone('Asia/Tokyo');
            $tomorrowDate = (new DateTime('tomorrow', $tomorrowTz))->format('Y-m-d');
            $taskRepo->updateTaskSchedule((int) $latestTask['id'], $ownerId, ['due_date' => $tomorrowDate]);
            webhook_log('task modified to tomorrow', ['owner_id' => $ownerId, 'task_id' => $latestTask['id'], 'title' => $latestTask['title']]);
            if ($replyToken !== '') {
                line_reply($replyToken, "更新しました：\n" . $latestTask['title'] . '（明日）');
            }
        } catch (\Throwable $e) {
            webhook_log('modify to tomorrow failed', ['owner_id' => $ownerId, 'error' => $e->getMessage()]);
            if ($replyToken !== '') {
                line_reply($replyToken, '修正処理中にエラーが発生しました。もう一度お試しください。');
            }
        }
        continue;
    }

    // "今のは10時" / "今のやつ19:30" / "さっきの15時半" — set latest task due_time only
    if (isModifyToTime($text) && $ownerId !== null && $taskRepo !== null) {
        try {
            $latestTask = $taskRepo->findLatestOpenTaskByOwnerId($ownerId);
            if ($latestTask === null) {
                if ($replyToken !== '') {
                    line_reply($replyToken, '更新できるタスクが見つかりませんでした。');
                }
                continue;
            }
            $normalizedTime = normalizeTime((string) extractShortcutTime($text));
            $taskRepo->updateTaskSchedule((int) $latestTask['id'], $ownerId, ['due_time' => $normalizedTime]);
            webhook_log('task modified time', ['owner_id' => $ownerId, 'task_id' => $latestTask['id'], 'title' => $latestTask['title'], 'due_time' => $normalizedTime]);

            $timeTz      = new DateTimeZone('Asia/Tokyo');
            $todayStr    = (new DateTime('now', $timeTz))->format('Y-m-d');
            $tomorrowStr = (new DateTime('tomorrow', $timeTz))->format('Y-m-d');
            $dateLabel   = '';
            if (!empty($latestTask['due_date'])) {
                if ($latestTask['due_date'] === $todayStr) {
                    $dateLabel = '今日 ';
                } elseif ($latestTask['due_date'] === $tomorrowStr) {
                    $dateLabel = '明日 ';
                } else {
                    $dateLabel = $latestTask['due_date'] . ' ';
                }
            }
            if ($replyToken !== '') {
                line_reply($replyToken, "更新しました：\n" . $latestTask['title'] . '（' . $dateLabel . $normalizedTime . '）');
            }
        } catch (\Throwable $e) {
            webhook_log('modify time failed', ['owner_id' => $ownerId, 'error' => $e->getMessage()]);
            if ($replyToken !== '') {
                line_reply($replyToken, '修正処理中にエラーが発生しました。もう一度お試しください。');
            }
        }
        continue;
    }

    // Direct amendment: "今のは..." / "さっきのは..." — must be evaluated before $isCommand guard
    if (preg_match('/^(?:今のは|さっきのは)(.*)/u', $text, $am) && $ownerId !== null && $taskRepo !== null) {
        webhook_log('amendment branch entered', ['owner_id' => $ownerId, 'text' => $text]);
        try {
            // Strip optional trailing noise ("です", "に変更" etc.) before keyword parsing
            $suffix    = trim(preg_replace('/(?:です|に変更|にして|してください)[\s　]*$/u', '', trim($am[1])));
            $amendTz   = new DateTimeZone('Asia/Tokyo');
            $amendDate = null;
            $amendTime = null;

            webhook_log('amendment suffix', ['suffix' => $suffix]);

            // Parse date component — keyword-based (not anchored to start of suffix)
            if (preg_match('/今日/u', $suffix)) {
                $amendDate = (new DateTime('now', $amendTz))->format('Y-m-d');
                $suffix    = trim(preg_replace('/今日/u', '', $suffix, 1));
            } elseif (preg_match('/明日/u', $suffix)) {
                $amendDate = (new DateTime('tomorrow', $amendTz))->format('Y-m-d');
                $suffix    = trim(preg_replace('/明日/u', '', $suffix, 1));
            }

            // Parse time component (specific → general)
            if (preg_match('/(\d{1,2}:\d{2})/u', $suffix, $tm)) {
                $amendTime = $tm[1];
            } elseif (preg_match('/(\d{1,2}時\d{1,2}分)/u', $suffix, $tm)) {
                $amendTime = $tm[1];
            } elseif (preg_match('/(\d{1,2}時半)/u', $suffix, $tm)) {
                $amendTime = $tm[1];
            } elseif (preg_match('/(\d{1,2}時)/u', $suffix, $tm)) {
                $amendTime = $tm[1];
            }

            webhook_log('amendment parsed', ['amendDate' => $amendDate, 'amendTime' => $amendTime]);

            if ($amendDate === null && $amendTime === null) {
                if ($replyToken !== '') {
                    line_reply($replyToken, '修正内容を認識できませんでした。（例：今のは今日10時）');
                }
                continue;
            }

            $latestTask = $taskRepo->findLatestOpenTaskByOwnerId($ownerId);
            webhook_log('amendment latest task', ['task' => $latestTask]);
            if ($latestTask === null) {
                if ($replyToken !== '') {
                    line_reply($replyToken, '修正できる直前のタスクが見つかりませんでした。');
                }
                continue;
            }

            $updates = [];
            $labels  = [];
            if ($amendDate !== null) {
                $updates['due_date'] = $amendDate;
                $todayStr            = (new DateTime('now', $amendTz))->format('Y-m-d');
                $labels[]            = ($amendDate === $todayStr) ? '今日' : '明日';
            }
            if ($amendTime !== null) {
                $normalized          = normalizeTime($amendTime);
                $updates['due_time'] = $normalized;
                $labels[]            = $normalized;
            }

            $taskRepo->updateTaskSchedule((int) $latestTask['id'], $ownerId, $updates);
            webhook_log('task amended', [
                'owner_id' => $ownerId,
                'task_id'  => $latestTask['id'],
                'title'    => $latestTask['title'],
                'updates'  => $updates,
            ]);

            if ($replyToken !== '') {
                line_reply($replyToken, '直前のタスクを「' . implode(' ', $labels) . '」に修正しました。');
            }
        } catch (\Throwable $e) {
            webhook_log('amendment failed', ['owner_id' => $ownerId, 'error' => $e->getMessage()]);
            if ($replyToken !== '') {
                line_reply($replyToken, '修正処理中にエラーが発生しました。もう一度お試しください。');
            }
        }
        continue;
    }

    // Save task
    $isCommand = ($text === '一覧'
        || $text === '/list'
        || $text === '次'
        || $text === '/next'
        || $text === '前'
        || $text === '/prev'
        || $text === '履歴'
        || $text === '/history'
        || $text === 'help'
        || $text === 'ヘルプ'
        || $text === '/help'
        || $text === '/brief'
        || $text === 'ブリーフ'
        || $text === '期限なし'
        || $text === '未定'
        || $text === '登録する'
        || $text === 'やめる'
        || $text === '解約'
        || $text === 'プラン'
        || $text === '課金');

    webhook_log('task attempt', ['owner_id' => $ownerId, 'text' => $text, 'is_command' => $isCommand]);

    if ($ownerId === null) {
        webhook_log('task skipped: owner_id is null', ['line_user_id' => $lineUserId]);
    }

    if (!$isCommand && $ownerId !== null && $taskRepo !== null && $text !== '') {
        // Detect prefix-only inputs with no content (e.g. "今日は", "明日の")
        if (preg_match('/^(?:今日|明日)(?:[ 　]+|の|は)?[ 　]*$/u', $text)) {
            webhook_log('task skipped: empty title after prefix strip', ['text' => $text, 'owner_id' => $ownerId]);
            if ($replyToken !== '') {
                line_reply($replyToken, implode("\n", [
                    '内容を入力してください。',
                    '',
                    '例：',
                    '「今日 13:30 歯医者」',
                    '「明日 税理士に連絡」',
                    '「請求書確認」',
                ]));
            }
            continue;
        }

        // Parse due_date from natural Japanese date prefixes
        // Order: specific → general (M月D日 / M/D before 今日 / 明日)
        $dueDate      = null;
        $dueTime      = null;
        $saveTitle    = $text;
        $tz           = new DateTimeZone('Asia/Tokyo');
        $datePattern  = 'no_match';

        if (preg_match('/^(\d{1,2})月(\d{1,2})日(?:[ 　]*(?:夕方は?|朝は|昼は|夜は|は|に|で|から|まで|の)[ 　]*|[ 　]+)?(.+)$/u', $text, $dm)) {
            $saveTitle   = trim($dm[3]);
            $year        = (int) (new DateTime('now', $tz))->format('Y');
            $dueDate     = sprintf('%04d-%02d-%02d', $year, (int) $dm[1], (int) $dm[2]);
            $datePattern = 'M月D日';
        } elseif (preg_match('/^(\d{1,2})\/(\d{1,2})(?:[ 　]*(?:夕方は?|朝は|昼は|夜は|は|に|で|から|まで|の)[ 　]*|[ 　]+)?(.+)$/u', $text, $dm)) {
            $saveTitle   = trim($dm[3]);
            $year        = (int) (new DateTime('now', $tz))->format('Y');
            $dueDate     = sprintf('%04d-%02d-%02d', $year, (int) $dm[1], (int) $dm[2]);
            $datePattern = 'M/D';
        } elseif (preg_match('/^今日(?:[ 　]+|[ 　]*(?:夕方は?|朝は|昼は|夜は|の|は)[ 　]*)?(.+)$/u', $text, $dm)) {
            $saveTitle   = trim($dm[1]);
            $dueDate     = (new DateTime('now', $tz))->format('Y-m-d');
            $datePattern = '今日';
        } elseif (preg_match('/^明日(?:[ 　]+|[ 　]*(?:夕方は?|朝は|昼は|夜は|の|は)[ 　]*)?(.+)$/u', $text, $dm)) {
            $saveTitle   = trim($dm[1]);
            $d           = new DateTime('now', $tz);
            $d->modify('+1 day');
            $dueDate     = $d->format('Y-m-d');
            $datePattern = '明日';
        }

        // Parse due_time from the start of saveTitle (leading time: C / A / B patterns)
        // Separator (space / に / から / の) is absorbed into the pattern
        $timePattern = 'no_match';
        if ($saveTitle !== '') {
            if (preg_match('/^(\d{1,2}:\d{2})(?:[ 　]+|に|から|の|まで|で)(.+)$/u', $saveTitle, $tm)) {
                $dueTime     = $tm[1];
                $saveTitle   = trim($tm[2]);
                $timePattern = 'leading_hhmm';
            } elseif (preg_match('/^(\d{1,2}時\d{1,2}分)(?:[ 　]+|に|から|の|まで|で)(.+)$/u', $saveTitle, $tm)) {
                $dueTime     = $tm[1];
                $saveTitle   = trim($tm[2]);
                $timePattern = 'leading_ji_fun';
            } elseif (preg_match('/^(\d{1,2}時(?:半)?)(?:[ 　]+|に|から|の|まで|で)(.+)$/u', $saveTitle, $tm)) {
                $dueTime     = $tm[1];
                $saveTitle   = trim($tm[2]);
                $timePattern = 'leading_ji';
            }
        }

        // Parse trailing time (title + time word order: D pattern)
        // Only when date is set and time not yet found
        if ($dueDate !== null && $dueTime === null && $saveTitle !== '') {
            if (preg_match('/^(.+?)[ 　]+(\d{1,2}:\d{2})$/u', $saveTitle, $tm)) {
                $saveTitle   = trim($tm[1]);
                $dueTime     = $tm[2];
                $timePattern = 'trailing_hhmm';
            } elseif (preg_match('/^(.+?)[ 　]+(\d{1,2}時半)$/u', $saveTitle, $tm)) {
                $saveTitle   = trim($tm[1]);
                $dueTime     = $tm[2];
                $timePattern = 'trailing_ji_han';
            } elseif (preg_match('/^(.+?)[ 　]+(\d{1,2}時)$/u', $saveTitle, $tm)) {
                $saveTitle   = trim($tm[1]);
                $dueTime     = $tm[2];
                $timePattern = 'trailing_ji';
            }
        }

        // Fallback: inline time extraction — handles no-separator cases (e.g. "20時10分ラインチェック")
        // Only when neither leading nor trailing parse found a time
        // Order: 時分 > HH:MM > 時半 > 時 (specific to general; HH:MM before 時 to avoid 13:30 → 13時 mismatch)
        if ($dueTime === null && $saveTitle !== '') {
            if (preg_match('/(\d{1,2}時\d{1,2}分)(?:に|から|の|まで|で|[ 　]+)?/u', $saveTitle, $tm)) {
                $dueTime     = $tm[1];
                $saveTitle   = trim(preg_replace('/' . preg_quote($tm[0], '/') . '/u', '', $saveTitle, 1));
                $timePattern = 'inline_ji_fun';
            } elseif (preg_match('/(\d{1,2}:\d{2})(?:に|から|の|まで|で|[ 　]+)?/u', $saveTitle, $tm)) {
                $dueTime     = $tm[1];
                $saveTitle   = trim(preg_replace('/' . preg_quote($tm[0], '/') . '/u', '', $saveTitle, 1));
                $timePattern = 'inline_hhmm';
            } elseif (preg_match('/(\d{1,2}時半)(?:に|から|の|まで|で|[ 　]+)?/u', $saveTitle, $tm)) {
                $dueTime     = $tm[1];
                $saveTitle   = trim(preg_replace('/' . preg_quote($tm[0], '/') . '/u', '', $saveTitle, 1));
                $timePattern = 'inline_ji_han';
            } elseif (preg_match('/(\d{1,2}時)(?:に|から|の|まで|で|[ 　]+)?/u', $saveTitle, $tm)) {
                $dueTime     = $tm[1];
                $saveTitle   = trim(preg_replace('/' . preg_quote($tm[0], '/') . '/u', '', $saveTitle, 1));
                $timePattern = 'inline_ji';
            }
        }

        // Strip any remaining leading の from title
        $saveTitle = trim(preg_replace('/^の/u', '', $saveTitle));

        // Extract #tag from end of saveTitle
        $tag = null;
        if (preg_match('/^(.*?)[ 　]*(#[\w\x{3000}-\x{9fff}\x{3040}-\x{309f}\x{30a0}-\x{30ff}\x{4e00}-\x{9fff}]+)[ 　]*$/u', $saveTitle, $tagMatch)) {
            $saveTitle = trim($tagMatch[1]);
            $tag       = ltrim($tagMatch[2], '#');
        }

        // If the entire remaining title is just a time-of-day label (e.g. "今日夜", "明日夕方"),
        // treat it as empty so the empty-title guard below rejects it.
        // Food words (夕飯, ランチ, etc.) are intentionally excluded from this list.
        if ($dueDate !== null && preg_match('/^(?:朝|昼|夕方|夜)は?$/u', $saveTitle)) {
            $saveTitle = '';
        }

        // Supplement due_date with today when only time was parsed (no explicit date).
        // e.g. "9時に日本リアライズ" / "日本リアライズ 9時" / "19:30 会食" / "14時 歯医者"
        // Do not补完 if text contains explicit future-date references that the date parser
        // does not cover (明後日 / 来週); 明日/今日/M月D日/M/D already set $dueDate above.
        if ($dueDate === null && $dueTime !== null && $saveTitle !== ''
            && !preg_match('/明後日|来週/u', $text)
        ) {
            $dueDate     = (new DateTime('now', $tz))->format('Y-m-d');
            $datePattern = 'today_from_time';
        }

        // Pre-save diagnostic log
        webhook_log('task parse result', [
            'raw_text'     => $text,
            'date_pattern' => $datePattern,
            'time_pattern' => $timePattern,
            'due_date'     => $dueDate,
            'due_time'     => $dueTime,
            'title'        => $saveTitle,
            'tag'          => $tag,
        ]);

        if ($saveTitle === '') {
            webhook_log('task parse rejected', [
                'raw_text'     => $text,
                'date_pattern' => $datePattern,
                'time_pattern' => $timePattern,
                'due_date'     => $dueDate,
                'due_time'     => $dueTime,
                'title'        => '',
                'tag'          => $tag,
                'result'       => 'rejected',
                'reason'       => 'empty_title',
            ]);
            if ($replyToken !== '') {
                line_reply($replyToken, '内容を入力してください');
            }
            continue;
        }

        // Conversational text filter — reject messages that are not task-like
        if (!isLikelyTask($saveTitle)) {
            // Step 1: known conversation / unsupported patterns — reply immediately, no AI needed
            $knownReply = isConversation($saveTitle) ?? isUnsupportedCommand($saveTitle);
            if ($knownReply !== null) {
                webhook_log('task skipped: known non-task', ['save_title' => $saveTitle]);
                if ($replyToken !== '') {
                    line_reply($replyToken, $knownReply);
                }
                continue;
            }

            // Step 2: ambiguous input — AI fallback with original text for full context
            $aiResult = OpenAIClient::analyze($text);
            webhook_log('ai_input',  ['text' => $text, 'ai_used' => ($aiResult !== null)]);
            webhook_log('ai_output', ['result' => $aiResult]);

            if (
                $aiResult !== null &&
                ($aiResult['action'] ?? '') === 'create_task' &&
                (float) ($aiResult['confidence'] ?? 0.0) >= 0.85 &&
                !empty($aiResult['normalized_title'])
            ) {
                // Use AI-normalized title
                $saveTitle = (string) $aiResult['normalized_title'];

                // Apply date hint only when rule-based parser found nothing
                if ($dueDate === null) {
                    $aiTz = new DateTimeZone('Asia/Tokyo');
                    $hint = (string) ($aiResult['due_date_hint'] ?? '');
                    if ($hint === 'today') {
                        $dueDate = (new DateTime('now', $aiTz))->format('Y-m-d');
                    } elseif ($hint === 'tomorrow') {
                        $dueDate = (new DateTime('tomorrow', $aiTz))->format('Y-m-d');
                    }
                }

                // Apply time hint only when rule-based parser found nothing
                if ($dueTime === null && !empty($aiResult['due_time_hint'])) {
                    $dueTime = (string) $aiResult['due_time_hint'];
                }

                webhook_log('ai task accepted', [
                    'title'    => $saveTitle,
                    'due_date' => $dueDate,
                    'due_time' => $dueTime,
                ]);
                // Fall through to task save block below
            } else {
                webhook_log('task skipped: not likely task', ['text' => $text, 'save_title' => $saveTitle, 'ai_used' => ($aiResult !== null)]);
                if ($replyToken !== '') {
                    line_reply($replyToken, 'タスクとして登録する内容を送ってください');
                }
                continue;
            }
        }

        // Free tier check: count current-month tasks and reject if over limit
        if ($ownerId !== null && $userRepo !== null && $taskRepo !== null) {
            try {
                $billingUser = $userRepo->findById($ownerId);
                if ($billingUser !== null && !is_paid_user($billingUser)) {
                    $now            = new DateTime('now', $tz);
                    $monthStart     = $now->format('Y-m-01 00:00:00');
                    $nextMonthStart = (clone $now)->modify('first day of next month')->format('Y-m-01 00:00:00');
                    $limit          = (int) ($config['stripe']['free_task_limit'] ?? 30);
                    $monthlyCount   = $taskRepo->countMonthlyCreatedByOwner($ownerId, $monthStart, $nextMonthStart);
                    if ($monthlyCount >= $limit) {
                        webhook_log('free limit reached', ['owner_id' => $ownerId, 'count' => $monthlyCount]);
                        if ($replyToken !== '') {
                            if ($tokenRepo === null) {
                                line_reply($replyToken, '現在、課金ページの生成に失敗しました。時間をおいて再度お試しください。');
                            } else {
                                $upgradeToken = $tokenRepo->createToken($ownerId, 'upgrade', new DateTimeImmutable('+10 minutes'));
                                $checkoutUrl  = rtrim((string) ($config['app']['base_url'] ?? ''), '/')
                                    . '/upgrade.php?token=' . urlencode($upgradeToken);
                                line_reply($replyToken, implode("\n", [
                                    '無料枠（月30件）を使い切りました。',
                                    '',
                                    'このまま使い続けるには',
                                    '有料プラン（月額980円）が必要です。',
                                    '',
                                    '▼有料プランでできること',
                                    '・タスク無制限',
                                    '・自動リマインド',
                                    '・抜け漏れ防止',
                                    '',
                                    '1日あたり約32円で使えます。',
                                    '',
                                    '👇30秒で登録できます',
                                    $checkoutUrl,
                                ]));
                            }
                        }
                        continue;
                    }
                }
            } catch (\Throwable $e) {
                // fail-closed: billing check errors must NOT allow task creation
                webhook_log('billing check failed', ['error' => $e->getMessage(), 'owner_id' => $ownerId]);
                if ($replyToken !== '') {
                    line_reply($replyToken, implode("\n", [
                        '現在、課金状態の確認に失敗しました。',
                        '時間をおいて再度お試しください。',
                    ]));
                }
                continue;
            }
        }

        // Time conflict check: only when both due_date and due_time are set
        if ($dueDate !== null && $dueTime !== null && $convStateRepo !== null) {
            try {
                $timedTasks = $taskRepo->getTimedOpenTasksByOwnerAndDate($ownerId, $dueDate);
                $newMinutes = parse_time_to_minutes($dueTime);
                $conflicts  = [];
                if ($newMinutes !== null) {
                    foreach ($timedTasks as $ct) {
                        $existingMin = parse_time_to_minutes((string) $ct['due_time']);
                        if ($existingMin !== null && abs($newMinutes - $existingMin) <= 30) {
                            $conflicts[] = $ct;
                        }
                    }
                }
                if (!empty($conflicts)) {
                    $state                          = $convStateRepo->getState($ownerId);
                    $state['mode']                  = 'confirm_conflict';
                    $state['pending_title']         = $saveTitle;
                    $state['pending_due_date']      = $dueDate;
                    $state['pending_due_time']      = $dueTime;
                    $state['pending_tag']           = $tag;
                    $convStateRepo->saveState($ownerId, $state);
                    webhook_log('conflict check triggered', ['owner_id' => $ownerId, 'new_time' => $dueTime, 'conflict_count' => count($conflicts)]);
                    $lines = ['近い時間の予定があります。', ''];
                    foreach ($conflicts as $ct) {
                        $lines[] = '・' . $ct['due_time'] . ' ' . $ct['title'];
                    }
                    $lines[] = '';
                    $lines[] = 'このまま登録しますか？';
                    $qr = ['items' => [qr_item('登録する', '登録する'), qr_item('やめる', 'やめる')]];
                    if ($replyToken !== '') {
                        line_reply($replyToken, implode("\n", $lines), $qr);
                    }
                    continue;
                }
            } catch (\Throwable $e) {
                webhook_log('conflict check failed', ['error' => $e->getMessage()]);
                // On error proceed with saving normally
            }
        }

        try {
            $taskId      = $taskRepo->create($ownerId, $saveTitle, $dueDate, $dueTime, $tag);
            $parseResult = ($dueDate === null && $dueTime === null) ? 'fallback_saved' : 'saved';
            webhook_log('task created', ['owner_id' => $ownerId, 'title' => $saveTitle, 'due_date' => $dueDate, 'due_time' => $dueTime, 'tag' => $tag, 'task_id' => $taskId]);
            webhook_log($parseResult === 'fallback_saved' ? 'task parse fallback' : 'task parse result', [
                'raw_text'     => $text,
                'date_pattern' => $datePattern,
                'time_pattern' => $timePattern,
                'due_date'     => $dueDate,
                'due_time'     => $dueTime,
                'title'        => $saveTitle,
                'tag'          => $tag,
                'result'       => $parseResult,
            ]);
            if ($replyToken !== '') {
                $msg = "登録しました:\n・" . $saveTitle;
                if ($dueDate !== null) {
                    $todayStr    = (new DateTime('now', $tz))->format('Y-m-d');
                    $tomorrowStr = (new DateTime('tomorrow', $tz))->format('Y-m-d');
                    if ($dueDate === $todayStr) {
                        $dateLbl = '今日';
                    } elseif ($dueDate === $tomorrowStr) {
                        $dateLbl = '明日';
                    } else {
                        $dateLbl = $dueDate;
                    }
                    if ($dueTime !== null) {
                        $msg .= "\n期限：" . $dateLbl . ' ' . $dueTime;
                    } else {
                        $msg .= "\n期限：" . $dateLbl;
                    }
                } elseif ($dueTime !== null) {
                    $msg .= "\n時刻：" . $dueTime;
                }
                if ($tag !== null) {
                    $msg .= "\nタグ：#" . $tag;
                }
                // Tutorial hint on task creation (STEP 1 → 2, STEP 2 → 3)
                if (in_array($tutorialStep, [0, 1, 2], true) && $ownerId !== null && $userRepo !== null) {
                    try {
                        $userRepo->advanceTutorialStep($ownerId);
                        $msg .= ($tutorialStep <= 1)
                            ? "\n\n---\n「今日」と送ると一覧が確認できます。"
                            : "\n\n---\n「今のは明日」などで日時を修正できます。";
                    } catch (\Throwable $e) {
                        webhook_log('tutorial advance failed', ['error' => $e->getMessage()]);
                    }
                }
                line_reply($replyToken, $msg);
            }

            // 25件事前警告：保存成功後・無料ユーザー・1回限り
            if (isset($billingUser, $monthlyCount)
                && $billingUser !== null
                && !is_paid_user($billingUser)
                && ($monthlyCount + 1) >= 25
                && !(bool) ($billingUser['warned_limit'] ?? false)
            ) {
                $userRepo->updateWarnedLimit($ownerId);
                webhook_log('free limit warning sent', ['owner_id' => $ownerId, 'count' => $monthlyCount + 1]);
                if ($tokenRepo !== null) {
                    $warnToken = $tokenRepo->createToken($ownerId, 'upgrade', new DateTimeImmutable('+10 minutes'));
                    $warnUrl   = rtrim((string) ($config['app']['base_url'] ?? ''), '/')
                        . '/upgrade.php?token=' . urlencode($warnToken);
                    line_push($lineUserId, implode("\n", [
                        'あと5件で無料枠が終了します。',
                        '',
                        '継続して使う場合は',
                        '有料プラン（月額980円）がおすすめです。',
                        '',
                        '▼有料プラン',
                        '・タスク無制限',
                        '・自動管理',
                        '',
                        '👇今のうちに登録しておくと安心です',
                        $warnUrl,
                    ]));
                } else {
                    line_push($lineUserId, '現在、課金ページの生成に失敗しました。時間をおいて再度お試しください。');
                }
            }
        } catch (\Throwable $e) {
            webhook_log('task create failed', ['error' => $e->getMessage()]);
        }
        continue;
    }

    if ($replyToken === '') {
        continue;
    }

    if ($text === '/brief' || $text === 'ブリーフ') {
        $replyText = "【今日のブリーフ】\n\n今日やるべきタスクはありません";
        if ($ownerId !== null && $taskRepo !== null) {
            try {
                $today      = (new DateTime('now', new DateTimeZone('Asia/Tokyo')))->format('Y-m-d');
                $todayTasks = $taskRepo->getTodayTasksByOwner($ownerId, $today);
                $noneTasks  = $taskRepo->getNoDueDateTasksByOwner($ownerId);

                if (!empty($todayTasks) || !empty($noneTasks)) {
                    $sections = ["【今日のブリーフ】"];
                    $counter  = 1;

                    if (!empty($todayTasks)) {
                        $sections[] = "\n■ 今日の期限";
                        foreach ($todayTasks as $t) {
                            $prefix = (!empty($t['due_time'])) ? $t['due_time'] . ' ' : '';
                            $sections[] = $counter++ . '. ' . $prefix . $t['title'];
                        }
                    }

                    if (!empty($noneTasks)) {
                        $sections[] = "\n■ その他（未期限）";
                        foreach ($noneTasks as $t) {
                            $sections[] = $counter++ . '. ' . $t['title'];
                        }
                    }

                    $replyText = implode("\n", $sections);
                }
            } catch (\Throwable $e) {
                webhook_log('brief failed', ['error' => $e->getMessage()]);
                $replyText = 'ブリーフ取得に失敗しました';
            }
        }
        if ($replyToken !== '') {
            line_reply($replyToken, $replyText);
        }
        continue;
    }

    if ($text === 'help' || $text === 'ヘルプ' || $text === '/help') {
        $helpText = implode("\n", [
            '【使い方】',
            '',
            '① タスクを送るだけで登録',
            '・今日 資料送る',
            '・明日 税理士に連絡',
            '・今日 13:30 歯医者',
            '',
            '② 今日のタスクを確認',
            '・今日',
            '',
            '👉 そのまま「完了1」で消せます',
            '',
            '③ 一覧を見る',
            '・一覧',
            '',
            '👉 「次」「前」で移動できます',
            '',
            '④ タスクを完了',
            '・完了1',
            '',
            '⑤ 特定の日を確認',
            '・3月20日',
            '・3/20',
            '',
            '⑥ タグで絞り込む',
            '・今日 資料送る #仕事　← 登録時にタグ付け',
            '・#仕事　← タグ一覧を表示',
            '',
            '---',
            '',
            '■ ポイント',
            '・自然な文章でOKです',
            '・時間や日付も自動で認識します',
            '・通知でやることをお知らせします',
            '・リッチメニューからも操作できます',
        ]);
        line_reply($replyToken, $helpText);
        continue;
    }

    if ($text === '解約' || $text === 'プラン' || $text === '課金') {
        if ($tokenRepo === null || $ownerId === null) {
            line_reply($replyToken, '現在、課金ページの生成に失敗しました。時間をおいて再度お試しください。');
        } else {
            $portalToken = $tokenRepo->createToken($ownerId, 'portal', new DateTimeImmutable('+10 minutes'));
            $portalUrl   = rtrim((string) ($config['app']['base_url'] ?? ''), '/')
                . '/stripe/portal.php?token=' . urlencode($portalToken);
            line_reply($replyToken, implode("\n", [
                '有料プランの確認・解約はこちらから行えます👇',
                $portalUrl,
            ]));
        }
        continue;
    }

    // default fallback response
    line_reply($replyToken, implode("\n", [
        '理解できませんでした。',
        '',
        '例:',
        '・今日 資料送る',
        '・一覧',
        '・完了 1',
        '',
        '困ったら「ヘルプ」と送ってください。',
    ]));
}

http_response_code(200);
echo 'OK';