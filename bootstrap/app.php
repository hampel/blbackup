<?php

use LaravelZero\Framework\Application;

$app = Application::configure(basePath: dirname(__DIR__))->create();

if (\Phar::running(false))
{
    $app->useStoragePath(env('LARAVEL_STORAGE_PATH', getcwd()));
}

return $app;
