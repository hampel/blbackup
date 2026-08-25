<?php

use App\Kernel;
use LaravelZero\Framework\Application;

$app = Application::configure(basePath: dirname(__DIR__))->create();

/*
 * Rebind the console kernel over the one Application::configure() just bound, so an
 * unrecognised command name fails instead of being proxied to the summary and exiting
 * 0. App\Kernel says why that matters here. Without this rebinding the class sits
 * there doing nothing.
 */
$app->singleton(
    Illuminate\Contracts\Console\Kernel::class,
    Kernel::class
);

if (\Phar::running(false))
{
    $app->useStoragePath(env('LARAVEL_STORAGE_PATH', getcwd()));
}

return $app;
