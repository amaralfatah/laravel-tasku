<?php

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;

/**
 * Vercel entry point. Every request is rewritten here.
 *
 * Laravel is booted directly instead of through `public/index.php`, because
 * `public/` is the deployment's static output directory: anything left in it
 * is served as a plain file, and the filesystem check runs before `rewrites`,
 * so a `public/index.php` would be handed to the browser as PHP source. It is
 * excluded in `.vercelignore` for that reason.
 */
define('LARAVEL_START', microtime(true));

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

require __DIR__.'/../vendor/autoload.php';

/** @var Application $app */
$app = require_once __DIR__.'/../bootstrap/app.php';

$app->handleRequest(Request::capture());
