<?php

declare(strict_types=1);

namespace App\Infrastructure;

use App\Review\PublicUrl;
use App\Review\ReviewRunner;
use App\Review\RunnerFactory;

final class LiveRunnerFactory implements RunnerFactory
{
    public function create(): ReviewRunner
    {
        $captures = match ($_ENV['SNAPSHOT_SITE_MCP_TRANSPORT'] ?? 'stdio') {
            'stdio' => new StdioComparisonGateway($_ENV['SNAPSHOT_SITE_API_KEY'], new PublicUrl()),
            'http' => new McpComparisonGateway($_ENV['SNAPSHOT_SITE_API_KEY'], $_ENV['SNAPSHOT_SITE_MCP_URL'], new PublicUrl()),
            default => throw new \InvalidArgumentException('Le transport MCP doit être stdio ou http.'),
        };

        return new ReviewRunner(
            $captures,
            SymfonyVisualReviewer::create($_ENV['OPENAI_API_KEY'], $_ENV['OPENAI_MODEL']),
        );
    }
}
