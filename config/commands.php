<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Command
    |--------------------------------------------------------------------------
    |
    | Laravel Zero will always run the command specified below when no command name is
    | provided. Consider update the default command for single command applications.
    | You cannot pass arguments to the default command because they are ignored.
    |
    */

    'default' => NunoMaduro\LaravelConsoleSummary\SummaryCommand::class,

    /*
    |--------------------------------------------------------------------------
    | Commands Paths
    |--------------------------------------------------------------------------
    |
    | This value determines the "paths" that should be loaded by the console's
    | kernel. Foreach "path" present on the array provided below the kernel
    | will extract all "Illuminate\Console\Command" based class commands.
    |
    */

    'paths' => [app_path('Commands')],

    /*
    |--------------------------------------------------------------------------
    | Added Commands
    |--------------------------------------------------------------------------
    |
    | You may want to include a single command class without having to load an
    | entire folder. Here you can specify which commands should be added to
    | your list of commands. The console's kernel will try to load them.
    |
    */

    'add' => [
        //
    ],

    /*
    |--------------------------------------------------------------------------
    | Hidden Commands
    |--------------------------------------------------------------------------
    |
    | Your application commands will always be visible on the application list
    | of commands. But you can still make them "hidden" specifying an array
    | of commands below. All "hidden" commands can still be run/executed.
    |
    */

    'hidden' => [
        NunoMaduro\LaravelConsoleSummary\SummaryCommand::class,
        Symfony\Component\Console\Command\DumpCompletionCommand::class,
        Symfony\Component\Console\Command\HelpCommand::class,
        Illuminate\Foundation\Console\VendorPublishCommand::class,
        LaravelZero\Framework\Commands\StubPublishCommand::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Removed Commands
    |--------------------------------------------------------------------------
    |
    | Do you have a service provider that loads a list of commands that
    | you don't need? No problem. Laravel Zero allows you to specify
    | below a list of commands that you don't to see in your app.
    |
    */

    'remove' => [
        /*
         * Removed rather than hidden, which is the whole point: a hidden command
         * is absent from the command list and still runs. `blbackup schedule:run`
         * printed "No scheduled commands are ready to run" and exited 0 - a
         * perfectly clean success from a command that does nothing and that this
         * application has no use for, in a tool whose exit code is the whole of
         * what cron reads. A crontab copied from another Laravel Zero app would
         * back up nothing and report success for as long as nobody looked.
         *
         * The scheduler could not run these commands anyway: a due event resolves
         * Illuminate\Log\Context\Repository, which needs a trait from
         * illuminate/queue that a console application does not install, and in a
         * compiled binary the working directory it hands Symfony Process is a
         * phar:// path that Process rejects. ScheduleRunCommand swallows both and
         * exits 0 regardless.
         *
         * The schedule() methods on the commands are left in place, commented
         * out, so the next person can see the decision rather than wonder.
         * `blbackup cron` is what a crontab calls.
         */
        Illuminate\Console\Scheduling\ScheduleRunCommand::class,
        Illuminate\Console\Scheduling\ScheduleListCommand::class,
        Illuminate\Console\Scheduling\ScheduleFinishCommand::class,
    ],

];
