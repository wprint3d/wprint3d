<?php
/**
 * Laravel OPcache Preload Script
 *
 * This script preloads core Laravel framework files into OPcache shared memory.
 * Preloaded files are available to all PHP workers without recompilation.
 *
 * IMPORTANT: This runs at PHP startup as the opcache.preload_user (www-data).
 * Any fatal error here will prevent PHP-FPM from starting.
 */

// Silence output during preload (avoid contaminating response stream)
ob_start();

// Preload Composer autoload
if (file_exists('/var/www/vendor/autoload.php')) {
    require_once '/var/www/vendor/autoload.php';
}

// Define Laravel framework paths to preload
$preloadPaths = [
    // Core Laravel framework components
    '/var/www/vendor/laravel/framework/src/Illuminate/Foundation',
    '/var/www/vendor/laravel/framework/src/Illuminate/Support',
    '/var/www/vendor/laravel/framework/src/Illuminate/Database',
    '/var/www/vendor/laravel/framework/src/Illuminate/Cache',
    '/var/www/vendor/laravel/framework/src/Illuminate/Queue',
    '/var/www/vendor/laravel/framework/src/Illuminate/Routing',
    '/var/www/vendor/laravel/framework/src/Illuminate/Http',
    '/var/www/vendor/laravel/framework/src/Illuminate/Auth',
    '/var/www/vendor/laravel/framework/src/Illuminate/Container',
    '/var/www/vendor/laravel/framework/src/Illuminate/Contracts',
    '/var/www/vendor/laravel/framework/src/Illuminate/Events',
    '/var/www/vendor/laravel/framework/src/Illuminate/Exceptions',
    '/var/www/vendor/laravel/framework/src/Illuminate/Filesystem',
    '/var/www/vendor/laravel/framework/src/Illuminate/Log',
    '/var/www/vendor/laravel/framework/src/Illuminate/Session',
    '/var/www/vendor/laravel/framework/src/Illuminate/View',
    '/var/www/vendor/laravel/framework/src/Illuminate/Validation',
];

// Preload each path
$loadedCount = 0;
$errorCount = 0;

foreach ($preloadPaths as $path) {
    if (!is_dir($path)) {
        continue;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($iterator as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            try {
                if (opcache_compile_file($file->getPathname())) {
                    $loadedCount++;
                }
            } catch (Throwable $e) {
                // Log error but continue - don't let one file break entire preload
                error_log("OPcache preload failed for {$file->getPathname()}: {$e->getMessage()}");
                $errorCount++;
            }
        }
    }
}

// Clean up any buffered output
ob_end_clean();

// Log preload results for monitoring
error_log("OPcache preload completed: {$loadedCount} files loaded, {$errorCount} errors");

// Exit successfully
exit(0);
