<?php

declare(strict_types=1);

namespace App\Infrastructure;

use App\Review\ComparisonGateway;
use App\Review\Evidence;
use App\Review\PublicUrl;
use App\Review\ReviewFailure;
use App\Review\ReviewInput;
use Mcp\Client\Client;
use Mcp\Client\Transport\HttpTransport;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Result\CallToolResult;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpClient\Psr18Client;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class McpComparisonGateway implements ComparisonGateway
{
    public function __construct(private string $apiKey, private string $endpoint, private PublicUrl $urls, private ?HttpClientInterface $http = null)
    {
    }

    public function compare(ReviewInput $input): Evidence
    {
        $this->urls->validate($this->endpoint);
        if (!str_starts_with($this->endpoint, 'https://')) {
            throw new \RuntimeException('La connexion MCP doit utiliser HTTPS.');
        }
        $client = Client::builder()->setClientInfo('snapshot-site-release-review', '1.0.0')->setInitTimeout(15)->setRequestTimeout(120)->setMaxRetries(0)->build();
        $transport = new HttpTransport($this->endpoint, ['x-snapshotsiteapi-key' => $this->apiKey], new Psr18Client($this->http ?? HttpClient::create(['timeout' => 120, 'max_duration' => 125, 'max_redirects' => 0])));
        try {
            $client->connect($transport);

            return self::decode($client->callTool('compare', $input->comparisonArguments()), $this->urls);
        } finally {
            $client->disconnect();
        }
    }

    public static function decode(CallToolResult $result, PublicUrl $urls): Evidence
    {
        if ($result->isError) {
            foreach ($result->content as $content) {
                if ($content instanceof TextContent) {
                    throw ReviewFailure::fromToolMessage($content->text);
                }
            }
            throw new ReviewFailure('capture', 'provider');
        }
        $data = $result->structuredContent;
        if (!is_array($data)) {
            foreach ($result->content as $content) {
                if ($content instanceof TextContent) {
                    $data = json_decode($content->text, true, 32, JSON_THROW_ON_ERROR);
                    break;
                }
            }
        }
        if (!is_array($data) || !is_string($data['before']['link'] ?? null) || !is_string($data['after']['link'] ?? null)) {
            throw new \UnexpectedValueException('La comparaison ne contient pas les deux captures attendues.');
        }
        $diff = $data['diff']['link'] ?? null;
        $mismatch = $data['summary']['mismatchPercentage'] ?? null;

        return new Evidence(
            $urls->validate($data['before']['link']),
            $urls->validate($data['after']['link']),
            is_string($diff) ? $urls->validate($diff) : null,
            is_numeric($mismatch) && $mismatch >= 0 && $mismatch <= 100 ? (float) $mismatch : null,
        );
    }
}
