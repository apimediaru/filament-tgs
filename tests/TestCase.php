<?php

namespace ApiMedia\Tgs\Tests;

use ApiMedia\Tgs\Tests\Fixtures\PackageTestPanelProvider;
use ApiMedia\Tgs\TgsServiceProvider;
use Filament\Facades\Filament;
use Orchestra\Testbench\TestCase as Orchestra;
use Workbench\App\Models\User;

abstract class TestCase extends Orchestra
{
    protected const PANEL_PROVIDER = PackageTestPanelProvider::class;

    protected $enablesPackageDiscoveries = true;

    protected function getPackageProviders($app): array
    {
        $installed = json_decode(file_get_contents(__DIR__.'/../vendor/composer/installed.json'), true);
        $providers = [];
        foreach ($installed['packages'] as $package) {
            foreach ($package['extra']['laravel']['providers'] ?? [] as $provider) {
                $providers[] = $provider;
            }
        }

        return array_values(array_unique([...$providers, TgsServiceProvider::class, static::PANEL_PROVIDER]));
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('t', 32)));
        $app['config']->set('database.default', 'testing');
        $driver = getenv('TGS_TEST_DRIVER') ?: 'sqlite';
        $connection = ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true];
        if ($driver === 'pgsql') {
            $database = getenv('TGS_TEST_DATABASE') ?: 'tgs_test';
            if (! preg_match('/(?:^|_)test(?:_|$)/', $database)) {
                throw new \RuntimeException('Refusing to migrate a non-test database.');
            }
            $connection = ['driver' => 'pgsql', 'host' => getenv('TGS_TEST_HOST') ?: '127.0.0.1',
                'port' => getenv('TGS_TEST_PORT') ?: '5432', 'database' => $database,
                'username' => getenv('TGS_TEST_USERNAME') ?: 'tgs', 'password' => getenv('TGS_TEST_PASSWORD') ?: 'tgs',
                'charset' => 'utf8', 'prefix' => '', 'search_path' => 'public', 'sslmode' => 'prefer'];
        }
        $app['config']->set('database.connections.testing', $connection);
        $app['config']->set('auth.providers.users.model', User::class);
        $app['config']->set('cache.default', 'array');
        $app['config']->set('app.locale', 'ru');
        $app['config']->set('hashing.bcrypt.rounds', 4);
        $app['config']->set('session.driver', 'array');
        $app['config']->set('media-library.queue_conversions_by_default', false);
        $app['config']->set('media-library.disk_name', 'public');
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(\Orchestra\Testbench\default_migration_path());
        $this->loadMigrationsFrom(__DIR__.'/../workbench/database/migrations');
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->app['view']->addNamespace('tgs-workbench', __DIR__.'/../workbench/resources/views');
        Filament::setCurrentPanel(Filament::getPanel('workbench'));
        Filament::bootCurrentPanel();
    }
}
