<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Tests\Unit\Section;

use InvalidArgumentException;
use LogicException;
use Nowo\InstanceSettingsBundle\Form\SettingColorType;
use Nowo\InstanceSettingsBundle\Section\AbstractSettingsSection;
use Nowo\InstanceSettingsBundle\Section\SettingField;
use Nowo\InstanceSettingsBundle\Section\SettingsSectionRegistry;
use Nowo\InstanceSettingsBundle\Section\SettingType;
use Nowo\InstanceSettingsBundle\Seed\FallbackSeeder;
use Nowo\InstanceSettingsBundle\Seed\SeedFileSeeder;
use Nowo\InstanceSettingsBundle\Settings\InstanceSettings;
use Nowo\InstanceSettingsBundle\Settings\ValueNormalizer;
use Nowo\InstanceSettingsBundle\Tests\Fixtures\ColorFixtureSection;
use Nowo\InstanceSettingsBundle\Tests\Support\InMemorySettingValueRepository;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Validation;

use function sys_get_temp_dir;

/**
 * 1.12: the colour field definition (SettingType::Color, SettingField::color(), withPresetFrom(), presetColor()), its
 * registry checks and the save rule "a colour equal to its preset is stored as null".
 */
final class ColorFieldDefinitionTest extends TestCase
{
    public function testColorFieldDefaults(): void
    {
        $field = SettingField::color('accent', '#ABCDEF', 'l', 'h', true);

        self::assertSame(SettingType::Color, $field->type);
        self::assertFalse(SettingType::Color->isString(), 'a colour is never a secret');
        self::assertSame('#abcdef', $field->default);
        self::assertSame('#abcdef', $field->presetColor([]));
        self::assertNull($field->presetFrom());
        self::assertTrue(SettingField::matchesType(SettingType::Color, '#A1b2C3'));
        self::assertFalse(SettingField::matchesType(SettingType::Color, '#abc'));
        self::assertFalse(SettingField::matchesType(SettingType::Color, 0xABCDEF));
        self::assertNull(SettingField::color('x')->presetColor(['y' => 'z']));
        self::assertNull(SettingField::string('x', '#abcdef')->presetColor([]), 'only colour fields have a preset');
        self::assertNull(SettingField::string('x')->presetFrom());
        self::assertSame('#ABCDEF', (new SettingField('raw', SettingType::Color, '#ABCDEF'))->default, 'the constructor keeps the declared default');
        self::assertSame('#abcdef', (new SettingField('raw', SettingType::Color, '#ABCDEF'))->presetColor([]));
    }

    public function testPresetFromFollowsTheSourceValue(): void
    {
        $field = SettingField::color('link', '#000000')->withPresetFrom('scheme', ['light' => '#AABBCC', 'dark' => '#112233', 1 => '#445566']);

        self::assertSame(['field' => 'scheme', 'colors' => ['light' => '#aabbcc', 'dark' => '#112233', '1' => '#445566']], $field->presetFrom());
        self::assertSame('#112233', $field->presetColor(['scheme' => 'dark']));
        self::assertSame('#445566', $field->presetColor(['scheme' => true]), 'booleans compare as 1 / empty');
        self::assertSame('#000000', $field->presetColor(['scheme' => 'other']), 'unmapped value: the default');
        self::assertSame('#000000', $field->presetColor([]));
        self::assertSame(['field' => 'scheme', 'colors' => ['light' => '#aabbcc', 'dark' => '#112233', '1' => '#445566']], $field->formOptions[SettingColorType::PRESET_FROM]);
        self::assertSame('#112233', $field->withGroup('colors')->presetColor(['scheme' => 'dark']), 'copies keep the source');
    }

    public function testAHandEditedPresetSourceIsReadDefensively(): void
    {
        $broken = SettingField::color('a')->withFormOptions([SettingColorType::PRESET_FROM => 'nope']);
        self::assertNull($broken->presetFrom());

        $partial = SettingField::color('a')->withFormOptions([SettingColorType::PRESET_FROM => ['field' => 'b', 'colors' => ['x' => '#ABCDEF', 'y' => 'red']]]);
        self::assertSame(['field' => 'b', 'colors' => ['x' => '#abcdef']], $partial->presetFrom());
    }

    /**
     * @return iterable<string, array{0: callable(): SettingField, 1: string}>
     */
    public static function invalidDefinitions(): iterable
    {
        yield 'invalid default' => [static fn (): SettingField => SettingField::color('a', 'red'), 'does not match the color type'];
        yield 'secret colour' => [static fn (): SettingField => new SettingField('a', SettingType::Color, secret: true), 'can be secret'];
        yield 'preset source on a string' => [static fn (): SettingField => SettingField::string('a')->withPresetFrom('b', ['x' => '#000000']), 'only for colour fields'];
        yield 'preset source is itself' => [static fn (): SettingField => SettingField::color('a')->withPresetFrom('a', ['x' => '#000000']), 'another field name'];
        yield 'preset source bad name' => [static fn (): SettingField => SettingField::color('a')->withPresetFrom('B!', ['x' => '#000000']), 'another field name'];
        yield 'no preset colours' => [static fn (): SettingField => SettingField::color('a')->withPresetFrom('b', []), 'at least one colour'];
        yield 'invalid preset colour' => [static fn (): SettingField => SettingField::color('a')->withPresetFrom('b', ['x' => 'url(x)']), 'are #rrggbb'];
    }

    /**
     * @param callable(): SettingField $build
     */
    #[DataProvider('invalidDefinitions')]
    public function testInvalidDefinitionsAreRefused(callable $build, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);
        $build();
    }

    /**
     * @return iterable<string, array{0: list<SettingField>}>
     */
    public static function invalidSources(): iterable
    {
        yield 'unknown field' => [[SettingField::color('a')->withPresetFrom('nope', ['x' => '#000000'])]];
        yield 'secret field' => [[SettingField::secret('s'), SettingField::color('a')->withPresetFrom('s', ['x' => '#000000'])]];
        yield 'image field' => [[SettingField::image('i'), SettingField::color('a')->withPresetFrom('i', ['x' => '#000000'])]];
    }

    /**
     * @param list<SettingField> $fields
     */
    #[DataProvider('invalidSources')]
    public function testTheRegistryRefusesAPresetSourceThatIsNotRendered(array $fields): void
    {
        $registry = new SettingsSectionRegistry([$this->section($fields)]);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('the preset colour source');
        $registry->fields('demo');
    }

    public function testSaveStoresNullForThePresetColour(): void
    {
        $repository = new InMemorySettingValueRepository();
        $settings   = new InstanceSettings($repository, new SettingsSectionRegistry([new ColorFixtureSection()]), new ValueNormalizer(Validation::createValidator()));

        self::assertSame(['accent', 'link'], $settings->save('palette', ['accent' => '#ABCDEF', 'link' => '#0000FF']));
        self::assertSame('#abcdef', $settings->getString('palette.accent'));
        self::assertSame('#0000ff', $settings->getString('palette.link'));

        // The default (any case) and the colour of the stored source value are dropped.
        self::assertSame(['accent', 'link'], $settings->save('palette', ['accent' => '#1d4ed8', 'link' => '#112233']));
        self::assertFalse($settings->isOverridden('palette.accent'));
        self::assertFalse($settings->isOverridden('palette.link'));

        // The source value saved in the same call decides.
        self::assertSame(['scheme', 'link'], $settings->save('palette', ['scheme' => 'dark', 'link' => '#112233']));
        self::assertSame('#112233', $settings->getString('palette.link'));
        self::assertSame(['link'], $settings->save('palette', ['link' => '#DDEEFF']));
        self::assertFalse($settings->isOverridden('palette.link'));

        // Unchanged preset colours change nothing.
        self::assertSame([], $settings->save('palette', ['accent' => '#1D4ED8']));
    }

    public function testSeedersSkipThePresetColour(): void
    {
        $registry   = new SettingsSectionRegistry([new ColorFixtureSection()]);
        $repository = new InMemorySettingValueRepository();
        $settings   = new InstanceSettings($repository, $registry, new ValueNormalizer(Validation::createValidator()));

        $report = (new FallbackSeeder($registry, $repository, $settings))->seed();
        self::assertSame(['palette.scheme', 'palette.legacy'], $report->seeded);
        self::assertSame(SeedFileSeeder::SKIP_DEFAULT, $report->skipped['palette.accent']);
        self::assertSame(SeedFileSeeder::SKIP_DEFAULT, $report->skipped['palette.link'], 'the preset of the seeded scheme');

        $repository = new InMemorySettingValueRepository();
        $settings   = new InstanceSettings($repository, $registry, new ValueNormalizer(Validation::createValidator()));
        $seeder     = new SeedFileSeeder($registry, $repository, $settings, sys_get_temp_dir());
        $report     = $seeder->seed(['settings' => ['palette' => ['accent' => '#1D4ED8', 'scheme' => 'dark', 'link' => '#112233']]], sys_get_temp_dir());
        self::assertSame(SeedFileSeeder::SKIP_DEFAULT, $report->skipped['palette.accent']);
        self::assertContains('palette.link', $report->seeded, '#112233 is custom for the dark scheme');
        self::assertSame('#112233', $settings->getString('palette.link'));
    }

    /**
     * @param list<SettingField> $fields
     */
    private function section(array $fields): AbstractSettingsSection
    {
        return new class($fields) extends AbstractSettingsSection {
            /**
             * @param list<SettingField> $fields
             */
            public function __construct(private readonly array $fields)
            {
            }

            public function getName(): string
            {
                return 'demo';
            }

            public function getFields(): iterable
            {
                return $this->fields;
            }
        };
    }
}
