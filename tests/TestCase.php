<?php

namespace Tests;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Illuminate\Foundation\Testing\CachedState;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Foundation\Testing\WithCachedConfig;
use Illuminate\Foundation\Testing\WithCachedRoutes;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    public function createApplication()
    {
        $sandbox = getenv('EXAM_TEST_SANDBOX');
        if ($sandbox === false || $sandbox === '') {
            return parent::createApplication();
        }

        $sandbox = realpath($sandbox);
        $allowed = realpath(dirname(__DIR__).'/.atl/exam-idle-timeout-checks');
        if (! $sandbox || ! $allowed || ! str_starts_with($sandbox, $allowed.DIRECTORY_SEPARATOR)
            || ! is_dir($sandbox.'/env') || count(scandir($sandbox.'/env')) !== 2) {
            throw new RuntimeException('An owned sandbox with an empty environment directory is required.');
        }

        $app = require dirname(__DIR__).'/bootstrap/app.php';
        $app->useEnvironmentPath($sandbox.'/env');
        $app->useStoragePath($sandbox.'/storage');
        $this->traitsUsedByTest = class_uses_recursive(static::class);

        if (isset(CachedState::$cachedConfig, $this->traitsUsedByTest[WithCachedConfig::class])) {
            $this->markConfigCached($app);
        }
        if (isset(CachedState::$cachedRoutes, $this->traitsUsedByTest[WithCachedRoutes::class])) {
            $app->booting(fn () => $this->markRoutesCached($app));
        }

        // Fail before providers or database traits can use an unsafe configuration.
        $app->afterBootstrapping(LoadConfiguration::class, function ($app) use ($sandbox): void {
            $config = $app['config'];
            if ($config->get('database.default') !== 'sqlite'
                || $config->get('database.connections.sqlite.database') !== ':memory:'
                || $config->get('database.connections.sqlite.url')
                || $config->get('cache.default') !== 'array'
                || $config->get('session.driver') !== 'array'
                || $config->get('logging.default') !== 'single') {
                throw new RuntimeException('Isolated tests require SQLite :memory: and local test drivers.');
            }
            foreach ([$app->storagePath(), $app->getCachedConfigPath(), $app->getCachedServicesPath(),
                $app->getCachedPackagesPath(), $app->getCachedRoutesPath(), $app->getCachedEventsPath(),
                $config->get('view.compiled'), $config->get('session.files'),
                $config->get('cache.stores.file.path'), $config->get('logging.channels.single.path'),
            ] as $path) {
                if (! str_starts_with(str_replace('\\', '/', $path), str_replace('\\', '/', $sandbox).'/')) {
                    throw new RuntimeException('A writable test path escaped the sandbox.');
                }
            }
        });
        $app->make(Kernel::class)->bootstrap();

        return $app;
    }
}
