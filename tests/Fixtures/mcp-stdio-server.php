<?php

declare(strict_types=1);

while (($line = fgets(STDIN)) !== false) {
    $request = json_decode($line, true, 32, JSON_THROW_ON_ERROR);
    if (!is_array($request) || !isset($request['id'])) {
        continue;
    }
    $result = match ($request['method'] ?? '') {
        'initialize' => [
            'protocolVersion' => '2025-11-25',
            'serverInfo' => ['name' => 'fixture', 'version' => '1.0.0'],
            'capabilities' => ['tools' => new stdClass()],
        ],
        'tools/call' => [
            'content' => [],
            'structuredContent' => [
                'before' => ['link' => 'https://cdn.example.com/before.png'],
                'after' => ['link' => 'https://cdn.example.com/after.png'],
                'summary' => ['mismatchPercentage' => 0.0],
            ],
        ],
        default => new stdClass(),
    };
    if (($request['method'] ?? '') === 'tools/call') {
        $valid = getenv('SNAPSHOT_SITE_API_KEY') === 'unit-only-key'
            && getenv('OPENAI_API_KEY') === false
            && count($argv) === 1
            && ($request['params']['name'] ?? '') === 'compare'
            && ($request['params']['arguments']['before']['width'] ?? 0) === 390;
        if (!$valid) {
            $result = ['isError' => true, 'content' => [['type' => 'text', 'text' => 'Invalid API credentials']]];
        }
    }
    echo json_encode(['jsonrpc' => '2.0', 'id' => $request['id'], 'result' => $result], JSON_THROW_ON_ERROR)."\n";
    fflush(STDOUT);
}
