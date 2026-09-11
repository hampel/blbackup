# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

`blbackup` is a BinaryLane VPS backup CLI built on Laravel Zero 13 (PHP 8.3+). It
drives the BinaryLane API to take temporary server snapshots, then shells out to
`wget`, `zstd` and `rclone` to download, verify, ship and expire the resulting
compressed disk images.

`README.md` is upstream Laravel Zero boilerplate and describes the framework, not
this app.

## Commands

```bash
php blbackup                     # default: summary list of all commands
php blbackup app:config          # resolved config (timeouts, binaries, remote, disks, logging)
php blbackup app:validate        # run the binaries, list the remote, call the API, write logs at every level
php blbackup app:validate --download=<url>  # also pull a real URL through the download path

php blbackup cron                # the whole run, for a single crontab line
php blbackup account             # BinaryLane account info — cheapest API token check
php blbackup servers [host|id] [--ids|--names]
php blbackup backups [host|id] [--ids] [--urls]

php blbackup create <host|id>|--all [--include=FILE] [--exclude=FILE] [-d|--download] [-m|--move]
php blbackup download <host|id>|--all|--image=ID [-f|--force] [--no-test] [--no-wget] [-m|--move]
php blbackup check <file>|--all [--dry-run]
php blbackup move  <file>|--all [--remote=REMOTE] [--dry-run]
php blbackup clean [--days=N] [--remote] [--dry-run]
php blbackup cron  [--include=FILE] [--exclude=FILE] [--no-move] [--no-clean]

php blbackup test                # Laravel Zero's Pest runner (same as vendor/bin/pest)
php vendor/bin/pest tests/Feature/SomeTest.php     # single file
php vendor/bin/pest --filter='some name'           # single test
php blbackup app:build blbackup  # compile a PHAR into builds/ (box.json)
```

`--include` / `--exclude` take a path to a plain-text file, one server hostname
per line, filtered against `$server['name']`, and default to
`binarylane.include_file` / `binarylane.exclude_file` when the option is absent.
`clean` prompts for confirmation unless `--dry-run` or `--no-interaction`.

## The pipeline

`create` → `download` → `check` → `move` → `clean` is one chain, and the commands
call each other through `$this->call()` rather than sharing code:

- `create --download` calls `download`, passing `--move` through.
- `download` calls `check` to validate the file (unless `--no-test`) and deletes
  the download if the zstd test fails; then calls `move` if `--move` is set.
- `clean --remote` expires the rclone side as well as the local disk.

**`cron` is the entry point for an unattended run**, and the only command that
posts a run summary. It runs `create --all --download` and then `clean`, and
decides two things from configuration rather than from flags:

- **`--move` is gated on `RCLONE_REMOTE` being set.** An installation that can
  run on the machine holding the backups has nowhere to move them to, and
  unsetting one variable is the whole of that change. `--no-move` forces
  download-only for one run.
- **`clean --remote` is gated on the same variable**, but not on `--move`:
  what previous nights shipped there still ages, whatever tonight did.

It never prompts — `clean` is called with `--no-interaction`, because a
`confirm()` with no stdin to read is an exception rather than a default. There is
deliberately no `--dry-run`: `check`, `move` and `clean` have one but `create`
and `download` do not, so it would really take a snapshot and really pull it
down. A stage that fails does not stop the ones after it, and any failure makes
the whole run exit non-zero.

## Architecture

**`App\Api` is the only thing that talks to BinaryLane.** Every method wraps a
`Http::binarylane()` call (a macro registered in `AppServiceProvider` binding the
token and `https://api.binarylane.com.au/v2`) and converts a `RequestException`
into `App\Exceptions\BinaryLaneException`, whose message carries the HTTP status
and reason. `_ide_helper.php` exists solely to declare that macro.

**`BaseCommand::execute()` catches `BinaryLaneException` and `ConnectionException`**
for the whole command, logs and prints them, and returns FAILURE — so command
code calls `$this->api->…` straight, with no try/catch. Subclasses set
`protected string $commandContext`, which is pushed into `Log::withContext()` so
every record from a run is tagged with the command.

**`app:validate` is the command to extend when a new dependency on the
environment appears.** It exercises rather than describes: it runs each
configured binary, lists the rclone remote, calls the API, write-probes the
download directory and writes a real log record. Four outcomes — `[ ok ]`,
`[warn]`, `[fail]`, and a blank marker for a check that did not apply, which is
deliberately not a pass — and a non-zero exit if anything failed, so an image
rebuild can be gated on it. **Do not report anything with `$this->components->twoColumnDetail()`.** Its
`EnsureRelativePaths` mutator strips `base_path().'/'` out of every value and
cannot be opted out of, so absolute paths print as convincing relative ones —
`app:config` reported the storage path as `storage` for exactly this reason.
`hampel/console-report` is why that is fixed: `ReportsSettings` + `FormatsValues`
draw the settings dump and `RendersChecks` draws the `[ ok ]` / `[warn]` /
`[fail]` rows and owns the exit code. The package imports no Illuminate symbol,
so it has to be handed somewhere to write — `setReportOutput($this->getOutput())`
at the top of `handle()`, which both commands do; forget it in a third and the
first render throws a `LogicException` naming the missing call.

**Two heading styles, on purpose.** `app:validate` heads its groups of checks
with the package's `checkSection()` — a margined green title that lines up with
the `[ ok ]` markers beneath it. `cron` heads each stage with
`BaseCommand::section()`, which is cyan, ruled, and has air either side. That is
not an oversight to be tidied away: a ruled heading separates stages of work in a
scrolling run log, which is what gets scanned when a backup has gone wrong
overnight, and `checkSection()`'s single blank line would be a downgrade there.
`section()` stays on `BaseCommand` for that one caller. Putting `RendersChecks`
on `BaseCommand` to unify them would land `checkOk()`, `checksFailed()`,
`checkExitCode()` and the rest on all ten commands, nine of which check nothing.
Note `section()` measures its rule with `strlen()`, so a multibyte title
underlines short — `cron` passes a stage name rather than a literal, so keep the
stage names ASCII.

Credentials go through `secretStatus()`, never printed: a settings dump is what
gets pasted into a ticket, and a working token pasted anywhere is a working
token. Paths go through `path()`, which reports a relative one along with what it
resolves against — under cron that is wherever the crontab last changed to.

Two things it has to defend against, both of which bit while it was written.
Reporting a failure writes to the log, so validating an unwritable log
destination died inside Monolog until every write from this command was guarded
and the channel dropped once known bad — `Log::forgetChannels()` cannot do that,
because closing a stream handler opens the stream that could not be opened.
And that exposure is not this command's alone: `App\Api` logs every call, so an
unwritable log path takes any command down on its first API call.

**A mistyped command must exit non-zero, and that took an override.**
`App\Kernel` narrows `LaravelZero\Framework\Kernel::ensureDefaultCommand()` so
only a bare invocation or an options-only one is proxied to the default command;
a first argument naming nothing reaches Symfony and fails. Stock behaviour
proxies it, so `blbackup app:validte` printed the command list and exited 0 —
the same silent success as the scheduler, in a tool whose exit code is the whole
of what cron reads, and it would have made `app:validate` pass while checking
nothing. It has to be rebound in `bootstrap/app.php` over the binding
`Application::configure()` makes, or the class sits there doing nothing.
`tests/Feature/UnknownCommandTest.php` drives `Kernel::handle()` directly,
because `$this->artisan()` calls the command and never passes through the
proxying — and it marks the input non-interactive, since Symfony's "Did you mean
this?" prompt otherwise waits on stdin and hangs the suite.

**One lock covers every command that writes**, so a run that overruns holds the
next one off rather than running over the top of it — a multi-gigabyte image on
a slow link is exactly the case that overruns. `App\Support\BackupLock` is a
container singleton holding an `flock`, taken in `BaseCommand::execute()` by any
command with `$locks = true` (`cron`, `create`, `download`, `move`, `clean` —
the listing commands and `check` only read). The kernel releases an `flock` when
the process ends however it ends, so a killed run leaves no stale lock to break
by hand.

It has to be a singleton: `flock` is associated with the open file description
rather than the process, so `cron` opening the file and then `create` opening it
again would deadlock the run against itself. Nested stages see `isHeld()` and
leave it alone, both to take and to release. A dry run skips the lock entirely —
asking what `clean --dry-run` would delete while a backup runs is the point of
the flag.

**In a container `LOCK_FILE` must name a path on a shared mount.** Each
`docker compose run` gets its own filesystem, so the default under the storage
path is a lock two concurrent runs cannot see each other holding — it does
nothing, and nothing says so. `app:validate` takes the lock and prints the path
it used for exactly that reason.

**The run summary is a notification, not a log record.** `App\Support\RunSummary`
is a container singleton — for the same reason the stages need one, since
`create` calls `download` and `download` calls `move`, and the thing being
summarised is the run rather than any one command. `$summarises` is true on `cron`
alone: every stage can be run by hand, and a summary posted for a command
somebody is sitting and watching is noise delivered to the channel of the person
watching it. Posting therefore belongs to the unattended entry point rather than
to a guess about whether anyone is there — and Symfony is no help with that
guess anyway, since `isInteractive()` is only cleared by `--no-interaction` or
`--quiet` and so reports true under cron. `BaseCommand` claims the run, records
what each stage produced and each failure, and posts once at the end.
`recordFailedStart()` records against whoever owns the run, not only the owner
itself: `cron` owns it and the stage that could not start is the one with
something to say. `App\Support\SlackSummary` renders it
and sends it with `hampel/slack-message`, which needs only a PSR-18 client —
Guzzle is already a `laravel-zero/framework` dependency, so it costs one package
rather than the 25 `illuminate/notifications` would.

Sending happens in a `finally` and can never fail the run: whether Slack heard
about the work does not change whether the work succeeded. A failed send logs a
warning and leaves the exit code alone.

**A run the operator called off does not report.** `clean` is the only command
that asks before it acts, and answering no used to post `Backup completed` with
no counts to the channel of the person who had just cancelled it.
`RunSummary::cancel()` marks that, and `SlackSummary::shouldSend()` declines —
it is neither a failure nor a block, because nothing went wrong and nothing was
missing. The gate on the prompt is `$this->input->isInteractive()`, not the
`--no-interaction` flag: that is the state `confirm()` itself obeys, `--quiet`
clears it too, and when it is set but stdin cannot be read Symfony throws rather
than silently taking the default.

**`SlackSummary` reads no config and resolves nothing.** Webhook, notify policy,
application string and hostname all arrive through its constructor, and
`AppServiceProvider` does the reading. Keep it that way — if a new setting is
needed in a message, add a constructor argument and bind it, don't reach for
`config()` inside the class. The `slack` log channel stays as the backstop; the
two are complementary, and `config/binarylane.php` says why: a log channel posts
a record at a time, so a night where everything worked produces nothing at all.

Tests fake at the HTTP client (`MockHandler` + `Middleware::history()`) and
assert on the decoded request body, because the payload is what the code
produces — mocking the sender would test nothing.

**Exit codes are the contract with cron**, and every command that works through
a list uses the same shape: `reject()` rather than `each()`, so one failure
doesn't stop the run and what is left is what failed, then a count logged and
FAILURE returned at the end. `create` reports servers whose backup errored or
timed out; `download` reports the servers whose backup is not in place;
`check`, `move` and `clean` report the files they could not handle. The chain
propagates too: `download` returns the exit code of the `move` it calls, and
`create --download` returns the exit code of the `download`. Copy that shape for
anything new — a `return` inside an `each()` closure returns from the closure,
not the command, which is how `clean`'s remote deletions used to fail silently.

**A skip is not a failure**, and `download` is where that distinction lives.
`downloadImage()` returns whether the backup is *in place afterwards* — true
when it downloaded one and true when one was already there, local or on the
remote; false only when something went wrong. Return false for the
already-downloaded case and a re-run of `download --all` reports trouble every
night, which is the failure mode that makes an exit code worth nothing.

**`BaseCommand::serverList('include'|'exclude')`** resolves the server lists for
`create` and `download`, which had the same twenty lines twice. The option wins
for one run; `binarylane.include_file` / `binarylane.exclude_file` is what an
unattended install is read out of, and exists so that `app:config` can show the
list and `app:validate` can check it — a path that lives only on the crontab line
is unverifiable, and the way it fails is the worst kind: the run completes, the
summary posts, and the servers you thought were covered are not.

Two decisions inside it worth not undoing. **A missing file fails the command**
rather than being ignored, because a filter that silently did not apply looks
exactly like a normal night. **A file that names nothing is treated as no list at
all**, so an empty include list backs up everything rather than nothing — the
safe way round, since a truncated list must not stop the backups. That second one
is genuinely surprising, which is why `app:validate` warns about it; nothing else
on the machine would say a word. Lines are trimmed, because these files get
edited on Windows and a CRLF list matches no hostname at all.

**`log($level, $message, $logMessage = null, $context = [])`** dual-writes: to
Monolog (structured, with `$context`) and to the console (styled, gated by a
level→verbosity map, so `debug` only appears under `-vvv`). Use it rather than
`$this->info()` / `Log::info()` for anything worth recording — the console string
and the log string are deliberately different, the log one being the stable
message with the variable parts moved into context.

`logCmd($description, $cmd)` debug-logs each shell command before it runs; every
external binary invocation goes through it. `getVerbosity()` translates the
command's own `-v`/`-q` into a flag string to splice into the rclone command line.

**Long-running processes use `Process::forever()`** — `wget`, `zstd --test` and
`rclone moveto` all operate on multi-GB files and will exceed any default
timeout. Short probes (`rclone lsjson`, `rclone lsd`) use plain `Process::path()`.

**Progress bars are parsed out of subprocess output.** `Download::processWget()`
scans wget's stderr for its percentage lines and drives a Symfony `ProgressBar`;
`Move::processRclone()` uses `deleteLines()` from `BaseCommand` to redraw
rclone's `--progress` block in place. Both are brittle against output-format
changes in those tools — that is the cost of the live display. Nothing may be
written to the console inside these callbacks except through the progress bar, or
it breaks the redraw (see the `Log::debug` in `Create::backup()` for the pattern).

**Downloads default to `wget`, not the Http client.** `--no-wget` switches to
`Api::download()` (Guzzle sink + progress callback), the original implementation.
`app:validate --download=<url>` exercises that same
`Api::download()` path against an arbitrary URL.

**Filenames encode the source.** Downloads land at
`<download disk>/<server name>/backup-<short name>-<Ymd-His>-<image id>.zst`,
where the datestamp is the image's `created_at` converted to
`binarylane.timezone` and the short name is the first dot-separated label of the
hostname. `move` preserves that relative path under the rclone remote, and both
`download`'s already-exists check and `clean`'s expiry rely on it.

**Completion is judged by size**, comparing the file's bytes-as-GB against the
API's `size_gigabytes` for exact equality. A short file is reported as a probable
incomplete download and left alone; `--force` is what re-downloads it. With
`--move`, the same check runs against `rclone lsjson --stat` before downloading,
so an image already shipped to the remote is not fetched again.

**Two filesystem disks** (`config/filesystems.php`): `local` at `storage_path()`,
and `downloads` at `DOWNLOAD_PATH` (default `storage_path('backups')`). All
backup file handling goes through the `downloads` disk, and shell commands are
built from `Storage::disk('downloads')->path(…)`.

## Configuration and packaging

Everything app-specific is env-driven through `config/binarylane.php` — API
token, download timeout, the three binary paths, `keeponly_days`, the rclone
remote, and the timezone. Read it through `config()`, never `env()` outside
`config/`. `.env.example` documents every variable with its default; `.env`
itself is gitignored, and `app:config` is the way to see what a given install
resolved to.

**The version has three sources, and which one answers depends on how it is
running.** `config/app.php` holds `app('git.version')`, which shells out to
`git describe --tags --abbrev=0`: a checkout answers from its tags, and
`app:build` compiles the evaluated result into a phar as a literal, so a binary
knows the tag it came from. A **container has neither a `.git` directory nor a
git binary**, so it falls back to the string `unreleased` — and that is not
cosmetic, because `AppServiceProvider` signs every Slack run summary with
`app()->version()`, so the alerts from that install are signed `unreleased` too.
`BLBACKUP_VERSION` is how the image is told, taken from a `VERSION` build
argument in the `Dockerfile`, and `AppServiceProvider::register()` applies it
over `app.version` — config is already loaded by the time providers register.
It is applied there rather than read in `config/app.php` because of the rule
below. Forgetting the build argument is not silent: `app:validate` warns, and it
is the gate a rebuild has to pass anyway.

**Never put an `env()` call in `config/app.php`.** `app:build` evaluates that file
on the build machine and compiles it in as a literal array, so the value freezes
at build time and no `.env` beside the binary can change it. That is why the
timezone lives as `binarylane.timezone` and is applied with
`date_default_timezone_set()` in `AppServiceProvider::boot()` rather than as
`app.timezone`. `bootstrap/app.php` likewise repoints the storage path at
`getcwd()` when running inside a Phar, so a compiled binary resolves logs and the
default download path relative to the working directory.

**But not `.env`, and the difference is measured rather than assumed.** A
compiled binary reads `.env` from the directory holding the binary — its base
path is inside the Phar, and Laravel Zero points it at the Phar's own directory
— while `useStoragePath(getcwd())` sends everything else to the working
directory. Verified by running one binary from two directories: the `.env` beside
it won over the one in `cwd`, and the storage path followed `cwd` regardless.
Under cron that splits: the binary reads its settings from where it lives and
writes its logs to wherever the crontab last changed to, which is why
`LARAVEL_STORAGE_PATH` exists and why `app:config` reports what each path
resolved against.

**Logging still resolves to nothing until `LOG_STACK` is set.** `logging.default`
is `stack`, but the stack's own channel list defaults to `null`, which discards
everything — so an install that sets neither is silent, and `app:validate` warns
about exactly that. A real install sets
`LOG_CHANNEL`/`LOG_STACK` and `LOG_STORAGE_PATH`; `app:validate` is the check
that it took — it writes a real record at every level rather than reporting that
the file looks writable.

**`app:validate` posts to Slack**, if a webhook is configured. That is
deliberate and is why the levels are not behind a flag by default: a destination
with a threshold only proves it works when something at that level is really
sent, and a revoked webhook is invisible from the sending end — the alert simply
never arrives, which looks exactly like a run where nothing went wrong. Say so
before anyone runs it on a machine whose Slack channel other people watch.

**It sends two things, not one, and the loud one hides the quiet one.** The
eight-level sweep is what anybody notices; `checkSummary()` posts a test message
to a *different* webhook — `BLBACKUP_SUMMARY_SLACK_WEBHOOK` rather than the log
channel — and qualifies on exactly the same test. Count the sends before
touching anything that gates them; do not reason outward from the one you
noticed.

**`--unattended` suppresses exactly those two and nothing else**, and states a
condition rather than a preference: the network is fine, but nobody is watching
where these land. Three things follow that are worth not undoing:

- **It is never the default.** Forgetting it costs channel noise somebody can
  delete; defaulting it on would cost every future run its proof of delivery,
  silently, which is not recoverable by noticing.
- **Both suppressed checks report as skips**, never as omissions — a check that
  quietly did not run is how a check that does nothing goes unnoticed.
- **A warning or a failure is still logged**, even though those records reach
  the same channel. That half of the output is written for whoever is *not* at
  the terminal, which is precisely who `--unattended` says is running it.
- **The slack threshold check still reports**, for the same reason turned
  around: it is a static fact about the configuration rather than something the
  sweep discovers, so the run that posts nothing is exactly the run that should
  still surface it. See the ordering rule below — intending that is not the same
  as getting it.

**Three flags, and they are not interchangeable.** Each states a different
condition, and the wrong one either throws away a check or hangs on a call that
was never going to answer:

| flag | the condition it states | what it suppresses |
|---|---|---|
| `--offline` | there is no outbound network, or you are not spending it | everything that leaves the machine |
| `--unattended` | the network is fine, nobody is watching the destination | only the two sends |
| `--no-api` | the API calls are not worth their cost on this run | the account and server calls, and nothing else |

**`--offline` implies `--unattended`, and not the reverse.** That asymmetry is
the whole reason there is more than one: an unattended run still wants its
outbound probes to fail loudly — a backup remote that stopped answering is
exactly what an unwatched rebuild gate exists to surface — while wanting nothing
posted into a channel nobody asked to read. `--offline` additionally skips the
rclone remote probe, skips the API calls, and refuses `--download` rather than
silently ignoring it.

**`--no-api` was not renamed into `--offline`, and it is not deprecated.** The
fleet settled `--offline` as the house name, and the obvious tidy-up is to make
this tool's older, narrower flag mean it. It cannot: `--no-api` covers one of
the four things here that reach outside, so renaming it would be a silent
widening under a name people already script rather than a rename. It also has a
permanent user that `--offline` cannot serve — the CI job runs `app:validate`
inside the freshly built image with no credentials, and needs the API off while
needing the level sweep genuinely written, which is most of the point of running
the command in there at all. `--offline` cannot give it that, because it must
assume any log channel might post: `driver => monolog` could be papertrail, so
"is this stack local?" is not answerable from configuration, and the safe answer
is to send nothing.

**An attended run has to say what it sent**, because the operator cannot check
what they were not told to expect. `checkDelivery()` prints the count and the
levels — `posted 4 records at error and above` — derived from the effective
stack and that channel's threshold rather than written down, so the line cannot
drift from the configuration it describes. Note the driver test in there says
*what to look for* and is not how to bound the sweep: `papertrail` is
`driver => monolog` and leaves the machine just as surely, so gating the loop on
the driver would send all eight off the box rather than none.

**A slack channel can be configured so that it cannot fire, and that is the
failure worth catching.** `LOG_SLACK_LEVEL` used to default to Laravel's stock
`critical`, and **nothing in `app/` logs above `error`** — so out of the box the
channel accepted a valid webhook, passed every check, posted `app:validate`'s own
sweep, and then stayed silent on the night it was installed for. A channel that
cannot fire is indistinguishable from one with nothing to say, which is the whole
reason it was configured. The default is now `error`, and `checkThreshold()`
warns when the threshold is above `AppValidate::HIGHEST_LOGGED_LEVEL`.

Two things about that constant. It **warns rather than fails**, because this
command's exit code gates a container rebuild and an install that set the
threshold high on purpose should not thereby be unable to rebuild. And it is **a
claim about the whole of `app/`, not a setting** — it goes stale the moment
somebody adds a `critical` call, and nothing else would notice, so
`tests/Feature/LogLevelTest.php` scans the token stream for anything above it.
Changing the default only ever helps an install that never set the variable; the
warning is the half that reaches the ones that did.

**A check that survives a flag has to be ordered against it, not merely written
to survive it.** `checkThreshold()` is meant to report under `--unattended` and
`--offline`, and did not: the flag's early return sat above the line that
resolved the threshold, so the warning was unreachable on exactly the runs that
most need it. The rule, and it generalises past this command:

> When a flag suppresses part of a check, everything that must outlive the flag
> goes **above** the early return. A suppression flag is a `continue`, and
> anything below it is suppressed too, whatever the docblock says.

Worth stating because it is the same failure as the one that started this —
something that reads as configured and cannot fire — and because two separate
implementations of this pattern made the same mistake, neither getting the order
right first time. A mutation that moves the call back below the flag check fails a
test, so it cannot drift back.

**The delivery count includes the run summary when the two share a webhook.**
`BLBACKUP_SUMMARY_SLACK_WEBHOOK` and `LOG_SLACK_WEBHOOK_URL` are separate
settings and usually separate channels, but pointing both at one is the obvious
thing to do — and then one more message arrives than the sweep sent, which makes
a correct count look wrong and trains the operator to ignore the line.

`config/logging.php` stamps every record with `logging.hostname` through the
`StampHostname` tap, so one webhook can serve more than one installation. It has
to be a tap: the `processors` key in a channel's config is only read by the
`monolog` driver, so `single`, `daily` and `slack` ignore it. Setting
`LOG_HOSTNAME` matters more here than on a normal box — a container calls itself
a hex string that changes every time the image is rebuilt. `ContextLogProcessor` is bound explicitly in `AppServiceProvider`
because Laravel Zero does not register it, and without it `Log::withContext()`
data never reaches the records.

## Testing

`tests/Feature/` covers every command — `create`, `download`, `check`, `move`,
`clean`, and the read-only `servers`, `backups` and `account`. The commands' product is the shell command they assemble, the API call they make and
the file that results, so that is what is asserted: `Process::assertRan()`
against the exact wget/zstd/rclone command string, `Http::assertSent()` against
the request, and the state of the `downloads` disk afterwards.

`tests/Pest.php` pins every binary path, the remote, the timezone and the API
token in a `beforeEach` chained onto `uses()`, so assertions don't depend on the
developer's `.env` — it also forces `logging.default` to `null`, since the
project `.env` is loaded during tests and the suite would otherwise append to
whatever log the developer has configured. `Storage::fake('downloads')` repoints
the download disk into `storage/framework/testing`. Helpers: `fakeServer()` /
`fakeImage()` build API payloads, `fakeApi()` answers every BinaryLane endpoint
by routing on the request path, `fakeBinaries()` fakes the external commands with
a fall-through to success, `wgetWrites()` is a wget fake that writes the file
wget would have written, `writeServerList()` writes an `--include` / `--exclude`
list, `putAgedDownload()` writes a backup with a modification time for `clean`
to expire, `rcloneEntry()` / `rcloneListing()` build `rclone lsjson` output,
`renderedTable()` parses a printed table back into rows for an exact
comparison, and `backupPath()` gives the path the command derives for the
standard fixture. `fakeApi()`'s `$statuses` argument is the queue of action payloads the
`create` poll loop reads, and an entry may be a closure — which is how a test
makes something happen between one poll and the next.

The suite has been audited by mutation: change one behaviour in `app/`, run the
suite, and a test should fail. 49 mutations were tried across the commands, the
API client and the service provider; the gaps that found are now covered
(`download`'s `--include` / `--exclude`, `backups --ids` filtering and its
per-server form, the `Http::binarylane()` token and base url, the timezone
default, and the deliberate working directory each external process runs in).
Do the same for anything substantial you add — a passing test proves nothing
until you have seen it fail. Two mutations survive on purpose and are not worth
chasing: deleting `create`'s `errored` status check changes nothing, because the
`!== 'in-progress'` check below it catches the same case, and the download
timeout cannot be observed through `Http::fake()`, which never times out.

Twelve things that will catch you out:

- **Anything the suite does not pin, it inherits — and two of those left the
  machine.** `tests/Pest.php` pins the binaries, the remote, the timezone, the
  token and the log channel because the project `.env` is loaded during tests.
  The pins that were missing were the two that sent: a full run posted 41 real
  messages to `BLBACKUP_SUMMARY_SLACK_WEBHOOK`, because `SlackSummary` is a
  container singleton built from config with a **real Guzzle client** that
  `Http::fake()` cannot see; and the level sweep posted through Monolog's slack
  handler, which `Http::fake()` cannot see either. When adding anything that
  sends, pin it here and write the test that fails if somebody unpins it —
  `recordingSlack()` is there for that. **And point every webhook fixture at
  `hooks.slack.test`**, never the real host: the Slack log channel has no seam,
  so `Log::spy()` is the only thing keeping a test off the network, and a test
  that forgets it against the real host posts silently, because a 404 fails
  nothing. Against a name that cannot resolve, Monolog throws and the test goes
  red. `WebhookFixturesTest` scans the whole tests tree for the real host.
- **A literal scan cannot see through a variable**, so `LogLevelTest` pins the
  dynamic call sites separately. Three calls here pass a `$level` through rather
  than naming one — `BaseCommand::log()`, the sweep, and `AppValidate::record()`
  — and all three hand on what they were given. A fourth would be a call site the
  ceiling assertion is blind to, so the test names the three by enclosing
  function and fails either way: on a new one, and on one of these disappearing.
- **`tests/Feature/LogLevelTest.php` guards a claim, not a behaviour.**
  `AppValidate::HIGHEST_LOGGED_LEVEL` asserts something about every other file
  in `app/`, so the test walks the token stream rather than grepping. Two things
  it gets right that a regex does not: the level usually sits on the **line
  after** `$this->log(` — there are 40 such call sites, and a per-line pattern
  finds almost none of them — and `$this->alert('…')` is Laravel's console
  banner, not a log call, so only `Log::<level>()` counts as a static call. It
  also asserts the scanner found something, because a scan that silently matches
  nothing passes forever.
- **`app.version` is pinned for the same reason, and the reason is `git`.**
  `config/app.php` resolves it by shelling out to `git describe --tags`, so
  without a pin every test inherits whatever the ambient checkout can answer. A
  clone with no tags answers `unreleased`, `app:validate` warns about it, and a
  test asserting a clean run fails somewhere with nothing to do with versions.
  That is not hypothetical: **CI's branch builds were red from 2.2.0 to 2.3.0**
  and nobody noticed, because `actions/checkout` fetches the tag on a tag push
  and no tags on a branch push — so the tag run, which is the one being watched
  at release time, passed every time. **Look at the branch run too.**
- **`beforeEach()` in `tests/Pest.php` must be chained onto `uses()`** —
  `beforeEach(...)->in('Feature')` on its own parses fine and silently never
  runs, so the tests execute against the developer's real config.
- **`Http::fake()` merges stubs, and the first registered match wins.** A fake
  in `beforeEach` therefore shadows a different one set inside a test. Each test
  here registers its own via `fakeOneBackup()`.
- **`Http::fake()` honours the `sink` option**, so the `--no-wget` path really
  writes the faked body to disk. That is why the fake `.zst` response body is
  exactly `MEGABYTE` bytes.
- **Don't use `expectsTable()` — it cannot see extra rows.** It renders the rows
  you give it and asserts each resulting line appears in the output, so a
  command printing rows the test never mentioned still passes. Deleting
  `backups`' public / non-backup filter passed its table assertion; so did
  injecting a bogus row into all three listing commands. Use
  `renderedTable(Artisan::output())` and compare the whole table with `toBe()`,
  which catches extra rows, missing rows and wrong order alike. The servers
  tests appeared to catch the bogus row only because the name chosen was long
  enough to change the column widths — a shorter one went through.
- **`expectsOutputToContain()` consumes one expectation per line**, so two of
  them cannot both match the same line. Two values on one table row need a
  single `expectsTable()` row instead — which is why the timezone test asserts a
  row rather than two timestamps.
- **`Sleep::fake()` is useless against `create`'s poll loop, and dangerous.**
  A faked sleep returns before the `while` loop runs, so the poll callback never
  executes and `$status` stays null. Sleep is therefore real in
  `CreateCommandTest`, which is only affordable because a poll that ends the loop
  never sleeps — the callback runs before the first sleep. Every test there has
  to reach a stopping condition on its first poll, or it costs ten seconds a
  poll. The timeout test gets there by having the API fake move the clock, since
  the elapsed-time check cannot otherwise trip.
- **`rclone lsjson` emits RFC3339 with nanosecond precision** — and `Z` rather
  than an offset on some backends. `rcloneEntry()` mirrors what the rclone on
  this machine really prints, which is the point: a fixture in a tidier format
  would have passed against a parse that crashes on live output, which is
  exactly the bug these tests found.
- **A download is accepted only if its size in GB exactly equals the API's
  `size_gigabytes`**, so fixture sizes have to be exact in both units. Hence
  `MEGABYTE` / `MEGABYTE_IN_GB` (0.0009765625) rather than a round decimal.

Faking any of this requires the process helpers to be typed against
`Illuminate\Contracts\Process\ProcessResult`, not the concrete
`Illuminate\Process\ProcessResult` — `Process::run()` returns a
`FakeProcessResult` under a fake, which implements the contract but does not
extend the class. Keep new helpers on the contract.

## Conventions

- The code uses Allman braces and its own spacing, which is **not** Laravel/PSR-12.
  Pint is in `require-dev` but there is no `pint.json`, so running it would
  reformat the entire codebase — don't run it across existing files.
- There are no Composer scripts; run Pest directly or via `php blbackup test`.
- `phpunit.xml.dist` names both `tests/Unit` and `tests/Feature` as testsuites,
  and Pest exits 2 if either directory is missing — don't leave one empty.

## Releasing

`config/app.php` has `'version' => app('git.version')`, and `app:build` compiles
that file's evaluated result in as a literal — so **tag first, then build**, or
the binary ships announcing the previous release. Then
`php blbackup app:build blbackup` → `builds/blbackup`, and confirm with
`./builds/blbackup --version`.
