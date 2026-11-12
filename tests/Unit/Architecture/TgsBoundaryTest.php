<?php

namespace ApiMedia\Tgs\Tests\Unit\Architecture;

use ApiMedia\Tgs\Tests\Support\Tgs\TgsDependencies;
use ApiMedia\Tgs\Tests\TestCase;
use Illuminate\View\Compilers\BladeCompiler;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

final class TgsBoundaryTest extends TestCase
{
    public function test_tgs_has_only_documented_app_dependencies(): void
    {
        $root = dirname(__DIR__, 3);
        $boundary = require $root.'/tests/Support/Tgs/boundary.php';
        $files = $boundary['files'];
        foreach ($boundary['directories'] as $directory) {
            self::assertDirectoryExists($root.'/'.$directory);
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/'.$directory, RecursiveDirectoryIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                if ($file->isFile() && $file->getExtension() === 'php') {
                    $files[] = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
                }
            }
        }
        $files = array_values(array_unique($files));
        sort($files);
        $internal = array_fill_keys(array_map(strtolower(...), $files), true);
        $actual = [];
        $rawSql = [];
        foreach ($files as $file) {
            self::assertFileExists($root.'/'.$file);
            $source = file_get_contents($root.'/'.$file);
            if (str_ends_with($file, '.blade.php')) {
                $source = app(BladeCompiler::class)->compileString($source);
            }
            foreach (TgsDependencies::rawSqlCalls($source) as $call) {
                $rawSql[] = $file.' -> '.$call;
            }
            foreach (TgsDependencies::names($source) as $dependency) {
                $target = 'app/'.str_replace('\\', '/', substr($dependency, 4)).'.php';
                if (! isset($internal[strtolower($target)])) {
                    $actual[] = $file.' -> '.$dependency;
                }
            }
        }
        $actual = array_values(array_unique($actual));
        sort($actual);
        $exceptions = require $root.'/tests/Support/Tgs/boundary-exceptions.php';
        foreach ($exceptions as $reason) {
            self::assertNotSame('', trim($reason), 'Every boundary exception needs a reason and removal slice.');
        }
        $changes = TgsDependencies::changes($actual, array_keys($exceptions));
        self::assertSame([], $rawSql, 'TGS must use portable query/schema builders, not raw SQL.');
        self::assertSame([], $changes['new'], "New TGS → App dependencies; remove them or document the architectural decision:\n".implode("\n", $changes['new']));
        self::assertSame([], $changes['stale'], "Remove resolved exceptions:\n".implode("\n", $changes['stale']));
    }

    public function test_blade_class_references_are_parsed_and_comments_are_ignored(): void
    {
        $source = app(BladeCompiler::class)->compileString(<<<'BLADE'
{{-- \App\Ignored\Comment --}}
@php $block = \App\Models\ExampleBlock::class; @endphp
{{ \App\Models\News::class }}
BLADE);
        self::assertSame(['App\Models\ExampleBlock', 'App\Models\News'], TgsDependencies::names($source));
    }
}
