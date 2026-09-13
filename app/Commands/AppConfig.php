<?php

namespace App\Commands;

use App\Support\BackupLock;
use Hampel\BinaryLane\Api\Laravel\BinaryLaneManager;
use Hampel\ConsoleReport\FormatsValues;
use Hampel\ConsoleReport\ReportsSettings;
use LaravelZero\Framework\Commands\Command;

/**
 * What this installation is configured to do
 *
 * Exhaustive on purpose: the value of a settings dump is that a setting missing
 * from it reads as a setting the tool does not have.
 *
 * Rendered by hampel/console-report rather than $this->components->twoColumnDetail(),
 * whose EnsureRelativePaths mutator strips base_path() out of every value with no
 * way to opt out - so absolute paths printed as convincing relative ones, and this
 * command reports little else. Credentials report as set or not set: this output is
 * what gets pasted into a support ticket.
 */
class AppConfig extends Command
{
    use FormatsValues;
    use ReportsSettings;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:config {--only= : The section to display}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Show application configuration';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        // the package imports no Illuminate symbol, so it has to be handed
        // somewhere to write before it renders anything
        $this->setReportOutput($this->getOutput());

        $this->reportSettings([
            'Application' => [
                'Name' => config('app.name'),
                'Version' => $this->app->version(),
                'Laravel Version' => $this->app::VERSION,
                'PHP Version' => phpversion(),
                'Environment' => $this->laravel->environment(),
                'Timezone' => config('blbackup.timezone'),
            ],

            'BinaryLane' => [
                // the default account's token, which is the one the client sends
                'API Token' => $this->secretStatus(config('binarylane.accounts.' . app(BinaryLaneManager::class)->getDefaultAccount() . '.token')),
                'API Request Timeout' => (string) config('binarylane.timeout'),
                'Download Timeout' => (string) config('blbackup.timeout'),
                'Keep Only Days' => (string) config('blbackup.keeponly_days'),
                'Include File' => $this->optionalPath(config('blbackup.include_file')),
                'Exclude File' => $this->optionalPath(config('blbackup.exclude_file')),
                'Lock File' => $this->path(app(BackupLock::class)->path()),
                'zstd Binary' => $this->path(config('blbackup.zstd_binary')),
                'wget Binary' => $this->path(config('blbackup.wget_binary')),
                'rclone Binary' => $this->path(config('blbackup.rclone.binary')),
                'rclone Remote' => $this->required(config('blbackup.rclone.remote')),
            ],

            'Filesystems' => [
                'Default' => config('filesystems.default'),
                'Storage Path' => $this->path(storage_path()),
                'Downloads Disk' => $this->path(config('filesystems.disks.downloads.root')),
            ],

            'Logging' => [
                'Default' => config('logging.default'),
                'Stack Channels' => implode(',', config('logging.channels.stack.channels')),
                'Hostname' => $this->required(config('logging.hostname')),
                'Single Path' => $this->path(config('logging.channels.single.path')),
                'Single Level' => config('logging.channels.single.level'),
                'Daily Path' => $this->path(config('logging.channels.daily.path')),
                'Daily Level' => config('logging.channels.daily.level'),
                'Daily Days' => (string) config('logging.channels.daily.days'),
                'Slack Webhook' => $this->secretStatus(config('logging.channels.slack.url')),
                'Slack Username' => $this->optional(config('logging.channels.slack.username')),
                'Slack Level' => config('logging.channels.slack.level'),
            ],

            'Run summary' => [
                'Slack Webhook' => $this->secretStatus(config('blbackup.summary.slack_webhook')),
                'Notify' => config('blbackup.summary.notify'),
            ],
        ], $this->option('only'));

        return Command::SUCCESS;
    }

    /**
     * A path that is empty in the ordinary case, so an empty one is not a fault.
     *
     * path() reports an unset value as "not set" in warning yellow, which is
     * right for a setting the tool needs and wrong for the server lists - most
     * installations back up everything and set neither.
     */
    protected function optionalPath(?string $value) : string
    {
        return $value === null || $value === '' ? $this->optional($value) : $this->path($value);
    }
}
