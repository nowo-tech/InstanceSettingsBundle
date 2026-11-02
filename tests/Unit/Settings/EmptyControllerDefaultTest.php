<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Tests\Unit\Settings;

use Nowo\InstanceSettingsBundle\Exception\InvalidSettingValueException;
use Nowo\InstanceSettingsBundle\Section\SettingsSectionRegistry;
use Nowo\InstanceSettingsBundle\Settings\InstanceSettings;
use Nowo\InstanceSettingsBundle\Tests\Fixtures\DefaultProviderFixtureSection;
use Nowo\InstanceSettingsBundle\Tests\Support\InMemorySettingValueRepository;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function array_key_last;
use function array_reverse;

/**
 * 1.3.1 audit N4: a controller saved empty (null, '', blanks) counts as its default for the `required` check, so it
 * cannot hide a field that its default shows.
 */
final class EmptyControllerDefaultTest extends TestCase
{
    /**
     * @return iterable<string, array{0: array<string, mixed>}>
     */
    public static function emptyControllers(): iterable
    {
        yield 'choice null' => [['provider' => null, 'host' => null]];
        yield 'choice empty' => [['provider' => '', 'host' => null]];
        yield 'choice blanks' => [['provider' => '   ', 'host' => '']];
        yield 'bool null' => [['tracking' => null, 'tracking_domain' => null]];
        yield 'bool empty' => [['tracking' => '', 'tracking_domain' => ' ']];
    }

    /**
     * @param array<string, mixed> $values
     */
    #[DataProvider('emptyControllers')]
    public function testEmptyControllerFallsBackToItsDefault(array $values): void
    {
        foreach ([$values, array_reverse($values, true)] as $ordered) {
            try {
                $this->settings()->save('relay', $ordered);
                self::fail('required field accepted empty');
            } catch (InvalidSettingValueException $e) {
                self::assertSame(['This value should not be blank.'], $e->violations[array_key_last($values)]);
            }
        }
    }

    public function testNonDefaultControllerStillHides(): void
    {
        $settings = $this->settings();
        self::assertSame(['provider', 'tracking'], $settings->save('relay', ['provider' => 'api', 'host' => null, 'tracking' => false, 'tracking_domain' => null]));
    }

    private function settings(): InstanceSettings
    {
        return new InstanceSettings(new InMemorySettingValueRepository(), new SettingsSectionRegistry([new DefaultProviderFixtureSection()]));
    }
}
