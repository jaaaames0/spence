<?php
/**
 * NanoGPT chat-completion client shared by the image-scanning endpoints.
 */

class SpenceNanoGptException extends RuntimeException {
    private array $debugDetails;

    public function __construct(string $message, array $debugDetails = []) {
        parent::__construct($message);
        $this->debugDetails = $debugDetails;
    }

    public function getDebugDetails(): array {
        return $this->debugDetails;
    }
}

function spenceEnvironmentFlag(string $name): bool {
    $value = getenv($name);
    if ($value === false) return false;
    return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true);
}

function spenceNanoGptResponseExcerpt($decoded, string $rawResponse): string {
    $source = is_array($decoded)
        ? json_encode($decoded, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        : $rawResponse;
    if (!is_string($source)) return '';

    // Keep debug responses useful without returning an unbounded provider body.
    return substr($source, 0, 2000);
}

function spenceNanoGptErrorMessage($decoded): ?string {
    if (!is_array($decoded)) return null;

    $error = $decoded['error'] ?? null;
    if (is_string($error) && trim($error) !== '') return trim($error);
    if (is_array($error)) {
        foreach (['message', 'detail', 'type', 'code'] as $field) {
            if (isset($error[$field]) && is_scalar($error[$field]) && trim((string)$error[$field]) !== '') {
                return trim((string)$error[$field]);
            }
        }
    }

    foreach (['message', 'detail'] as $field) {
        if (isset($decoded[$field]) && is_scalar($decoded[$field]) && trim((string)$decoded[$field]) !== '') {
            return trim((string)$decoded[$field]);
        }
    }
    return null;
}

function spenceNanoGptParseResponse(string $response, int $httpStatus, array $baseDebug = []): string {
    $decoded = json_decode($response, true);
    $jsonError = json_last_error_msg();
    $debug = $baseDebug + [
        'http_status' => $httpStatus,
        'response_json_error' => $jsonError,
        'response_excerpt' => spenceNanoGptResponseExcerpt($decoded, $response),
    ];
    $providerError = spenceNanoGptErrorMessage($decoded);

    if ($httpStatus < 200 || $httpStatus >= 300) {
        $message = "NanoGPT request failed (HTTP {$httpStatus})";
        if ($providerError !== null) $message .= ': ' . $providerError;
        throw new SpenceNanoGptException($message, $debug);
    }

    if (!is_array($decoded)) {
        throw new SpenceNanoGptException(
            'NanoGPT returned invalid JSON (HTTP 200): ' . $jsonError,
            $debug
        );
    }

    // Some compatible gateways return an error object with HTTP 200.
    if ($providerError !== null && !isset($decoded['choices'])) {
        throw new SpenceNanoGptException('NanoGPT error: ' . $providerError, $debug);
    }

    $choice = $decoded['choices'][0] ?? [];
    $message = is_array($choice) ? ($choice['message'] ?? []) : [];
    $content = is_array($message) ? ($message['content'] ?? null) : null;

    // Accept either the usual string or OpenAI-style typed content blocks.
    if (is_array($content)) {
        $parts = [];
        foreach ($content as $part) {
            if (is_array($part) && isset($part['text']) && is_string($part['text'])) {
                $parts[] = $part['text'];
            }
        }
        $content = implode('', $parts);
    }

    if (!is_string($content) || trim($content) === '') {
        $finishReason = is_array($choice) ? ($choice['finish_reason'] ?? null) : null;
        $refusal = is_array($message) ? ($message['refusal'] ?? null) : null;
        $debug['finish_reason'] = $finishReason;
        $debug['refusal'] = $refusal;

        $reason = $refusal ?: $finishReason;
        $suffix = is_scalar($reason) && trim((string)$reason) !== ''
            ? ' (reason: ' . trim((string)$reason) . ')'
            : '';
        throw new SpenceNanoGptException(
            'NanoGPT returned no message content' . $suffix . '.',
            $debug
        );
    }

    return $content;
}

/**
 * Execute an OpenAI-compatible chat completion and return message content.
 * Throws an actionable exception for transport, HTTP, JSON, and empty output.
 */
function spenceNanoGptChatCompletion(string $apiKey, array $request): string {
    $endpoint = 'https://nano-gpt.com/api/v1/chat/completions';
    $model = (string)($request['model'] ?? 'unknown');
    $payload = json_encode($request);
    if ($payload === false) {
        throw new SpenceNanoGptException(
            'Could not encode the NanoGPT request: ' . json_last_error_msg(),
            ['model' => $model, 'json_error' => json_last_error_msg()]
        );
    }

    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey,
        ],
    ]);

    $response = curl_exec($ch);
    $curlErrorNumber = curl_errno($ch);
    $curlError = curl_error($ch);
    $httpStatus = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $contentType = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    $totalTimeMs = (int)round(((float)curl_getinfo($ch, CURLINFO_TOTAL_TIME)) * 1000);
    curl_close($ch);

    $baseDebug = [
        'endpoint' => $endpoint,
        'model' => $model,
        'request_bytes' => strlen($payload),
        'http_status' => $httpStatus,
        'content_type' => $contentType,
        'total_time_ms' => $totalTimeMs,
    ];

    if ($response === false || $curlErrorNumber !== 0) {
        throw new SpenceNanoGptException(
            'NanoGPT network error: ' . ($curlError ?: 'unknown cURL failure'),
            $baseDebug + ['curl_errno' => $curlErrorNumber, 'curl_error' => $curlError]
        );
    }

    return spenceNanoGptParseResponse($response, $httpStatus, $baseDebug);
}

function spenceNanoGptDebugDetails(Exception $exception): ?array {
    if (!spenceEnvironmentFlag('SPENCE_AI_DEBUG')) return null;
    if (!($exception instanceof SpenceNanoGptException)) return null;
    return $exception->getDebugDetails();
}

function spenceNanoGptDecodeJson(string $content, string $model) {
    $decoded = json_decode($content, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new SpenceNanoGptException(
            'Could not parse NanoGPT content as JSON: ' . json_last_error_msg(),
            [
                'model' => $model,
                'content_json_error' => json_last_error_msg(),
                'content_excerpt' => substr($content, 0, 2000),
            ]
        );
    }
    return $decoded;
}
