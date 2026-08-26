<?php

namespace App\Commands;

use Illuminate\Console\Scheduling\Schedule;

/**
 * Run the whole backup from one cron entry
 *
 * Two things live here that cannot live anywhere else.
 *
 * The first is the run summary. Every stage command can be run by hand, and a
 * summary posted for a command somebody is watching run is noise sent to the
 * channel of the person watching it. Posting is therefore a property of this
 * command - the unattended entry point - rather than something the stages guess
 * at from the terminal. Symfony is no help there anyway: isInteractive() is only
 * cleared by --no-interaction or --quiet, so it reports true under cron and would
 * have silenced exactly the runs the summary exists for.
 *
 * The second is the order. As two crontab lines, the expiry started when the
 * crontab guessed the downloads would be finished; here it starts when they
 * actually are.
 */
class Cron extends BaseCommand
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'cron
                            {--include= : include only this list of servers}
                            {--exclude= : exclude this list of servers}
                            {--no-move : download only, even if an rclone remote is configured}
                            {--no-clean : skip expiring old backups}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Run every stage in turn, for a single cron entry';

    protected string $commandContext = 'cron';

    /**
     * This is the command that reports the run. The stages it calls see it is
     * taken and stay quiet.
     */
    protected bool $summarises = true;

    /**
     * Execute the console command.
     *
     * There is deliberately no --dry-run. clean, check and move have one, but
     * create and download do not - it would really take a snapshot and really
     * pull it down, which is the opposite of what the flag promises anywhere
     * else. Run the stages themselves to rehearse.
     */
    public function handle()
    {
        $remote = $this->remote();
        $move = $remote !== null && !$this->option('no-move');

        $this->announce($remote, $move);

        $failed = false;

        foreach ($this->stages($remote, $move) as $stage => $arguments)
        {
            $this->section($stage);

            // a stage that fails does not stop the ones after it: servers that
            // would not back up tonight are no reason to leave last month's
            // downloads filling the disk
            if ($this->call($stage, $arguments) !== self::SUCCESS)
            {
                $failed = true;

                $this->log(
                    'error',
                    "Backup stage [{$stage}] failed",
                    "Backup stage failed",
                    ['stage' => $stage]
                );
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @return array<string, array<string, mixed>> each stage and the arguments
     *                                             it runs with, in order
     */
    protected function stages(?string $remote, bool $move) : array
    {
        $create = ['--all' => true, '--download' => true];

        if ($move)
        {
            $create['--move'] = true;
        }

        foreach (['include', 'exclude'] as $option)
        {
            if ($this->option($option))
            {
                $create["--{$option}"] = $this->option($option);
            }
        }

        $stages = ['create' => $create];

        if (!$this->option('no-clean'))
        {
            // never prompt: this command is the unattended one by definition, and
            // a confirm() with no stdin to read is an exception, not a default.
            // The remote is expired whenever one is configured, --no-move or not -
            // what was shipped there on previous nights still ages.
            $clean = ['--no-interaction' => true];

            if ($remote !== null)
            {
                $clean['--remote'] = true;
            }

            $stages['clean'] = $clean;
        }

        return $stages;
    }

    /**
     * Say which shape of run this is before doing any of it.
     *
     * Whether the backups leave this machine is the difference between an
     * installation that survives losing it and one that does not, and it is
     * decided by whether RCLONE_REMOTE is set - so it is worth a line in the log
     * of every run rather than something to work out from what the run did.
     */
    protected function announce(?string $remote, bool $move) : void
    {
        if ($move)
        {
            $this->log(
                'notice',
                "Backing up all servers, downloading and moving to [{$remote}]",
                "Starting backup run",
                ['mode' => 'move', 'remote' => $remote]
            );

            return;
        }

        $reason = $remote === null
            ? 'no rclone remote is configured'
            : 'moving is switched off for this run';

        $this->log(
            'notice',
            "Backing up all servers, downloading only - {$reason}",
            "Starting backup run",
            ['mode' => 'download', 'reason' => $reason]
        );
    }

    /**
     * @return string|null the configured rclone remote, or null if this
     *                     installation keeps its backups where it downloads them
     */
    protected function remote() : ?string
    {
        $remote = trim((string) config('binarylane.rclone.remote'));

        return $remote === '' ? null : $remote;
    }

    /**
     * Define the command's schedule.
     */
    public function schedule(Schedule $schedule): void
    {
        // driven by the system crontab, not by the framework scheduler
    }
}
