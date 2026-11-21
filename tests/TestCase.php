<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    public function createApplication()
    {
        $app = parent::createApplication();
        $compiledViews = storage_path('framework/views/testing-'.getmypid());
        $app['files']->ensureDirectoryExists($compiledViews);
        $app['config']->set('view.compiled', $compiledViews);
        if (config('database.default') === 'mysql' && config('database.connections.mysql.database') !== 'school_tutoring_test') {
            throw new \RuntimeException('Las pruebas MySQL solo pueden usar school_tutoring_test.');
        }

        return $app;
    }
}
