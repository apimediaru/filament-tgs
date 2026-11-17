<?php

namespace ApiMedia\Tgs\Tests\Feature\Tgs;

use ApiMedia\Tgs\Blocks\Code;
use ApiMedia\Tgs\Blocks\Content;
use ApiMedia\Tgs\Data\BlockCollectionData;
use ApiMedia\Tgs\Data\Shared\TgsSectionData;
use ApiMedia\Tgs\Support\TgsBlockRegistry;
use ApiMedia\Tgs\Support\TgsCommonSettings;
use ApiMedia\Tgs\Support\TgsConfiguration;
use ApiMedia\Tgs\Tests\Fixtures\CommonSettings\SiteSettings;
use ApiMedia\Tgs\Tests\TestCase;
use ApiMedia\Tgs\TgsPlugin;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Support\TypeScriptTransformer\DataTypeScriptTransformer;
use Spatie\LaravelTypeScriptTransformer\Transformers\DtoTransformer;
use Workbench\App\Models\TgsDemo;

class CommonSettingsTest extends TestCase
{
    #[Test]
    public function common_groups_are_fresh_and_block_overrides_keep_their_precedence(): void
    {
        $this->assertSame(TgsCommonSettings::class, app(TgsConfiguration::class)->commonSettingsClass());
        app(TgsConfiguration::class)->register('site', TgsPlugin::make()->commonSettings(PresentationSettings::class));
        foreach ([Content::class, Code::class] as $class) {
            foreach (['Heading', 'Section', 'Container'] as $group) {
                $method = 'getBlock'.$group.'Fields';
                $first = $class::make()->fields($method);
                $second = $class::make()->fields($method);
                $this->assertSame('Site '.$group, $first[0]->getExtraAttributes()['data-test']);
                $this->assertNotSame($first[0], $second[0]);
            }
        }
        $this->assertSame('Block heading', HeadingOverride::make()->fields('getBlockHeadingFields')[0]->getExtraAttributes()['data-test']);
        $this->assertSame('Site Heading', Content::make()->fields('getBlockHeadingFields')[0]->getExtraAttributes()['data-test']);
        app()->forgetScopedInstances();
        $this->assertSame('Site Container', Code::make()->fields('getBlockContainerFields')[0]->getExtraAttributes()['data-test']);
    }

    #[Test]
    public function check_rejects_a_leaf_that_exists_at_the_wrong_nested_path(): void
    {
        app(TgsConfiguration::class)->register('site', TgsPlugin::make()->commonSettings(WrongPathSettings::class));
        $this->artisan('tgs:check')->expectsOutputToContain('section_style.section_note')->assertFailed();
    }

    #[Test]
    public function one_registration_extends_two_registered_blocks_and_reads_legacy_data_without_a_panel(): void
    {
        Filament::setCurrentPanel(null);
        app(TgsConfiguration::class)->register('site', TgsPlugin::make()->commonSettings(SiteSettings::class));
        foreach ([Content::class, Code::class] as $block) {
            $key = $block::blockKey();
            foreach ([[], ['section_note' => 'Note', 'section_style' => ['tone' => 'calm']]] as $extra) {
                $raw = ['id' => 'old', $key => 'Text', 'section_heading' => 'Heading'] + $extra;
                $dto = BlockCollectionData::fromStored([['type' => $key, 'data' => $raw]], new TgsDemo)[0];
                $json = json_decode(json_encode($dto), true);
                $this->assertSame('Heading', $json['section']['section_heading']);
                $this->assertSame($extra['section_note'] ?? null, $json['section']['section_note']);
                $this->assertSame($extra['section_style'] ?? null, $json['section']['section_style']);
            }
        }
        foreach (app(TgsBlockRegistry::class)->all() as $class) {
            $dtoClass = $class::dataClass();
            if ($dtoClass === null || ! property_exists($dtoClass, 'section')) {
                continue;
            }
            $dto = $dtoClass::fromStored(['id' => 'legacy', 'section_note' => 'All blocks'], new TgsDemo);
            $this->assertInstanceOf(Data::class, $dto);
            $this->assertSame('All blocks', $dto->toArray()['section']['section_note'], $class);
        }
        $this->assertNull(Filament::getCurrentPanel());
    }

    #[Test]
    public function cli_generates_the_active_nested_dto_and_preserves_other_type_replacements(): void
    {
        Filament::setCurrentPanel(null);
        app(TgsConfiguration::class)->register('site', TgsPlugin::make()->commonSettings(SiteSettings::class));
        $directory = sys_get_temp_dir().'/tgs-types-'.bin2hex(random_bytes(6));
        mkdir($directory);
        $oldBase = base_path();
        app()->setBasePath($directory);
        config(['typescript-transformer.auto_discover_types' => [dirname(__DIR__, 3).'/src/Data', dirname(__DIR__, 2).'/Fixtures/CommonSettings'],
            'typescript-transformer.transformers' => [DataTypeScriptTransformer::class, DtoTransformer::class],
            'typescript-transformer.output_file' => $directory.'/laravel.d.ts']);
        try {
            $this->artisan('typescript:transform')->assertSuccessful();
            $this->artisan('tgs:generate-block-union')->assertSuccessful();
            $types = file_get_contents($directory.'/laravel.d.ts');
            $this->assertStringContainsString('section: ApiMedia.Tgs.Tests.Fixtures.CommonSettings.SiteSection;', $types);
            $this->assertStringContainsString('section_style: ApiMedia.Tgs.Tests.Fixtures.CommonSettings.StyleData | null;', $types);
            $this->assertStringContainsString('section_note: string | null;', $types);
            $this->assertStringContainsString('ApiMedia.Tgs.Data.Blocks.Content', file_get_contents($directory.'/resources/types/tgs-blocks.d.ts'));
            $this->assertNull(Filament::getCurrentPanel());
        } finally {
            app()->setBasePath($oldBase);
            File::deleteDirectory($directory);
        }
    }

    #[Test]
    public function duplicate_settings_are_allowed_but_different_panels_cannot_choose_different_contracts(): void
    {
        $config = app(TgsConfiguration::class);
        $config->register('first', TgsPlugin::make()->commonSettings(SiteSettings::class));
        $config->register('second', TgsPlugin::make()->commonSettings(SiteSettings::class));
        $config->register('unspecified', TgsPlugin::make());
        $this->assertSame(SiteSettings::class, $config->commonSettingsClass());
        $config->register('conflict', TgsPlugin::make()->commonSettings(TgsCommonSettings::class));
        $this->expectException(\LogicException::class);
        $config->commonSettingsClass();
    }

    #[Test]
    public function check_rejects_missing_common_properties_and_collisions_even_in_hidden_fields(): void
    {
        app(TgsConfiguration::class)->register('site', TgsPlugin::make()->commonSettings(BrokenSettings::class));
        $this->artisan('tgs:check')->expectsOutputToContain('section_missing')
            ->expectsOutputToContain('content')->assertFailed();
    }

    #[Test]
    public function check_accepts_the_active_common_dto_with_nested_paths(): void
    {
        app(TgsConfiguration::class)->register('site', TgsPlugin::make()->commonSettings(SiteSettings::class));
        $exit = Artisan::call('tgs:check');
        $this->assertSame(0, $exit, Artisan::output());
    }

    #[Test]
    public function an_unexported_common_dto_is_rejected_before_it_can_produce_dangling_types(): void
    {
        app(TgsConfiguration::class)->register('site', TgsPlugin::make()->commonSettings(UnmarkedSettings::class));
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('TypeScript');
        app(TgsCommonSettings::class);
    }
}

class BrokenSettings extends SiteSettings
{
    public function section(array $fields): array
    {
        return [...parent::section($fields), TextInput::make('section_missing')->hidden(),
            TextInput::make('content')];
    }
}

class UnmarkedSection extends TgsSectionData {}
class UnmarkedSettings extends SiteSettings
{
    public static function dataClass(): string
    {
        return UnmarkedSection::class;
    }
}

class PresentationSettings extends TgsCommonSettings
{
    public function heading(array $fields): array
    {
        $fields[0]->extraAttributes(['data-test' => 'Site Heading']);

        return $fields;
    }

    public function section(array $fields): array
    {
        $fields[0]->extraAttributes(['data-test' => 'Site Section']);

        return $fields;
    }

    public function container(array $fields): array
    {
        $fields[0]->extraAttributes(['data-test' => 'Site Container']);

        return $fields;
    }
}
class HeadingOverride extends Code
{
    public function getBlockHeadingFields(): array|\Closure
    {
        $fields = parent::getBlockHeadingFields();
        $fields[0]->extraAttributes(['data-test' => 'Block heading']);

        return $fields;
    }
}
class WrongPathSettings extends SiteSettings
{
    public function section(array $fields): array
    {
        return [...parent::section($fields), TextInput::make('section_style.section_note')];
    }
}
