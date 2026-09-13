# blbackup - BinaryLane VPS Backup CLI

[![ci](https://github.com/hampel/blbackup/actions/workflows/ci.yml/badge.svg)](https://github.com/hampel/blbackup/actions/workflows/ci.yml)

Backup for [BinaryLane](https://www.binarylane.com.au/) VPS servers. `blbackup`
asks the BinaryLane API for a temporary snapshot of a server, downloads the
resulting compressed disk image, verifies it, ships it to cloud storage and
expires the old ones.

It is a thin, well-behaved wrapper: BinaryLane takes the snapshot, `wget`
downloads it, `zstd` verifies it and `rclone` moves it. Every command can be run
against one server or all of them, and the whole night's work is a single
`blbackup cron`.

## Requirements

- PHP 8.3 or later, with the CLI SAPI and `ext-intl`
- A BinaryLane API token
- [`wget`](https://www.gnu.org/software/wget/) to download images
- [`zstd`](https://facebook.github.io/zstd/) to verify them
- [rclone](https://rclone.org/) with a configured remote, if the images are to
  be moved to secondary storage — optional, see
  [Where backups end up](#where-backups-end-up)
- Enough free disk to hold at least one disk image per server, uncompressed size
  irrelevant but compressed size very much not

## How it works

A BinaryLane backup is a snapshot taken on their infrastructure and offered as a
download link that expires. `blbackup` turns that into a file you keep:

```
create  →  download  →  check  →  move  →  clean
```

1. **create** asks the API for a temporary backup and polls until it finishes.
2. **download** fetches the image over the temporary link, as a `.zst` file.
3. **check** runs `zstd --test` over it. A file that fails is deleted, not kept.
4. **move** hands it to `rclone` for secondary storage.
5. **clean** expires anything older than `KEEPONLY_DAYS`, locally and remotely.

The commands call each other rather than sharing code, so each stage can also be
run on its own — but for an unattended run there is one command that does all of
it, and it is the one cron should call:

```bash
blbackup cron
```

## Installation

### As a container

The intended deployment, and what the `Dockerfile` and `docker-compose.yml` in
this repository describe: a one-shot container invoked by cron on the host, with
no long-running service. `docker-compose.yml` documents every mount and why it
is what it is — in particular that `rclone.conf` must **not** be mounted
read-only, because rclone rewrites it when it refreshes an OAuth token.

```bash
git clone https://github.com/hampel/blbackup.git
cd blbackup
cp .env.example .env      # then edit it
docker compose build
docker compose run --rm blbackup php blbackup app:validate
```

Paths in `.env` name the **container** side of a mount: `DOWNLOAD_PATH=/downloads`,
not the host directory it maps to.

The host directories behind those mounts are set in the same `.env`, as
`HOST_DOWNLOAD_PATH` and `HOST_LOG_PATH`. Docker Compose reads them to build the
mounts; the app never sees them. **They have no default, on purpose:** without
them `docker compose` refuses to start and names the missing variable. A default
would put multi-gigabyte images somewhere nobody chose, and `clean` would then
expire an empty directory while the real backups kept ageing, with every run
reporting success. **Both directories must already exist:** a path that does
not is refused rather than created, so a typo stops the run instead of quietly
becoming an empty directory the backups go into.

### As a compiled binary

Each tagged release publishes one on the
[releases page](https://github.com/hampel/blbackup/releases) — a single
self-contained PHAR, with a `.sha256` beside it:

```bash
curl -L -o blbackup https://github.com/hampel/blbackup/releases/latest/download/blbackup
curl -L -o blbackup.sha256 https://github.com/hampel/blbackup/releases/latest/download/blbackup.sha256
sha256sum -c blbackup.sha256
chmod +x blbackup
./blbackup --version
```

It needs PHP 8.3 or newer with `ext-intl`, and `zstd`, `wget` and `rclone` on
the machine — `./blbackup app:validate` checks all of it.

To build one yourself:

```bash
composer install
composer build            # → builds/blbackup
```

`composer build` installs without dev dependencies, compiles, restores them, and
then fails if `laravel/pint` is still findable in the artefact. Use it rather
than `php blbackup app:build` directly: `app:build` never runs Composer and
`box.json` takes `vendor/` wholesale, so building from a development checkout
compiles Pint, PHPUnit, Pest and Mockery into the binary.

The binary reads **`.env` from the directory holding the binary**, and resolves
its logs and the default download path **against the working directory**. Under
cron those are rarely the same place — the working directory is wherever the
crontab last changed to — so keep `.env` beside the binary and set an absolute
`LARAVEL_STORAGE_PATH`. `blbackup app:config` reports what each path resolved
to.

### From source

```bash
composer install --no-dev
php blbackup app:validate
```

## Configuration

Everything is environment-driven through `.env`. `.env.example` documents every
setting with the default it takes when left unset, and
`blbackup app:config` prints what a given install actually resolved to — which
is the way to check a `.env` took effect.

| Setting | Default | What it does |
|---|---|---|
| `BINARYLANE_API_TOKEN` | — | **Required.** BinaryLane tokens are not scoped: this one can do anything the account can |
| `BINARYLANE_TIMEOUT` / `BINARYLANE_CONNECT_TIMEOUT` | `10` / `5` | Seconds one API request may take. Not a download or a backup |
| `APP_TIMEZONE` | `UTC` | Datestamps in filenames, local-time columns, log records |
| `DOWNLOAD_PATH` | `storage/backups` | Where images land |
| `DOWNLOAD_TIMEOUT` | `3600` | Seconds to wait for a backup to be taken |
| `KEEPONLY_DAYS` | `7` | What `clean` expires |
| `LOCK_FILE` | storage path | See [The lock](#the-lock) — **must be on a shared mount in a container** |
| `WGET_BINARY` | `/usr/bin/wget` | |
| `ZSTD_BINARY` | `/usr/bin/zstd` | |
| `RCLONE_BINARY` | `/usr/bin/rclone` | |
| `RCLONE_REMOTE` | — | `remote:path_prefix`. Unset means images stay on this machine |
| `LOG_CHANNEL` / `LOG_STACK` | `stack` / `null` | **Logging is off until `LOG_STACK` names a channel** |
| `LOG_STORAGE_PATH` | `storage/blbackup.log` | |
| `LOG_HOSTNAME` | — | Stamped on every record, so one webhook can serve several installs |
| `LOG_SLACK_WEBHOOK_URL` | — | A log channel: posts records at `LOG_SLACK_LEVEL` and above |
| `BLBACKUP_SUMMARY_SLACK_WEBHOOK` | — | The run summary: one message per run. Not the same thing |
| `BLBACKUP_SUMMARY_NOTIFY` | `always` | `failure` for the bad nights only |

Credentials are never printed. `app:config` reports a token as
`set (64 characters)`, which is enough to tell an empty setting from a truncated
paste and no use to anyone reading over your shoulder.

## Commands

```bash
blbackup                          # the command list

blbackup cron  [--include=FILE] [--exclude=FILE] [--no-move] [--no-clean]

blbackup account
blbackup servers  [host|id] [--ids|--names]
blbackup backups  [host|id] [--ids] [--urls]

blbackup create   <host|id>|--all [--include=FILE] [--exclude=FILE] [-d|--download] [-m|--move]
blbackup download <host|id>|--all|--image=ID [-f|--force] [--no-test] [--no-wget] [-m|--move]
blbackup check    <file>|--all [--dry-run]
blbackup move     <file>|--all [--remote=REMOTE] [--dry-run]
blbackup clean    [--days=N] [--remote] [--dry-run]

blbackup app:config   [--only=SECTION]
blbackup app:validate [--offline] [--unattended] [--no-api] [-d|--download=URL]
```

`--include` and `--exclude` each take a path to a plain-text file, one hostname
per line, matched against the server name. Lines are trimmed, so a list edited on
Windows still matches.

**Prefer configuring them.** `INCLUDE_FILE` and `EXCLUDE_FILE` set the same
paths, and the options override them for one run. A list that only ever appears
on the crontab line cannot be shown by `app:config` or checked by `app:validate`,
so nothing tells you the path is wrong until a run fails on it — and nothing at
all tells you that a list naming no servers has quietly stopped filtering.
Neither is set by default, which means every server on the account is backed up;
that is not treated as a fault.

**`cron`** is the unattended entry point. It runs `create --all --download`
followed by `clean`, and decides two things from configuration rather than from
flags:

- **moving is gated on `RCLONE_REMOTE`.** Set, and images are moved to the
  remote and expired there. Unset, and they are downloaded and kept where they
  land, with no rclone invocation at all. `--no-move` forces download-only for
  one run.
- **`clean --remote` is gated on the same setting**, but not on `--no-move`:
  what previous nights shipped there still ages, whatever tonight did.

It never prompts, and it has no `--dry-run` — `create` and `download` do not
have one, so the flag would really take a snapshot and really pull it down,
which is the opposite of what it promises everywhere else. To rehearse, run the
stages themselves.

**`clean`** asks before it deletes anything, unless `--dry-run` or
`--no-interaction`. `--days` overrides `KEEPONLY_DAYS` for one run.

**`download`** defaults to `wget`. `--no-wget` switches to an in-process HTTP
download instead. A file whose size does not exactly match the API's figure is
reported as a probable incomplete download and left alone; `--force`
re-downloads it.

## Where backups end up

```
<DOWNLOAD_PATH>/<server name>/backup-<short name>-<Ymd-His>-<image id>.zst
```

The datestamp is the image's `created_at` in `APP_TIMEZONE`, and the short name
is the first dot-separated label of the hostname —
`web1.example.com` gives `backup-web1-20260826-020144-12345.zst`. `move`
reproduces that relative path beneath the rclone remote, which is what lets
`download` tell an image already shipped from one still to fetch, and `clean`
expire either side.

An install with no `RCLONE_REMOTE` keeps everything at `DOWNLOAD_PATH` and never
runs rclone. That is a supported configuration, not a half-configured one —
`app:validate` reports it as a skip rather than a failure — and it is the right
one when the machine running `blbackup` is already the machine keeping the
backups.

## Running it from cron

One line:

```cron
0 2 * * * cd /opt/blbackup && \
          docker compose run --rm blbackup php blbackup cron >> /var/log/blbackup/cron.log 2>&1
```

**`>>` grows without limit**, and nothing rotates that file for you. A run writes
little now — progress displays are drawn only when the output is decorated, which
under cron it is not — but little is not nothing. Point it at logrotate, truncate
it with `>` instead of appending, or wrap the line in a script that keeps the
tail; the tool has no opinion, but the file needs a ceiling from somewhere.

**Exit codes are the contract with cron.** Every command returns non-zero if any
part of its work failed, and keeps going through the rest rather than stopping
at the first failure — so what is reported is everything that went wrong, not
just the first thing. A stage that fails does not stop the ones after it: a
server that would not back up tonight is no reason to leave last month's
downloads filling the disk.

A mistyped command exits non-zero too, which is less obvious than it sounds and
took an override to achieve — the stock behaviour prints the command list and
exits 0, which in a tool whose exit code is the whole of what cron reads is a
silent success.

### The lock

One lock covers every command that writes, so a run that overruns holds the next
one off rather than running over the top of it. A multi-gigabyte image on a slow
link is exactly the case that overruns. The lock is an `flock` held for the life
of the process, so a run that is killed leaves nothing to clean up.

A run skipped for the lock is reported as one that *did not happen*, naming what
holds it — not as a silent success and not as a failure of the backup itself.

**In a container, `LOCK_FILE` must name a path on a shared mount.** Each
`docker compose run` gets its own filesystem, so a lock left at its default
inside the container is one that two concurrent runs cannot see each other
holding: it does nothing, and nothing says so. `app:validate` prints the path it
locked.

## Logging and alerting

Two mechanisms, deliberately complementary:

**The log channels** record what happened, and can post to Slack at a threshold
— `LOG_SLACK_LEVEL=error` and above, which is the default and is as high as it
can usefully go, since nothing here logs above `error`. A log channel can only
ever report trouble, so a night where everything worked produces nothing at all,
which looks exactly like a cron entry nobody installed.

**The run summary** is one message per run saying what it did — servers backed
up, bytes downloaded, files moved, files expired, how long it took, and what
failed. That is the thing that tells a working backup from an uninstalled one.
Set `BLBACKUP_SUMMARY_SLACK_WEBHOOK` to enable it, and
`BLBACKUP_SUMMARY_NOTIFY=failure` if you would rather have silence than a
nightly all-clear.

Only `cron` posts a summary. Every stage can be run by hand, and a summary
posted for a command somebody is sitting and watching is noise delivered to the
channel of the person watching it.

Logging resolves to nothing until `LOG_STACK` names a channel — the default
discards everything. `app:validate` warns about exactly that.

## Validating an install

```bash
blbackup app:validate
```

It exercises rather than describes: it runs each configured binary, takes and
releases the lock, write-probes the download directory, lists the rclone remote,
writes a real log record at every level, posts a real message to the summary
webhook and calls the API. Four outcomes — `[ ok ]`, `[warn]`, `[fail]`, and a
blank marker for a check that did not apply, which is deliberately not a pass —
and a non-zero exit if anything failed, so a container rebuild can be gated on
it.

`--download=<url>` additionally pulls a real URL through the download path.

**It really posts to Slack**, which is the point: a destination with a threshold
only proves it works when something at that level is really sent, and a revoked
webhook is invisible from the sending end — the alert simply never arrives,
which looks exactly like a run where nothing went wrong. Say so before running
it on a machine whose Slack channel other people watch.

It sends two things. The record it writes at every log level goes wherever
`LOG_STACK` routes it, and a separate test message goes to
`BLBACKUP_SUMMARY_SLACK_WEBHOOK`. An attended run reports what the first one
posted — `posted 4 records at error and above: error, critical, alert,
emergency` — so the count can be compared against the channel. Four records at a
threshold of `error` is right; three means the threshold is not what the
configuration says. If both webhooks point at the same channel, the line says so
and adds one, because the summary lands there too.

**It also warns when `LOG_SLACK_LEVEL` is set above anything this tool logs at.**
Nothing here logs above `error`, so a channel set to `critical` accepts a valid
webhook, passes every check, receives this command's own test sweep, and then
never fires again — which is indistinguishable from a channel with nothing to
report. `error` is the default for that reason. A warning rather than a failure,
so an install that set it high deliberately can still gate a rebuild on this
command.

### Three flags for three different conditions

| flag | say this when | it stops |
|---|---|---|
| `--offline` | there is no outbound network, or you are not spending it | every call that leaves the machine |
| `--unattended` | the network is fine, but nobody is watching the destination | only the two messages above |
| `--no-api` | the API calls are not worth their cost this run | the account and server calls, nothing else |

**`--offline` implies `--unattended`; not the reverse.** That is the point of
having both. An unattended run still wants its outbound probes to fail loudly —
a backup remote that stopped answering is exactly what an unwatched rebuild gate
is for — while wanting nothing posted into a channel nobody asked to read.
`--offline` also skips the rclone remote probe and the API calls, and refuses
`--download` rather than quietly ignoring it.

None of them is a quiet mode and none is the default. Everything they suppress
is reported as a skip, naming the flag responsible, so a run never looks like it
checked something it did not. Forgetting one costs some noise you can delete;
having one on by default would cost every run its proof of delivery without
saying so.

A warning or a failure is still written to the log under all three. That is the
half of this command's output meant for whoever is not at the terminal, and
these flags are the runs where that is everybody.

Do not try to get the same effect by blanking a webhook on the command line.
`.env` is loaded mutably, so `BLBACKUP_SUMMARY_SLACK_WEBHOOK= blbackup
app:validate` keeps the value from the file and sends anyway — it looks like a
second layer behind the flag and is not a layer at all.

## Development

```bash
composer install
composer test                     # or: php blbackup test
php vendor/bin/pest --filter='some name'
```

CI runs three jobs on every push: the suite, a **build of the container image
with `app:validate --no-api` run inside it**, and a compiled binary. The middle
one is there because the `Dockerfile` had never been built anywhere but the
production server, so its first execution was always on the machine taking the
backups — and two defects reached it that way. `--no-api` needs no credentials
and still exercises every binary, path and dependency — including really writing
the log records, which is why that job uses it rather than `--offline`.

The suite fakes at the process, HTTP client and filesystem boundaries and
asserts on what the commands actually produce — the exact shell command string,
the request body, the state of the download disk afterwards. It has been audited
by mutation: change one behaviour in `app/`, run the suite, and a test should
fail.

`CLAUDE.md` documents the architecture, the conventions and the traps, and is
worth reading before changing anything.

## Built with

[Laravel Zero](https://laravel-zero.com/), [Pest](https://pestphp.com/),
[hampel/console-report](https://github.com/hampel/console-report) and
[hampel/slack-message](https://github.com/hampel/slack-message).

## Changelog

See [CHANGELOG.md](CHANGELOG.md).

## License

MIT — see [LICENSE](LICENSE).
