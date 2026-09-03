<?php

/**
 * Vercel entry point. Every request is rewritten here and Laravel's own front
 * controller takes it from there.
 */

// A function's filesystem is read-only apart from /tmp, so the writable half
// of the storage tree has to be moved there before the framework boots.
// `Application::storagePath()` reads this straight off $_ENV / $_SERVER, and
// everything derived from it — compiled Blade views, the file cache, logs —
// follows along. Config is deliberately left uncached so that these values
// are still live at runtime.
$storagePath = '/tmp/storage';

foreach ([
    '/app/private',
    '/app/public',
    '/framework/cache/data',
    '/framework/sessions',
    '/framework/views',
    '/logs',
] as $directory) {
    if (! is_dir($storagePath.$directory)) {
        mkdir($storagePath.$directory, 0755, true);
    }
}

$_ENV['LARAVEL_STORAGE_PATH'] = $storagePath;
$_SERVER['LARAVEL_STORAGE_PATH'] = $storagePath;

require __DIR__.'/../public/index.php';
