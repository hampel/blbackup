# Changelog

Notable changes to blbackup. Versions before 2.0.0 are recorded in the git
history only.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and
this project uses [semantic versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Fixed

- **The container had no `/app/storage`, and every rclone call runs from
  there.** `.dockerignore` excludes `/storage` deliberately - it holds a working
  checkout's downloads, logs and compiled phar - but nothing then created the
  empty directory, and Symfony's `Process` refuses to start when its working
  directory does not exist. So `move`, `clean --remote` and `download`'s
  already-on-the-remote check all threw `The provided cwd "/app/storage" does
  not exist`, *after* the backup had been taken and downloaded. The image now
  creates it.
- **`app:validate` passed on an installation that could not move a file**,
  which is the more serious half: it checked the download path and the log path
  but never the storage path. It now checks that too, and fails rather than
  warns, because a missing cwd is not a degraded run.
- The container installed rclone to `/usr/local/bin/rclone` while
  `RCLONE_BINARY` defaults to `/usr/bin/rclone`, so a container install
  following the documented defaults had `move` and `clean --remote` fail on a
  path that does not exist. `wget` and `zstd` come from apt and land in
  `/usr/bin` already; rclone was the one installed by hand. The image now
  symlinks `/usr/bin/rclone` to it, so both paths work whatever an existing
  `.env` says. `app:validate` reports which one it found.

## [2.0.0] - 2026-08-26

The release that makes an unattended run trustworthy. Before it, a night that
failed could look exactly like a night that worked: several commands returned
success whatever happened, a mistyped command printed the command list and
exited 0, and nothing said anything unless you went and read a log. Everything
below follows from fixing that.

### Removed

- **`app:test` is gone.** It is now `app:validate`, which does considerably
  more — the old name was one keystroke from `test`, which runs the unit and
  feature suites, in a tool where the two are very different operations.
- **PHP 8.2 is no longer supported.** `composer.json` claimed `^8.2` while the
  code had already moved on; the constraint is now `^8.3`, and `ext-intl` is
  declared, because `Number::fileSize()` needs it and its absence surfaced as a
  fatal a long way from the cause.

### Added

- **`cron`** — the whole run from one crontab line: `create --all --download`,
  then `clean`. As two lines, the expiry started when the crontab *guessed* the
  downloads would be finished; here it starts when they actually are.
  Whether images are moved to secondary storage is decided by `RCLONE_REMOTE`
  rather than by a flag, so an installation that runs on the machine keeping the
  backups unsets one variable and changes nothing else. `--no-move` and
  `--no-clean` override for a single run.
- **A run lock.** One `flock` covers every command that writes, so a night that
  overruns holds the next one off rather than running over the top of it — a
  multi-gigabyte image on a slow link is exactly the case that overruns. Held
  for the life of the process, so a killed run leaves no stale lock to break by
  hand. A run skipped for the lock is reported as one that *did not happen*,
  naming what holds it. **In a container, `LOCK_FILE` must be on a shared
  mount**: each `docker compose run` gets its own filesystem, so the default is
  a lock two concurrent runs cannot see each other holding.
- **A Slack run summary** — one message per run saying what it did and what
  failed. The existing Slack *log channel* can only report trouble, so a night
  where everything worked produced nothing at all, which looks exactly like a
  cron entry nobody installed. The two are complementary and both are kept.
  Only `cron` posts one: a summary for a command somebody is sitting and
  watching is noise delivered to the channel of the person watching it.
- **`app:validate`** — the smoke run this tool had no way to do. It exercises
  rather than describes: runs each configured binary, takes and releases the
  lock, write-probes the download directory, lists the rclone remote, writes a
  real log record at every level, posts a real message to the summary webhook
  and calls the API. Four outcomes — `[ ok ]`, `[warn]`, `[fail]`, and a blank
  marker for a check that did not apply, which is deliberately not a pass — and
  a non-zero exit, so a container rebuild can be gated on it.
- **The container build lives in the repository**: `Dockerfile`,
  `docker-compose.yml` and `.dockerignore`, the last of which is why the image
  no longer risks carrying `.env` and `rclone.conf` in a layer.
- **A test suite**, from two stock tests to 166 covering every command, the API
  client and the service provider. It has been audited by mutation twice: change
  one behaviour in `app/`, run the suite, and a test should fail.
- **`LOG_HOSTNAME`**, stamped onto every record by a Monolog tap, so one webhook
  can serve more than one installation. It matters more in a container than on a
  normal box, where the machine calls itself a hex string that changes with
  every image rebuild.
- **`composer build`**, which installs `--no-dev`, compiles, restores the dev
  dependencies and then fails if `laravel/pint` is still findable in the
  artefact. `app:build` never runs Composer and `box.json` takes `vendor/`
  wholesale, so building from a development checkout compiled Pint, PHPUnit,
  Pest and Mockery into the binary — **29 MB of which most was a code formatter
  a production machine will never run**.
- `.env.example` documenting every setting with its default, `CLAUDE.md`
  describing the architecture and its traps, this changelog, a README that is
  about this tool rather than about Laravel Zero, and a LICENSE.

### Changed

- **Laravel Zero 13** (Illuminate 13), up from 12. No application code changed:
  the whole suite passed on the new framework unaltered, including the narrowed
  console kernel, and a live `app:validate` run confirmed it outside the fakes.
- **Exit codes are now the contract with cron.** Every command that works
  through a list keeps going past a failure and reports at the end, so what is
  reported is everything that went wrong rather than the first thing. `create`
  reports servers whose backup errored or timed out, `download` the servers
  whose backup is not in place, and `check`, `move` and `clean` the files they
  could not handle. The chain propagates: `download` returns the exit code of
  the `move` it called, and `create --download` that of the `download`.
- **A skip is not a failure.** `download` returns whether the backup is in place
  *afterwards* — true when it downloaded one and true when one was already
  there, local or remote. Reporting the already-downloaded case as a failure
  made `download --all` report trouble every night, which is the failure mode
  that makes an exit code worth nothing.
- `clean` cancelled at its confirmation prompt now says what was not deleted and
  points at `--dry-run`, and posts no summary. It used to print `Operation
  aborted by user` and then announce a completed backup, with no counts, to the
  channel of the person who had just cancelled it.
- The default log channel is `stack`, and `app:config` and `app:validate` render
  through [hampel/console-report](https://github.com/hampel/console-report).

### Fixed

- **A mistyped command exited 0.** `blbackup app:validte` printed the command
  list and reported success — in a tool whose exit code is the whole of what
  cron reads, and it would have made a gated container rebuild pass while
  checking nothing. A narrowed `Kernel::ensureDefaultCommand()` now proxies only
  a bare or options-only invocation.
- **`app:config` reported absolute paths as relative ones.** The storage path
  printed as `storage`, because `twoColumnDetail()`'s `EnsureRelativePaths`
  mutator strips `base_path()` out of every value and cannot be opted out of.
  Convincing, and wrong on every machine where those paths are not under the
  project — which is every container. It also printed the Slack webhook in full.
- **`clean --remote` crashed on real rclone output.** `rclone lsjson` emits
  RFC3339 with nanosecond precision, and `Z` rather than an offset on some
  backends; the fixed format string threw rather than failing the command.
- **`clean`'s remote deletions failed silently.** A `return` inside an `each()`
  closure returns from the closure, not the command.
- **`download --image=N --move` exited 1 on success**, because the helper
  returning `bool` was handed the `int` exit code of the `move` it called, and
  `0` is falsy.
- **`app:validate` died inside Monolog** when validating an unwritable log
  destination, because reporting a failure writes to the log. That exposure was
  never this command's alone: `App\Api` logs every call, so an unwritable log
  path takes any command down on its first API call.
- **`app:validate` threw a stack trace** when the rclone remote was too slow to
  answer, out of the command whose whole job is to report a failure legibly.
- **20 dependency advisories, one high, now none.** A year of updates applied
  behind the new test suite.

[Unreleased]: https://github.com/hampel/blbackup/compare/2.0.0...HEAD
[2.0.0]: https://github.com/hampel/blbackup/compare/1.9.2...2.0.0
