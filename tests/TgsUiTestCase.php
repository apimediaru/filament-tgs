<?php

namespace ApiMedia\Tgs\Tests;

use Illuminate\Support\Facades\Gate;
use Workbench\App\Models\User;

abstract class TgsUiTestCase extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Explicit fixture permissions; production defaults are tested separately.
        Gate::define('tgs.manageLibrary', fn ($user): bool => true);
        Gate::define('tgs.deleteEverywhere', fn ($user): bool => true);
        Gate::define('tgs.manageLocks', fn ($user): bool => false);
        $this->actingAs(User::create(['name' => 'Editor', 'email' => 'editor@example.test', 'password' => 'test-password']));
    }
}
