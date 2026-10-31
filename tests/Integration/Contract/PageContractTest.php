<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Tests\Integration\Contract;

use Nowo\InstanceSettingsBundle\Tests\Support\PageFixtures;
use Nowo\UiKitBundle\Test\PageContractTestCase;
use Nowo\UiKitBundle\Test\PageFixture;
use PHPUnit\Framework\Attributes\DataProvider;

use function dirname;

/**
 * REQ-UI-003 page contract: every page extends the UiKit settings scaffold; no <html>, CDN, inline handler or
 * confirm(); framework classes only through theme slots; the stub theme is reflected; no raw or missing translation
 * (strict rule); the CSRF forms keep their hidden fields; a stored secret is never printed.
 */
final class PageContractTest extends PageContractTestCase
{
    public static function pageFixtures(): iterable
    {
        yield 'section mailer (secret stored)' => new PageFixture('@NowoInstanceSettingsBundle/settings/section.html.twig', PageFixtures::section());
        yield 'section mailer (no secret)' => new PageFixture('@NowoInstanceSettingsBundle/settings/section.html.twig', PageFixtures::section('mailer', false));
        yield 'section general' => new PageFixture('@NowoInstanceSettingsBundle/settings/section.html.twig', PageFixtures::section('general'));
        yield 'no section' => new PageFixture('@NowoInstanceSettingsBundle/settings/section.html.twig', PageFixtures::emptyIndex());
        yield 'portability (sealed secrets)' => new PageFixture('@NowoInstanceSettingsBundle/settings/portability.html.twig', PageFixtures::portability());
        yield 'portability (no secrets)' => new PageFixture('@NowoInstanceSettingsBundle/settings/portability.html.twig', PageFixtures::portability(false));
    }

    /**
     * A stored secret never reaches the HTML (the form only knows that one is stored).
     */
    #[DataProvider('contractPages')]
    public function testPageNeverPrintsAStoredSecret(PageFixture $fixture): void
    {
        foreach (['none', 'bootstrap5', 'tailwind'] as $framework) {
            self::assertStringNotContainsString(PageFixtures::SECRET, $this->renderPage($fixture, $framework)->html);
            self::assertStringNotContainsString('value="stored"', $this->renderPage($fixture, $framework)->html);
        }
    }

    protected static function templatePaths(): array
    {
        return ['NowoInstanceSettingsBundle' => dirname(__DIR__, 3) . '/src/Resources/views'];
    }

    protected static function translationDirs(): array
    {
        $vendor = dirname(__DIR__, 3) . '/vendor/nowo-tech/doctrine-encrypt-bundle/src/Resources/translations';

        return [dirname(__DIR__, 3) . '/src/Resources/translations', dirname(__DIR__, 2) . '/Fixtures/translations', $vendor];
    }

    protected static function templatesDocument(): string
    {
        return dirname(__DIR__, 3) . '/docs/TEMPLATES.md';
    }

    protected static function strictMissingTranslations(): bool
    {
        return true;
    }
}
