<?php

declare(strict_types=1);

namespace ApiMedia\Tgs\Tests\Feature\Tgs;

use ApiMedia\Tgs\Data\BlockRegistry;
use ApiMedia\Tgs\Tests\TestCase;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;
use Spatie\LaravelData\Support\TypeScriptTransformer\DataTypeScriptTransformer;
use Spatie\LaravelTypeScriptTransformer\Transformers\DtoTransformer;
use Spatie\TypeScriptTransformer\Transformers\EnumTransformer;

class TgsBlockUnionGenerationTest extends TestCase
{
    #[Test]
    public function installed_package_types_and_block_union_are_exported_together(): void
    {
        $output = resource_path('types/tgs-contract-test.d.ts');
        config([
            'typescript-transformer.auto_discover_types' => [__DIR__.'/../../../src'],
            'typescript-transformer.transformers' => [EnumTransformer::class, DataTypeScriptTransformer::class, DtoTransformer::class],
            'typescript-transformer.output_file' => $output,
        ]);

        try {
            $this->artisan('typescript:transform')->assertSuccessful();
            $this->artisan('tgs:generate-block-union')->assertSuccessful();
            $types = File::get($output);
            $union = File::get(resource_path('types/tgs-blocks.d.ts'));
            $this->assertStringContainsString('namespace ApiMedia.Tgs.Data.Blocks', $types);
            $expectedTypes = ['Accordion', 'Code', 'Collapse', 'Column', 'Content', 'DoubleColumn', 'Embedded', 'Gallery', 'Picture', 'Popup', 'Tabs'];
            $this->assertCount(count($expectedTypes), BlockRegistry::map());
            foreach ($expectedTypes as $name) {
                $this->assertStringContainsString('export type '.$name.' =', $types);
                $this->assertStringContainsString('ApiMedia.Tgs.Data.Blocks.'.$name, $union);
            }
            $this->assertStringContainsString('type: "content"', $types);
            $this->assertStringContainsString('content: string | null', $types);
            $this->assertStringContainsString('export type UnknownBlock', $types);
            $this->assertStringContainsString('export type Block', $union);
            $this->assertStringContainsString('ApiMedia.Tgs.Data.UnknownBlock', $union);
            $this->assertStringNotContainsString('App.', $types.$union);
        } finally {
            File::delete($output);
        }
    }
}
