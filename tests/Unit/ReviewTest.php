<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Review\ComparisonGateway;
use App\Review\DemoReview;
use App\Review\Evidence;
use App\Review\Finding;
use App\Review\ReviewInput;
use App\Review\ReviewReport;
use App\Review\ReviewRunner;
use App\Review\Verdict;
use App\Review\VisualReviewer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Translation\IdentityTranslator;

final class ReviewTest extends TestCase
{
    #[DataProvider('verdictPairs')]
    public function testDisagreementNeverBecomesAConfidentVerdict(Verdict $first, Verdict $second, Verdict $expected): void
    {
        $report = new ReviewReport($this->input(), new Evidence('before', 'after'), $this->finding($first), $this->finding($second));
        self::assertSame($expected, $report->verdict);
        self::assertSame($first !== $second, $report->disagreement);
    }

    /** @return iterable<string, array{Verdict, Verdict, Verdict}> */
    public static function verdictPairs(): iterable
    {
        foreach (Verdict::cases() as $first) {
            foreach (Verdict::cases() as $second) {
                yield $first->value.'-'.$second->value => [$first, $second, $first === $second ? $first : Verdict::Uncertain];
            }
        }
    }

    public function testBothReviewersReceiveTheSameOriginalImages(): void
    {
        $input = $this->input();
        $evidence = new Evidence('https://cdn.example.com/before.png', 'https://cdn.example.com/after.png');
        $inspection = $this->finding(Verdict::Confirmed);
        $critique = $this->finding(Verdict::Contradicted);
        $gateway = $this->createMock(ComparisonGateway::class);
        $gateway->expects(self::once())->method('compare')->with($input)->willReturn($evidence);
        $calls = [];
        $reviewer = $this->createMock(VisualReviewer::class);
        $reviewer->expects(self::exactly(2))->method('review')->willReturnCallback(function ($receivedInput, $receivedEvidence, $prior = null) use (&$calls, $input, $evidence, $inspection, $critique) {
            self::assertSame($input, $receivedInput);
            self::assertSame($evidence, $receivedEvidence);
            $calls[] = $prior;

            return $prior === null ? $inspection : $critique;
        });
        $report = (new ReviewRunner($gateway, $reviewer))->run($input);
        self::assertSame([null, $inspection], $calls);
        self::assertSame(Verdict::Uncertain, $report->verdict);
    }

    public function testCaptureFailureDoesNotProduceAFabricatedReport(): void
    {
        $gateway = $this->createMock(ComparisonGateway::class);
        $gateway->method('compare')->willThrowException(new \RuntimeException('capture failed'));
        $reviewer = $this->createMock(VisualReviewer::class);
        $reviewer->expects(self::never())->method('review');
        $this->expectException(\RuntimeException::class);
        (new ReviewRunner($gateway, $reviewer))->run($this->input());
    }

    public function testViewportIsIdenticalAndLimitedToTheVisiblePage(): void
    {
        $arguments = $this->input()->comparisonArguments();
        self::assertSame(390, $arguments['before']['width']);
        self::assertSame(844, $arguments['after']['height']);
        self::assertFalse($arguments['before']['fullSize']);
        unset($arguments['before']['url'], $arguments['after']['url']);
        self::assertSame($arguments['before'], $arguments['after']);
    }

    #[DataProvider('invalidFindings')]
    public function testMalformedModelOutputIsRejected(string $json): void
    {
        $this->expectException(\Throwable::class);
        Finding::fromJson($json);
    }

    /** @return iterable<int, array{string}> */
    public static function invalidFindings(): iterable
    {
        yield ['not json'];
        yield ['null'];
        yield ['{"verdict":"safe-to-deploy"}'];
        yield ['{"verdict":"confirmed","observation":"","evidence":"after","limitation":"No clicks tested"}'];
        yield ['{"verdict":"confirmed","observation":"Visible","evidence":"invented.png","limitation":"No clicks tested"}'];
        yield ['{"verdict":"confirmed","observation":[],"evidence":"after","limitation":"No clicks tested"}'];
    }

    public function testValidModelFindingKeepsItsEvidenceReference(): void
    {
        $finding = Finding::fromJson('{"verdict":"contradicted","observation":"Le bandeau masque le bouton.","evidence":"after","limitation":"Le clic n’est pas testé."}');
        self::assertSame(Verdict::Contradicted, $finding->verdict);
        self::assertSame('after', $finding->evidence);
    }

    public function testDemoIsExplicitlyMarkedAsReplay(): void
    {
        $demo = new DemoReview(new IdentityTranslator());
        self::assertTrue($demo->run('broken')->replay);
        self::assertSame(Verdict::Confirmed, $demo->run('fixed')->verdict);
        self::assertSame(Verdict::Uncertain, $demo->run('uncertain')->verdict);
    }

    private function input(): ReviewInput
    {
        return new ReviewInput('https://example.com/before', 'https://example.com/after', 'Le bouton Acheter est entièrement visible.');
    }

    private function finding(Verdict $verdict): Finding
    {
        return new Finding($verdict, 'Une observation sur les images.', 'both', 'Les clics ne sont pas testés.');
    }
}
