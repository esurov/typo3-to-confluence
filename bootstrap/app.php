<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Illuminate\Foundation\Configuration\Exceptions;

$app = Application::configure(basePath: dirname(__DIR__))
    ->withCommands([
        \App\Console\Commands\ExportTypo3ToConfluence::class,
    ])
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();

// Disable merging framework's bundled config (getRealPath() fails inside PHARs)
$app->dontMergeFrameworkConfiguration();

// When running inside a PHAR, set up writable paths and provide config directly
// since realpath() doesn't work on phar:// streams (breaks config file discovery)
if (Phar::running()) {
    $tempDir = sys_get_temp_dir().'/typo3-to-confluence';

    foreach ([$tempDir, "$tempDir/cache", "$tempDir/logs", "$tempDir/framework/cache", "$tempDir/framework/views"] as $dir) {
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
    }

    $app->useStoragePath($tempDir);
    $app->useBootstrapPath($tempDir);
    $app->useEnvironmentPath(getcwd());

    // Provide config directly to avoid realpath() issues in LoadConfiguration
    LoadConfiguration::alwaysUse(function () use ($tempDir) {
        return [
            'app' => [
                'name' => 'TYPO3 to Confluence',
                'env' => 'production',
                'debug' => false,
                'url' => 'http://localhost',
                'timezone' => 'UTC',
                'locale' => 'en',
                'fallback_locale' => 'en',
                'cipher' => 'AES-256-CBC',
                'key' => 'base64:'.base64_encode(random_bytes(32)),
                'previous_keys' => [],
                'maintenance' => ['driver' => 'file'],
                'providers' => [
                    \Illuminate\Bus\BusServiceProvider::class,
                    \Illuminate\Foundation\Providers\ConsoleSupportServiceProvider::class,
                    \Illuminate\Database\DatabaseServiceProvider::class,
                    \Illuminate\Encryption\EncryptionServiceProvider::class,
                    \Illuminate\Filesystem\FilesystemServiceProvider::class,
                    \Illuminate\Foundation\Providers\FoundationServiceProvider::class,
                    \App\Providers\AppServiceProvider::class,
                ],
            ],
            'database' => [
                'default' => 'typo3',
                'connections' => [
                    'typo3' => [
                        'driver' => 'mysql',
                        'host' => env('TYPO3_DB_HOST', '127.0.0.1'),
                        'port' => env('TYPO3_DB_PORT', '3306'),
                        'database' => env('TYPO3_DB_DATABASE', 'typo3'),
                        'username' => env('TYPO3_DB_USERNAME', 'root'),
                        'password' => env('TYPO3_DB_PASSWORD', ''),
                        'charset' => 'utf8mb4',
                        'collation' => 'utf8mb4_unicode_ci',
                        'prefix' => '',
                        'prefix_indexes' => true,
                        'strict' => true,
                        'engine' => null,
                        'options' => [],
                    ],
                ],
            ],
            'logging' => [
                'default' => 'stderr',
                'deprecations' => ['channel' => 'null', 'trace' => false],
                'channels' => [
                    'stderr' => [
                        'driver' => 'monolog',
                        'level' => 'warning',
                        'handler' => \Monolog\Handler\StreamHandler::class,
                        'handler_with' => ['stream' => 'php://stderr'],
                        'processors' => [\Monolog\Processor\PsrLogMessageProcessor::class],
                    ],
                    'null' => [
                        'driver' => 'monolog',
                        'handler' => \Monolog\Handler\NullHandler::class,
                    ],
                    'emergency' => [
                        'path' => $tempDir.'/logs/laravel.log',
                    ],
                ],
            ],
        ];
    });
}

return $app;
