<?php

namespace Harri\LaravelMpesa\Tests\Concerns;

use Illuminate\Contracts\Console\Kernel;

trait RefreshPackageDatabase
{
    protected function setUpRefreshPackageDatabase(): void
    {
        $this->refreshPackageDatabase();
    }

    protected function refreshPackageDatabase(): void
    {
        if (method_exists($this, 'beforeRefreshingDatabase')) {
            $this->beforeRefreshingDatabase();
        }

        $this->resetTestingDatabase();

        $this->artisan('migrate', ['--database' => config('database.default', 'testing')]);

        $this->app[Kernel::class]->setArtisan(null);

        if (method_exists($this, 'afterRefreshingDatabase')) {
            $this->afterRefreshingDatabase();
        }
    }
}
