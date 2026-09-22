<?php
require_once __DIR__ . '/../core/nanogpt_client.php';

function assertNanoGptSame($expected, $actual, string $message): void {
    if ($expected !== $actual) {
        throw new RuntimeException($message . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

function expectNanoGptException(callable $callback, string $expectedMessage): SpenceNanoGptException {
    try {
        $callback();
    } catch (SpenceNanoGptException $exception) {
        assertNanoGptSame($expectedMessage, $exception->getMessage(), 'exception message');
        return $exception;
    }
    throw new RuntimeException('expected SpenceNanoGptException was not thrown');
}

$plain = '{"choices":[{"message":{"content":"{\\"ok\\":true}"},"finish_reason":"stop"}]}';
assertNanoGptSame('{"ok":true}', spenceNanoGptParseResponse($plain, 200), 'plain content');

$blocks = '{"choices":[{"message":{"content":[{"type":"text","text":"part one"},{"type":"text","text":" + two"}]}}]}';
assertNanoGptSame('part one + two', spenceNanoGptParseResponse($blocks, 200), 'typed content blocks');

$httpError = expectNanoGptException(
    fn() => spenceNanoGptParseResponse('{"error":{"message":"insufficient balance","type":"billing"}}', 402, ['model' => 'test-model']),
    'NanoGPT request failed (HTTP 402): insufficient balance'
);
assertNanoGptSame('test-model', $httpError->getDebugDetails()['model'], 'debug model');
assertNanoGptSame(402, $httpError->getDebugDetails()['http_status'], 'debug HTTP status');

$empty = expectNanoGptException(
    fn() => spenceNanoGptParseResponse('{"choices":[{"message":{"content":null},"finish_reason":"length"}]}', 200),
    'NanoGPT returned no message content (reason: length).'
);
assertNanoGptSame('length', $empty->getDebugDetails()['finish_reason'], 'debug finish reason');

expectNanoGptException(
    fn() => spenceNanoGptParseResponse('<html>bad gateway</html>', 502),
    'NanoGPT request failed (HTTP 502)'
);

assertNanoGptSame(['ok' => true], spenceNanoGptDecodeJson('{"ok":true}', 'test-model'), 'JSON content');
$invalidContent = expectNanoGptException(
    fn() => spenceNanoGptDecodeJson('not JSON', 'test-model'),
    'Could not parse NanoGPT content as JSON: Syntax error'
);
assertNanoGptSame('not JSON', $invalidContent->getDebugDetails()['content_excerpt'], 'invalid content excerpt');

putenv('SPENCE_AI_DEBUG=1');
assertNanoGptSame(['model' => 'test'], spenceNanoGptDebugDetails(new SpenceNanoGptException('test', ['model' => 'test'])), 'debug enabled');
putenv('SPENCE_AI_DEBUG=0');
assertNanoGptSame(null, spenceNanoGptDebugDetails(new SpenceNanoGptException('test')), 'debug disabled');
putenv('SPENCE_AI_DEBUG');

echo "NanoGPT client tests passed\n";
