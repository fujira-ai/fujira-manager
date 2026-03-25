<?php
declare(strict_types=1);

/**
 * Thin wrapper around OpenAI Chat Completions API.
 * Used as a fallback classifier when rule-based isLikelyTask() returns false.
 *
 * Required env var: OPENAI_API_KEY
 */
class OpenAIClient
{
    private const API_URL = 'https://api.openai.com/v1/chat/completions';
    private const MODEL   = 'gpt-4.1-mini';
    private const TIMEOUT = 5; // seconds

    private const SYSTEM_PROMPT = <<<PROMPT
あなたはLINEタスク管理アシスタントの入力判定器です。
ユーザーの日本語入力を解析し、必ずJSONだけを返してください。

目的:
- タスク登録
- 修正指示
- 会話
- 未対応
を判定すること

出力ルール:
- JSONのみ
- explanationは短く
- normalized_titleは簡潔に
- due_date_hint: today / tomorrow / none / unknown
- due_time_hint: HH:MM形式またはnull
- confidence: 0.0〜1.0

出力フォーマット例:
{"action":"create_task","is_task":true,"normalized_title":"高橋とご飯","due_date_hint":"today","due_time_hint":"20:00","confidence":0.92,"explanation":"食事の予定"}
PROMPT;

    /**
     * Analyze input text and return a structured classification result.
     * Returns null on any failure (API error, JSON parse error, missing key).
     *
     * @return array{action:string,is_task:bool,normalized_title:string,due_date_hint:string,due_time_hint:string|null,confidence:float,explanation?:string}|null
     */
    public static function analyze(string $text): ?array
    {
        $secret = require __DIR__ . '/../app/config.secret.php';
        $apiKey = (string) ($secret['OPENAI_API_KEY'] ?? '');
        if ($apiKey === '') {
            return null;
        }

        $payload = json_encode([
            'model'           => self::MODEL,
            'messages'        => [
                ['role' => 'system', 'content' => self::SYSTEM_PROMPT],
                ['role' => 'user',   'content' => $text],
            ],
            'temperature'     => 0.0,
            'max_tokens'      => 200,
            'response_format' => [
                'type'        => 'json_schema',
                'json_schema' => [
                    'name'   => 'task_classification',
                    'strict' => false,
                    'schema' => [
                        'type'       => 'object',
                        'properties' => [
                            'action'           => ['type' => 'string'],
                            'is_task'          => ['type' => 'boolean'],
                            'normalized_title' => ['type' => ['string', 'null']],
                            'due_date_hint'    => ['type' => ['string', 'null']],
                            'due_time_hint'    => ['type' => ['string', 'null']],
                            'confidence'       => ['type' => 'number'],
                        ],
                        'required' => ['action', 'is_task', 'normalized_title', 'due_date_hint', 'due_time_hint', 'confidence'],
                    ],
                ],
            ],
        ], JSON_UNESCAPED_UNICODE);

        if ($payload === false) {
            return null;
        }

        $ch = curl_init(self::API_URL);
        if ($ch === false) {
            return null;
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $apiKey,
            ],
            CURLOPT_TIMEOUT        => self::TIMEOUT,
        ]);

        $response  = curl_exec($ch);
        $curlErrno = curl_errno($ch);
        curl_close($ch);

        if ($curlErrno !== 0 || !is_string($response)) {
            return null;
        }

        $decoded = json_decode($response, true);
        if (!is_array($decoded)) {
            return null;
        }

        $content = $decoded['choices'][0]['message']['content'] ?? null;
        if (!is_string($content)) {
            return null;
        }

        $result = json_decode($content, true);
        if (!is_array($result)) {
            return null;
        }

        // Validate required keys
        if (!isset($result['action'], $result['confidence'], $result['normalized_title'])) {
            return null;
        }

        return $result;
    }
}
