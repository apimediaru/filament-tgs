<?php

namespace ApiMedia\Tgs\Tests\Unit;

use ApiMedia\Tgs\Support\TgsDocumentation;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class DocumentationTest extends TestCase
{
    #[Test]
    public function documentation_is_optional(): void
    {
        $this->assertNull(TgsDocumentation::url());
    }

    #[Test]
    public function the_package_ships_matching_russian_and_english_guides(): void
    {
        $root = dirname(__DIR__, 2);
        $composer = json_decode(file_get_contents($root.'/composer.json'), true, flags: JSON_THROW_ON_ERROR);
        $definition = $composer['extra']['apimediaru']['documentation'];
        $this->assertSame('tgs', $definition['id']);
        $russian = glob($root.'/'.$definition['path'].'/ru/*.md');
        $english = glob($root.'/'.$definition['path'].'/en/*.md');
        $this->assertCount(7, $russian);
        $this->assertSame(array_map('basename', $russian), array_map('basename', $english));
        foreach ([...$russian, ...$english] as $file) {
            $this->assertMatchesRegularExpression('/^# .+/m', file_get_contents($file));
        }
    }
}
