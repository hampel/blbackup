# Changelog

Notable changes to blbackup. Versions before 2.0.0 are recorded in the git
history only.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and
this project uses [semantic versioning](https://semver.org/spec/v2.0.0.html).

## [2.8.1] - 2026-09-21

### Changed

- **The include and exclude lists apply only to `--all`.** A server named on the command
  line — `create <server>`, `download <server>` — was filtered through them too, so the
  excluded server, the one most likely to be backed up by hand, could not be: the command
  did nothing and exited 0. A named server now ignores both lists, and does not read them,
  so a missing list file no longer stops it. `--include` or `--exclude` given with a
  named server says it is ignored. `cron` is unaffected: it runs `--all`.

## [2.8.0] - 2026-09-21

### Added

- **`KEEPLEAST_DAYS`, a floor under `KEEPONLY_DAYS`.** `clean` keeps the most recent
  `KEEPLEAST_DAYS` days of each server's backups whatever their age, locally and on the
  remote, so a server that stops being backed up keeps its last good backups rather than
  having them expire with the rest. Counted in days rather than files, per server
  directory. Defaults to `3`; `0` expires strictly by age, as before. It only ever
  prevents a deletion, so on an install backing up nightly with the default retention it
  changes nothing.

### Changed

- **Moved to `hampel/binarylane-api-laravel` 0.7.0** (`hampel/binarylane-api` 0.6.0), from
  0.6.0. The changes are to DNS records, which this tool does not use. An API request that gets
  no answer now says that whether it was carried out is unknown, where it named only the
  transport error. Both API timeouts still apply.

## [2.7.2] - 2026-09-16

### Fixed

- **A malformed line in the `.env` beside the binary is reported rather than fatal.** The
  compiled binary died with an uncaught parse error — exit 255, and the stack trace on
  standard output wherever `display_errors` is on, which is the default in the PHP images
  this ships in. It now says `The environment file is invalid!` with the offending line on
  standard error and exits 1, which is what a checkout and a container already did.
- **`app:validate` fails a log channel that is not configured.** It reported one as
  `[ ok ] log channel (x)  unknown driver`, so a stack naming a channel that does not exist
  passed the check a deployment gates on — while Laravel answered every record with its
  emergency logger, in a file this tool does not mention. `LOG_CHANNEL=null` is the way in:
  the word `null` reaches the config as no value at all, and an empty default becomes the
  channel name `default`, which nothing defines.
- **`LOG_STACK=null` no longer turns logging off in a way nothing reports.** It is the value
  `.env.example` documents, but the framework reads the literal word `null` as no value at all,
  leaving a stack holding one channel with no name. Laravel cannot build that, so every record
  went to its emergency log — `storage/logs/laravel.log`, which nothing here documents — while
  the run carried on and exited 0. An empty `LOG_STACK`, a stray comma in a list of channels,
  and a space after one — `single, slack` names a channel that does not exist — did the same.
  Reported by another tool in this fleet, which hit it as an outright failure.

## [2.7.1] - 2026-09-15

### Fixed

- **The test suite passes on a checkout with no `.env`.** One test read the environment file
  the bootstrap records in a way that throws when there is none, so 2.7.0's CI failed and it
  was never published as a release. The tool itself is unchanged from 2.7.0, which has the
  notes below.

## [2.7.0] - 2026-09-14

### Added

- **`app:config` reports the environment file it read**, or where it looked when there was
  none. A compiled binary reads the `.env` beside itself rather than the one in the working
  directory, so a setting that seems to be ignored is most often in a file that was never
  read — and with no file at all, every setting shows its default and nothing else says why.
- **`app:config` shows the API connect timeout and the Slack emoji**, the two documented
  settings it did not list.

### Changed

- **Moved to `hampel/binarylane-api-laravel` 0.6.0** (`hampel/binarylane-api` 0.5.0), from
  0.3.0. Nothing this tool does changes: the releases in between affect DNS records, reverse
  names, how the HTTP transport is overridden and one server field, and it uses none of them.
  The API request and connect timeouts still apply.

### Fixed

- **`app:validate` no longer fails a remote whose path has not been created yet.** A new
  install's `RCLONE_REMOTE` path is created by the first backup moved there, so the check
  failed — reporting the remote as unreachable — before any backup had run. It now warns that
  the path does not exist, which is normal before the first move and a typo otherwise. A remote
  that cannot be reached or is not configured still fails.
- **A failure that stops a command now prints under `--quiet`.** A rejected API token, a
  missing server list and the other failures that end a command outright exited 1 and printed
  nothing, while the failure of a single server printed. Since cron mails output rather than an
  exit code, `cron -q` sent no mail for exactly the failures that stop a whole run.
- **A backup image's download URL is no longer written to the log, the console or a failure
  message.** The URL needs no authentication and is valid for 24 hours, so it grants the whole
  disk to whoever reads it. It was logged in full on every download, and on a failed download it
  reached an `error` record — through wget's own error output, or the download exception's message
  — which a Slack log channel at its default threshold posts. Only the host is shown now.
  `backups --urls` still prints URLs, since that is what it is for.

## [2.6.0] - 2026-09-14

### Changed

- **The BinaryLane API is reached through `hampel/binarylane-api-laravel`** rather
  than a client built into this tool. Error messages from the API now name the
  request and the status — `BinaryLane rejected GET .../v2/account (HTTP 401): the
  API token was missing, malformed, expired or revoked` — in place of the shorter
  `Could not fetch account information [401]: Unauthorized`.
- **Each API request is bounded at 10 seconds, and its connection at 5** —
  previously the HTTP client's defaults, 30 and 10. Set
  `BINARYLANE_TIMEOUT` and `BINARYLANE_CONNECT_TIMEOUT` to change them. They do not
  bound a download or a backup being taken; `DOWNLOAD_TIMEOUT` still does.
- **A backup blocked on a question or an unpaid invoice fails at once**, reported
  as `status: blocked`, rather than being polled until the timeout.
- **The backup timeout counts the time spent waiting between checks**, not the
  time elapsed, so the time taken by the requests themselves is not charged
  against it.
- **A backup that fails, is blocked or reports a status that is not recognised is
  logged with the reason BinaryLane gave**, not only its status.

### Fixed

- **Every page of servers is read.** The API returns 20 servers a page, and
  `create --all`, `download --all` and `servers` read only the first, so an
  account with more than 20 servers had the rest left out without a word.
- **`backups` with no server lists every backup on the account.** It read the
  first page of all images — operating system images included — and listed only
  the backups among those 20.
- **`app:validate` reports how many servers the account has.** Its `Servers
  visible` line counted one page, so it could not report more than 20.
- **A backup the API accepts without an action to follow is reported as a
  failure**, where it previously ended the command with a PHP error.
- **A backup offering no compressed download is reported as having no download
  link**, rather than failing in `wget`.
- **`.env.example` and the README no longer say an API token can be limited to
  reading and taking backups.** BinaryLane tokens have no scopes: a token can do
  anything its account can.

## [2.5.0] - 2026-09-11

### Changed

- **Container installs must now set `HOST_DOWNLOAD_PATH` and `HOST_LOG_PATH` in
  `.env`.** `docker-compose.yml` no longer hardcodes the host directories behind
  its two mounts; it reads them from the same `.env` the app is given. **Add both
  before pulling this change**, set to the directories the compose file used to
  name. Adding them first does no harm, because the old compose file ignores them
  and so does the app. Pull first and `docker compose` refuses to start until
  they are set.

  They have no default, on purpose. A default would put backups somewhere nobody
  chose, and `clean` would then expire an empty directory while the real backups
  kept ageing, with every run reporting success. Unset, `docker compose` stops
  and names the missing variable. A path that does not exist is refused too,
  rather than created as an empty directory, so a typo fails the same way — and
  both directories must already exist. `DOWNLOAD_PATH` and `LOG_STORAGE_PATH` are
  unchanged: they still name the container side. A compiled binary ignores all
  of this.

## [2.4.0] - 2026-09-10

### Changed

- **The application calls itself `blbackup`, not `BinaryLane Backup`.** The name
  it goes by everywhere else — the binary, the repository, the container — is now
  the name it reports. You will see it in three places: `--version`, the `Name`
  row in `app:config`, and the footer of every Slack run summary, which now reads
  `blbackup 2.4.0 on <host>`. **If anything filters that channel on the old
  string, it will stop matching.** The Slack log channel's default username
  follows the same setting, so an install with no `LOG_HOSTNAME` still posts as
  `blbackup`.

- **`LOG_SLACK_LEVEL` now defaults to `error` rather than `critical`.** Nothing
  in this application logs above `error` — verified across every call site — so
  the stock Laravel default meant a configured Slack log channel accepted a valid
  webhook, passed every check in `app:validate`, received that command's own test
  sweep, and then never fired again. A channel that cannot fire looks exactly
  like a channel with nothing to report, which is the failure worth catching.

  **This is a behaviour change for an install that never set the variable**: a
  channel that has been silent will start receiving `error` records, which is
  what configuring it asked for. Set `LOG_SLACK_LEVEL` explicitly to keep the old
  threshold.

### Added

- **`app:validate` warns when the Slack threshold is above anything this tool
  logs at**, naming the level to set. Changing a default only ever reaches
  installs that never set the variable, so the warning is the half that reaches
  the ones that did. A warning rather than a failure, because this command's exit
  code gates a container rebuild. Reported even under `--unattended` and
  `--offline`, unlike the two sends: a threshold is a static fact about the
  configuration rather than something the sweep discovers, so the run that posts
  nothing is exactly the run that should surface it.

- **The delivery count includes the run summary when both webhooks point at the
  same channel.** They are separate settings, but pointing both at one is the
  obvious thing to do — and then one more message arrived than the line
  predicted, which makes a correct count look wrong.

## [2.3.0] - 2026-09-06

### Added

- **`app:validate --unattended`.** The command sends two things whose only proof
  is a person seeing them arrive: the record it writes at every log level, and
  the run summary test post. Those sends are the point — a webhook url and
  `LOG_SLACK_LEVEL` are both unprovable from the sending end, and records landing
  at the configured threshold prove both at once — so the flag suppresses exactly
  those two and nothing else. Every binary, path, lock, remote and API call still
  runs. Use it when the network is fine but nobody is watching where the messages
  land.

  It is deliberately narrower than `--offline` below, which also ships in this
  release: an unattended run still wants its outbound probes to run, because a
  backup remote that stopped answering is exactly what a gate nobody is watching
  exists to surface. Never the default, and both suppressed checks report as
  skips rather than vanishing.

- **`app:validate --offline`.** The fleet's name for "make no call that leaves
  this machine": it skips the API calls and the rclone remote probe, refuses
  `--download` rather than quietly ignoring it, and implies `--unattended`. Not
  the reverse — that asymmetry is why there are two flags rather than one.
  Everything either flag suppresses is reported as a skip naming the flag
  responsible, so a run never looks like it checked something it did not.

  `--no-api` is unchanged and is **not** deprecated. It covers one of the four
  things here that reach outside, so renaming it would have been a silent
  widening under a name people already script rather than a rename — and it has a
  use `--offline` cannot serve, which is CI running this command inside the
  freshly built image with no credentials but with the log sweep really written.

- **An attended run now says what it posted.** `posted 4 records at error and
  above: error, critical, alert, emergency — check they arrived`, derived from
  the effective log stack and that channel's threshold. Sending was previously
  unverifiable in practice, because nobody was told what to expect: four records
  is right for a threshold of `error`, and three means the threshold is not what
  the configuration says.

### Fixed

- **The test suite posted to a live Slack channel.** A full run sent 41 real
  messages to whatever webhook the developer had in `.env`, and separately made
  four requests to `hooks.slack.com` through Monolog. Three reasonable decisions
  lined up to allow it: the project `.env` is loaded during tests, `SlackSummary`
  is built from config by the service provider, and the transport under it is a
  real Guzzle client rather than the `Http` facade — so `Http::fake()` never saw
  the requests and none of the existing fakes were going to stop them. The
  webhook and the Slack log level are now pinned alongside the API token and the
  binaries, and two tests fail if anyone unpins them.

## [2.2.0] - 2026-08-28

### Added

- **`app:validate` reports the version.** The first thing worth knowing after a
  rebuild is whether what you deployed is what you meant to, and the command a
  rollout is gated on was the only place that did not say. A warning rather than
  a pass when it cannot tell, which is what catches the fix below being skipped.

### Fixed

- **A container called itself `unreleased`, and signed its Slack summaries with
  it.** `app.version` comes from `git describe`, and an image has neither a
  `.git` directory nor a git binary — so `app:config` reported `unreleased` and,
  because `AppServiceProvider` signs the run summary with `app()->version()`,
  every alert from that install was signed with it too. Nothing in the channel
  said which build produced an alert. The `Dockerfile` now takes a `VERSION`
  build argument, and `AppServiceProvider` applies it over `app.version`:

      VERSION=$(git describe --tags --abbrev=0) docker compose build

  Applied in the provider rather than read in `config/app.php`, because an
  `env()` call in that file freezes at build time for everything in it. A
  checkout and a compiled binary are unaffected — both already knew.

## [2.1.1] - 2026-08-28

### Fixed

- **A cron run no longer writes a progress display to a file nobody rotates.**
  The progress bars and rclone's `--progress` block were drawn unconditionally,
  so an unattended run redirected to a log wrote tens of thousands of redraw
  lines describing transfers that had finished — and raw ANSI escapes among
  them, since the in-place redraw ran whether or not anything could interpret
  it. Both are now gated on `$output->isDecorated()`, and rclone is no longer
  asked for `--progress` when nothing will draw it, so the output is not
  generated rather than merely discarded. Nothing is lost: every figure worth
  keeping — bytes, elapsed, rate — is logged and summarised when the stage ends.

## [2.1.0] - 2026-08-28

The release that 2.0.0's deployment produced. Everything here was found by
running it on the machine that takes the backups, or by the CI that now runs on
every push and did not exist before.

### Added

- **CI**, which this repository had none of. Three jobs on every push: the test
  suite, a build of the container image with `app:validate --no-api` run inside
  it, and a compiled binary through `composer build`. The container job is the
  reason the file exists — the `Dockerfile` had never been built anywhere but
  the production server, so its first execution was always on the machine that
  takes the backups, and two defects reached it that way. It also asserts the
  two specific regressions: `/usr/bin/rclone` runs, and `/app/storage` exists.
- **Released binaries.** Pushing a tag now compiles the PHAR and publishes it to
  the GitHub releases page with a `.sha256`, gated on the suite and the container
  image passing first. The job asserts that the binary's own `--version` matches
  the tag being released — `config/app.php` holds `app('git.version')` and
  `app:build` compiles its evaluated result in as a literal, so a binary built
  before its tag ships announcing the previous release with nothing to show it is
  wrong. That trap now fails the release instead.

### Added

- **`INCLUDE_FILE` and `EXCLUDE_FILE`**, so the server lists can be configured
  rather than existing only as `--include` / `--exclude` on the command line. The
  options still win for one run. This closes a gap found in production: the
  exclude list is the setting that decides *what gets backed up*, and living on
  the crontab line made it the one setting `app:config` could not show and
  `app:validate` could not check — nothing could tell you the path was wrong
  until a run failed on it.
- **`app:validate` checks the configured lists** — it reports the path and how
  many servers each names, fails on one that cannot be read, and warns on one
  that names nothing. Unset is a skip rather than a fault: most installations
  back up everything.

### Changed

- **The `--include` / `--exclude` block moved to `BaseCommand::serverList()`**,
  which `create` and `download` had carried identical copies of. Lines are now
  trimmed on the way in — a list edited on Windows arrives with CRLF endings and
  matched no hostname at all, so it filtered nobody without a word. A file that
  names no servers is now explicitly treated as no list rather than as an empty
  one, which is the behaviour it already had by accident and is the safe way
  round: a truncated include list must not silently stop the backups. That is
  surprising enough that `app:validate` warns about it.
- **`app:validate`'s section headings now come from `hampel/console-report` 2.1**
  (`RendersChecks::checkSection()`) rather than from `BaseCommand::section()`.
  They move to the two-column margin, so a heading lines up with the `[ ok ]`
  markers under it instead of hanging to their left, and the last Illuminate call
  in this command's reporting path goes with them — it now writes entirely
  through `setReportOutput()`. `cron` keeps `BaseCommand::section()` for its
  stage headings, which is deliberate and explained in `CLAUDE.md`.

### Fixed

- **`log()` chose the console verbosity with a string where an integer belongs.**
  `$verbosityMap[$level] ?? 'warning'` reaches `parseVerbosity()`, which knows
  `v`/`vv`/`vvv`/`quiet`/`normal` and nothing else, so a level the map does not
  carry silently printed at whatever verbosity the run was given rather than at
  the intended one. Latent — every current caller passes a mapped level — and now
  `OutputInterface::VERBOSITY_NORMAL`.
- **`.env.example` showed an example as though it were the default**, in three
  places. Its header promised every setting was shown with the default it takes
  when left unset, and `LOCK_FILE=/logs/blbackup.lock`, `LOG_STACK=single,slack`
  and an example `LOG_HOSTNAME` were none of them defaults. The first cost real time:
  it read as though the lock was already on a shared mount, when the default put
  it inside the container where it does nothing. Lines that cannot show a default
  are now marked EXAMPLE and say what the default really is.
- The lock file default moved from `BackupLock` into `config/binarylane.php`,
  so the file declaring the setting is the file that says what it defaults to.
  There is a test now that `.env.example` and `config/` name the same settings.
- **`blbackup schedule:run` exited 0 having done nothing.** Laravel Zero's
  scheduler commands were listed as `hidden` rather than `remove`, and hidden is
  not removed - a hidden command is absent from the command list and still runs.
  This application does not use the scheduler, so that was a clean success from
  a command with nothing to do, in a tool whose exit code is the whole of what
  cron reads: a crontab copied from another Laravel Zero app would have backed
  up nothing and reported success for as long as nobody looked. `schedule:run`,
  `schedule:list` and `schedule:finish` are now removed outright.
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

[Unreleased]: https://github.com/hampel/blbackup/compare/2.8.1...HEAD
[2.8.1]: https://github.com/hampel/blbackup/compare/2.8.0...2.8.1
[2.8.0]: https://github.com/hampel/blbackup/compare/2.7.2...2.8.0
[2.7.2]: https://github.com/hampel/blbackup/compare/2.7.1...2.7.2
[2.7.1]: https://github.com/hampel/blbackup/compare/2.7.0...2.7.1
[2.7.0]: https://github.com/hampel/blbackup/compare/2.6.0...2.7.0
[2.6.0]: https://github.com/hampel/blbackup/compare/2.5.0...2.6.0
[2.5.0]: https://github.com/hampel/blbackup/compare/2.4.0...2.5.0
[2.4.0]: https://github.com/hampel/blbackup/compare/2.3.0...2.4.0
[2.3.0]: https://github.com/hampel/blbackup/compare/2.2.0...2.3.0
[2.2.0]: https://github.com/hampel/blbackup/compare/2.1.1...2.2.0
[2.1.1]: https://github.com/hampel/blbackup/compare/2.1.0...2.1.1
[2.1.0]: https://github.com/hampel/blbackup/compare/2.0.0...2.1.0
[2.0.0]: https://github.com/hampel/blbackup/compare/1.9.2...2.0.0
