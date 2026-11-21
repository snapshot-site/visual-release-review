<?php

declare(strict_types=1);

namespace App\Infrastructure;

use App\Review\ComparisonGateway;
use App\Review\Evidence;
use App\Review\PublicUrl;
use App\Review\ReviewFailure;
use App\Review\ReviewInput;
use Mcp\Client\Client;
use Mcp\Client\Transport\StdioTransport;
use Mcp\Schema\Enum\ProtocolVersion;

final readonly class StdioComparisonGateway implements ComparisonGateway
{
    public function __construct(
        #[\SensitiveParameter] private string $apiKey,
        private PublicUrl $urls,
        private string $command = 'node',
        private ?string $serverPath = null,
    ) {
    }

    public function compare(ReviewInput $input): Evidence
    {
        $root = dirname(__DIR__, 2);
        $server = $this->serverPath ?? $root.'/node_modules/@snapshot-site/mcp/build/server.js';
        if (!is_file($server)) {
            throw new ReviewFailure('capture', 'setup');
        }
        $transport = new StdioTransport($this->command, [$server], $root, [
            'PATH' => getenv('PATH') ?: '/usr/bin:/usr/local/bin:/opt/homebrew/bin',
            'SNAPSHOT_SITE_API_KEY' => $this->apiKey,
        ]);
        $client = Client::builder()
            ->setClientInfo('snapshot-site-release-review', '1.0.0')
            ->setProtocolVersion(ProtocolVersion::V2025_11_25)
            ->setInitTimeout(15)
            ->setRequestTimeout(120)
            ->setMaxRetries(0)
            ->build();
        try {
            $client->connect($transport);

            return McpComparisonGateway::decode($client->callTool('compare', $input->comparisonArguments()), $this->urls);
        } finally {
            $client->disconnect();
        }
    }
}
