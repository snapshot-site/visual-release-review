<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Review\DemoReview;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Translation\Loader\PhpFileLoader;
use Symfony\Component\Translation\Translator;

final class TranslationTest extends TestCase
{
    public function testEveryFrenchMessageHasAnEnglishTranslation(): void
    {
        $french = require dirname(__DIR__, 2).'/translations/messages.fr.php';
        $english = require dirname(__DIR__, 2).'/translations/messages.en.php';
        self::assertSame(array_keys($french), array_keys($english));
        foreach ($english as $message => $translation) {
            self::assertNotSame('', trim($translation), $message);
            self::assertNotSame($message, $translation, $message);
        }
    }

    /** @return iterable<string, array{string}> */
    public static function scenarios(): iterable
    {
        foreach (['broken', 'fixed', 'uncertain', 'disagreement'] as $scenario) {
            yield $scenario => [$scenario];
        }
    }

    #[DataProvider('scenarios')]
    public function testExampleFindingsAndLimitationsAreTranslated(string $scenario): void
    {
        $translator = new Translator('fr');
        $translator->addLoader('php', new PhpFileLoader());
        $translator->addResource('php', dirname(__DIR__, 2).'/translations/messages.en.php', 'en');
        $demo = new DemoReview($translator);
        $french = $demo->run($scenario);
        $english = $demo->run($scenario, 'en');
        self::assertSame('en', $english->input->locale);
        self::assertSame($french->verdict, $english->verdict);
        self::assertSame($french->evidence->after, $english->evidence->after);
        self::assertNotSame($french->input->claim, $english->input->claim);
        foreach (['inspector', 'critic'] as $role) {
            self::assertNotSame($french->{$role}->observation, $english->{$role}->observation);
            self::assertNotSame($french->{$role}->limitation, $english->{$role}->limitation);
        }
    }
}
