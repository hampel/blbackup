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

/*
 * Which environment file the framework will read, recorded so app:config can report it,
 * and say where it looked when there was none.
 *
 * This does not choose the file. Laravel Zero does: the .env beside a compiled binary,
 * and the project's own from a checkout, which is where the container mounts it. This
 * only writes the answer down, because environmentFilePath() answers with
 * base_path().'/.env' whether or not anything is there - and inside a phar that names a
 * file in the archive that has never been opened, which reads exactly like a real answer.
 *
 * The same binding names wback and sites use, which also search further afield before
 * loading. blbackup does not, so there is one candidate, not a list to walk.
 */
$phar = \Phar::running(false);
$envFile = $phar ? dirname($phar) . DIRECTORY_SEPARATOR . '.env' : $app->environmentFilePath();

$app->instance('blbackup.env.loaded', is_file($envFile) ? $envFile : null);
$app->instance('blbackup.env.candidates', [$envFile]);

if ($phar)
{
    $app->useStoragePath(env('LARAVEL_STORAGE_PATH', getcwd()));
}

return $app;
