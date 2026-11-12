<?php

namespace ApiMedia\Tgs\Tests\Unit\Architecture;

use ApiMedia\Tgs\Tests\Support\Tgs\TgsDependencies;
use PHPUnit\Framework\TestCase;

final class TgsDependenciesTest extends TestCase
{
    public function test_resolves_imports_implicit_names_attributes_and_literal_classes(): void
    {
        $source = <<<'PHP'
<?php
namespace App\Models;
use App\Settings\{WebsiteSettings as Settings, OtherSettings};
use App\Services\Tgs\CanvasPersistence;
/** @property \App\Ignored $comment */
#[\App\Attributes\Marker]
class BlockMedia extends Media {
    public function run(Settings $settings): User {
        $service = CanvasPersistence::class;
        $name = 'App\\Settings\\LiteralSettings';
        return new User;
    }
}
PHP;
        self::assertSame([
            'App\Attributes\Marker',
            'App\Models\Media',
            'App\Models\User',
            'App\Services\Tgs\CanvasPersistence',
            'App\Settings\LiteralSettings',
            'App\Settings\OtherSettings',
            'App\Settings\WebsiteSettings',
        ], TgsDependencies::names($source));
    }

    public function test_reports_new_pairs_and_stale_exceptions_independently(): void
    {
        self::assertSame([
            'new' => ['app/A.php -> App\Models\News'],
            'stale' => ['app/B.php -> App\Models\News'],
        ], TgsDependencies::changes(
            ['app/A.php -> App\Models\News', 'app/C.php -> App\Models\Page'],
            ['app/B.php -> App\Models\News', 'app/C.php -> App\Models\Page'],
        ));
    }

    public function test_namespace_alias_is_not_mistaken_for_a_class_and_app_prefix_is_case_insensitive(): void
    {
        self::assertSame(['App\Models\Block', 'App\Models\User'], TgsDependencies::names(<<<'PHP'
<?php
use App\Models as Models;
new Models\Block;
\app\Models\User::query();
PHP));
    }

    public function test_literal_static_callable_keeps_its_class_dependency(): void
    {
        self::assertSame(['App\Models\User'], TgsDependencies::names(<<<'PHP'
<?php
call_user_func('App\\Models\\User::query');
PHP));
    }

    public function test_raw_sql_detection_resolves_db_aliases_and_ignores_comments_and_query_builder_columns(): void
    {
        self::assertSame(['DB::raw', 'DB::select', 'orderByRaw', 'statement'], TgsDependencies::rawSqlCalls(<<<'PHP'
<?php
use Illuminate\Support\Facades\DB as Database;
// Database::unprepared('ignored');
Database::raw('one');
Database::select('select two');
Database::table('items')->select('name')->where('id', 1)->orderByRaw('name');
Database::connection()->statement('three');
PHP));
    }
}
