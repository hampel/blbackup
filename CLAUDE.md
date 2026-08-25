# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

`blbackup` is a BinaryLane VPS backup CLI built on Laravel Zero 12 (PHP 8.2+). It
drives the BinaryLane API to take temporary server snapshots, then shells out to
`wget`, `zstd` and `rclone` to download, verify, ship and expire the resulting
compressed disk images.

`README.md` is upstream Laravel Zero boilerplate and describes the framework, not
this app.

## Commands

```bash
php blbackup                     # default: summary list of all commands
php blbackup app:config          # resolved config (timeouts, binaries, remote, disks, logging)
php blbackup app:test --logs     # write one message at each level, then dump logging config
php blbackup app:test --download=<url>   # exercise the Http download path against a URL

php blbackup account             # BinaryLane account info — cheapest API token check
php blbackup servers [host|id] [--ids|--names]
php blbackup backups [host|id] [--ids] [--urls]

php blbackup create <host|id>|--all [--include=FILE] [--exclude=FILE] [-d|--download] [-m|--move]
php blbackup download <host|id>|--all|--image=ID [-f|--force] [--no-test] [--no-wget] [-m|--move]
php blbackup check <file>|--all [--dry-run]
php blbackup move  <file>|--all [--remote=REMOTE] [--dry-run]
php blbackup clean [--days=N] [--remote] [--dry-run]

php blbackup test                # Laravel Zero's Pest runner (same as vendor/bin/pest)
php vendor/bin/pest tests/Feature/SomeTest.php     # single file
php vendor/bin/pest --filter='some name'           # single test
php blbackup app:build blbackup  # compile a PHAR into builds/ (box.json)
```

`--include` / `--exclude` take a path to a plain-text file, one server hostname
per line, filtered against `$server['name']`. `clean` prompts for confirmation
unless `--dry-run` or `--no-interaction`.

## The pipeline

`create` → `download` → `check` → `move` → `clean` is one chain, and the commands
call each other through `$this->call()` rather than sharing code:

- `create --download` calls `download`, passing `--move` through.
- `download` calls `check` to validate the file (unless `--no-test`) and deletes
  the download if the zstd test fails; then calls `move` if `--move` is set.
- `clean --remote` expires the rclone side as well as the local disk.

So a scheduled full run is `create --all --download --move` followed by
`clean --remote --no-interaction`.

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
`app:test --download` duplicates that Http path inline rather than calling
`Api::download()`, so it can be pointed at an arbitrary URL.

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
`config/`. `.env` is gitignored and **there is no `.env.example`**; `app:config`
is the way to see what a given install resolved to.

**Never put an `env()` call in `config/app.php`.** `app:build` evaluates that file
on the build machine and compiles it in as a literal array, so the value freezes
at build time and no `.env` beside the binary can change it. That is why the
timezone lives as `binarylane.timezone` and is applied with
`date_default_timezone_set()` in `AppServiceProvider::boot()` rather than as
`app.timezone`. `bootstrap/app.php` likewise repoints the storage path at
`getcwd()` when running inside a Phar, so a compiled binary resolves `.env`, logs
and the default download path relative to the working directory.

**Logging is off by default** — `logging.default` is `null`. A real install sets
`LOG_CHANNEL`/`LOG_STACK` and `LOG_STORAGE_PATH`; `app:test --logs` is the check
that it took. `ContextLogProcessor` is bound explicitly in `AppServiceProvider`
because Laravel Zero does not register it, and without it `Log::withContext()`
data never reaches the records.

## Conventions

- The code uses Allman braces and its own spacing, which is **not** Laravel/PSR-12.
  Pint is in `require-dev` but there is no `pint.json`, so running it would
  reformat the entire codebase — don't run it across existing files.
- There are no Composer scripts; run Pest directly or via `php blbackup test`.
- `tests/` is Laravel Zero scaffolding with the stock `InspireCommandTest`
  removed (it drove an `inspire` command this app doesn't have), leaving
  `tests/Unit/ExampleTest.php` and a `.gitkeep` holding `tests/Feature` open —
  `phpunit.xml.dist` names both directories and Pest exits 2 if either is
  missing. There is no real coverage of this app's commands.

## Releasing

`config/app.php` has `'version' => app('git.version')`, and `app:build` compiles
that file's evaluated result in as a literal — so **tag first, then build**, or
the binary ships announcing the previous release. Then
`php blbackup app:build blbackup` → `builds/blbackup`, and confirm with
`./builds/blbackup --version`.
