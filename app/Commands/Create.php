<?php

namespace App\Commands;

use Carbon\CarbonInterval;
use Hampel\BinaryLane\Api\Entity\Action;
use Hampel\BinaryLane\Api\Entity\Server;
use Hampel\BinaryLane\Api\Enum\BackupSlot;
use Hampel\BinaryLane\Api\Exception\ActionBlockedException;
use Hampel\BinaryLane\Api\Exception\ActionFailedException;
use Hampel\BinaryLane\Api\Exception\ActionTimedOutException;
use Hampel\BinaryLane\Api\Exception\MalformedResponseException;
use Hampel\BinaryLane\Api\Request\TakeBackup;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Number;
use Illuminate\Support\Sleep;

class Create extends BaseCommand
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'create
                            {server? : hostname or numeric server id to create backups for}
                            {--all : create backups for all servers in account}
                            {--include= : include only this list of servers}
                            {--exclude= : exclude this list of servers}
                            {--d|download : also download each backup created}
                            {--m|move : move downloaded files to secondary storage}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create new temporary backup on BinaryLane and optionally download locally';

    protected string $commandContext = 'create';

    protected bool $locks = true;

    /**
     * Seconds between checks on a backup that is still being taken.
     */
    protected const POLL_INTERVAL = 10;

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $hostnameOrServerId = $this->argument('server');
        $allServers = $this->option('all');

        if (!$allServers)
        {
            if (empty($hostnameOrServerId))
            {
                $this->fail("No hostname or server_id specified. Specify --all option to back up all servers");
            }

            $this->ignoreServerListOptions();

            if (is_numeric($hostnameOrServerId))
            {
                // $hostname is server_id - an id that is not on the account raises,
                // rather than answering empty
                $servers = [$this->binarylane->servers()->get((int) $hostnameOrServerId)];
            }
            else
            {
                // hostname is string
                $servers = $this->serversNamed($hostnameOrServerId);

                if (empty($servers))
                {
                    $this->fail("No server data returned for {$hostnameOrServerId}");
                }
            }
        }
        else
        {
            $servers = $this->allServers();

            if (empty($servers))
            {
                $this->fail("No server data returned");
            }

            $servers = $this->applyServerLists($servers);

            $this->log('notice', "Backing up all servers");
        }

        $failed = collect($servers)
            // reject rather than each, so one server that fails doesn't stop
            // the run and what is left is the servers that were not backed up
            ->reject(function (Server $server) {

                if (! $this->backup($server))
                {
                    return false;
                }

                if ($this->option('download'))
                {
                    return $this->call('download', [
                        'server' => $server->id,
                        '--move' => $this->option('move'),
                    ]) === self::SUCCESS;
                }

                return true;

            });

        if ($failed->isNotEmpty())
        {
            $this->log(
                'error',
                "{$failed->count()} server backup(s) did not complete",
                "Server backups did not complete",
                ['count' => $failed->count(), 'servers' => $failed->pluck('name')->all()]
            );

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    protected function backup(Server $server) : bool
    {
        $this->log(
            'notice',
            "Backing up {$server->disk}GB from {$server->name} to temporary backup image",
            "Backing up server",
            ['server_id' => $server->id, 'disk_size' => $server->disk, 'name' => $server->name]
        );

        $start = now();

        // into a temporary slot, replacing the oldest one when they are all taken -
        // what this tool has always asked for. A nightly run that refused when the
        // slots were full would stop backing the server up rather than rotate
        $action = $this->binarylane->serverActions()->takeBackup(
            $server->id,
            TakeBackup::replacingOldest(BackupSlot::Temporary)->withLabel('API initiated backup')
        );

        if ($action === null)
        {
            // a bodiless 202: accepted, with no action to follow. Reported rather
            // than assumed to have worked, because nothing here can find out
            // whether it did
            $this->log(
                'error',
                "BinaryLane accepted the backup of {$server->name} but returned no action to follow",
                "Backup accepted with no action to follow",
                ['server' => $server->name, 'server_id' => $server->id]
            );

            $this->summary->recordFailure($server->name, 'create', 'backup accepted with no action to follow');

            return false;
        }

        $progress = $this->progressBar();
        $progress->start();

        $timeout = (int) config('blbackup.timeout');

        // what the action ended as, in the words the log and the run summary have
        // always used - so a failure reads the same now that the client raises it
        // rather than handing back a status to compare
        $outcome = 'completed';

        // why, when the client said. It logs nothing above debug as of 0.3.0, so the
        // record written below is the only place the reason survives - an errored
        // action's own explanation, the invoice a blocked one is waiting on
        $reason = null;

        try
        {
            $this->binarylane->actions()->await(
                $action,
                timeout: $timeout,
                interval: self::POLL_INTERVAL,
                onPoll: function (Action $current) use ($progress) {
                    $progress->setProgress($current->progress?->percentComplete ?? 0);

                    // don't write this out to console, will break the progress bar - just log it
                    Log::debug("Backup progress", ['current_step_detail' => $current->progress?->currentStepDetail]);
                },
                // through Sleep rather than the client's own sleep(), so a test can
                // fake the wait instead of spending ten real seconds a poll
                wait: function (int $seconds) : void {
                    Sleep::for($seconds)->seconds();
                },
            );
        }
        catch (ActionTimedOutException $e)
        {
            // nothing was cancelled: the backup may still finish, it just did not
            // finish in time for this run to wait on it
            $this->newLine();
            $this->log(
                'warning',
                "Backup of {$server->name} exceeded timeout of {$timeout} seconds",
                "Backup exceeded timeout",
                ['server_id' => $server->id, 'name' => $server->name, 'timeout' => $timeout]
            );

            $outcome = 'in-progress';
        }
        catch (ActionFailedException $e)
        {
            $outcome = 'errored';
            $reason = $e->getMessage();
        }
        catch (ActionBlockedException $e)
        {
            // waiting on an answer or an unpaid invoice, and neither arrives by
            // waiting longer - so it is reported now rather than at the timeout
            $outcome = 'blocked';
            $reason = $e->getMessage();
        }
        catch (MalformedResponseException $e)
        {
            // a status the client cannot classify. The run stops waiting on it, as
            // it always has for a status that was not one it knew
            $outcome = 'unrecognised';
            $reason = $e->getMessage();
        }

        if ($outcome === 'completed')
        {
            $progress->finish();
            $this->newLine();

            $seconds = $start->diffInSeconds(now());
            $elapsed = CarbonInterval::seconds($seconds)->cascade()->forHumans();
            $secondsFormatted = Number::format($seconds, 1);

            $this->newLine();
            $this->log(
                'notice',
                "Completed server backup {$server->name} in {$elapsed}",
                "Completed server backup",
                ['server' => $server->name, 'server_id' => $server->id, 'seconds' => $secondsFormatted, 'elapsed' => $elapsed, 'disk_size' => $server->disk]
            );
            $this->newLine();

            $this->summary->recordBackup($server->name, $seconds);

            return true;
        }
        else
        {
            $this->log(
                'error',
                "Error backing up {$server->name} - status: {$outcome}",
                "Error backing up server",
                ['server' => $server->name, 'server_id' => $server->id, 'status' => $outcome, 'reason' => $reason, 'disk_size' => $server->disk]
            );

            $this->summary->recordFailure($server->name, 'create', "backup {$outcome}");

            return false;
        }
    }

    /**
     * Define the command's schedule.
     */
    public function schedule(Schedule $schedule): void
    {
        // $schedule->command(static::class)->everyMinute();
    }
}
