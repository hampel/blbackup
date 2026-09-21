# blbackup — BinaryLane VPS Backup CLI

[![ci](https://github.com/hampel/blbackup/actions/workflows/ci.yml/badge.svg)](https://github.com/hampel/blbackup/actions/workflows/ci.yml)

Backs up [BinaryLane](https://www.binarylane.com.au/) VPS servers. It takes a snapshot through the
BinaryLane API, downloads the compressed disk image with `wget`, verifies it with `zstd`, moves it
to cloud storage with `rclone`, and expires old backups.

## Requirements

- PHP 8.3 or later, with `ext-intl`
- A BinaryLane API token
- [`wget`](https://www.gnu.org/software/wget/) and [`zstd`](https://facebook.github.io/zstd/)
- [rclone](https://rclone.org/) with a configured remote — optional, see
  [Where backups end up](#where-backups-end-up)
- Disk space for at least one compressed image per server

## How it works

```text
create  →  download  →  check  →  move  →  clean
```

1. **create** asks the API for a backup and waits for it to finish.
2. **download** fetches the image as a `.zst` file.
3. **check** runs `zstd --test` on it, and deletes it if the test fails.
4. **move** moves it to the rclone remote.
5. **clean** deletes backups older than `KEEPONLY_DAYS`, locally and on the remote, except the
   most recent `KEEPLEAST_DAYS` days of each server.

Each stage can be run on its own. `blbackup cron` runs them all, and is what cron should call.

## Installation

### As a container

The `Dockerfile` and `docker-compose.yml` run it as a one-shot container, started by cron on the
host.

```bash
git clone https://github.com/hampel/blbackup.git
cd blbackup
git checkout "$(git describe --tags --abbrev=0)"   # the latest release
cp .env.example .env                                # then edit it
VERSION=$(git describe --tags --abbrev=0) docker compose build
docker compose run --rm blbackup php blbackup app:validate
```

- **Always build with `VERSION=`.** The image has no git, so without it the version is
  `unreleased` — in `--version`, `app:config` and every Slack run summary. `app:validate` warns.
- **Paths in `.env` are container paths**, such as `DOWNLOAD_PATH=/downloads`.
- **`HOST_DOWNLOAD_PATH` and `HOST_LOG_PATH`** in `.env` set the host directories for the
  mounts. They have no default, and both directories must already exist.
- **Do not mount `rclone.conf` read-only.** rclone rewrites it when it refreshes an OAuth token.

### Upgrading a container

`.env` is not in git, so upgrading leaves your settings alone. Read the
[changelog](CHANGELOG.md) first and add any new settings, then:

```bash
git fetch --tags
git checkout "$(git describe --tags --abbrev=0 origin/master)"
VERSION=$(git describe --tags --abbrev=0) docker compose build
docker compose run --rm blbackup php blbackup --version
docker compose run --rm blbackup php blbackup app:validate
```

`--version` should print the release you checked out. If `app:validate` fails, do not keep the
new image.

The clone is left on a release tag rather than a branch, so use these steps rather than
`git pull`. To roll back, check out an older tag and build the same way:

```bash
git checkout 2.7.2
VERSION=$(git describe --tags --abbrev=0) docker compose build
docker compose run --rm blbackup php blbackup app:validate
```

### As a compiled binary

Each release publishes a PHAR and its checksum on the
[releases page](https://github.com/hampel/blbackup/releases):

```bash
curl -L -o blbackup https://github.com/hampel/blbackup/releases/latest/download/blbackup
curl -L -o blbackup.sha256 https://github.com/hampel/blbackup/releases/latest/download/blbackup.sha256
sha256sum -c blbackup.sha256
chmod +x blbackup
./blbackup --version
```

- **`.env` is read from the binary's directory.** Logs and the default download path resolve
  against the working directory, so under cron set an absolute `LARAVEL_STORAGE_PATH`.
  `app:config` shows what each path resolved to and which `.env` was read.
- **`.env` wins over an exported variable.** `KEEPONLY_DAYS=30 blbackup clean` does not override
  a `KEEPONLY_DAYS` set in the file, even an empty one.

To build it yourself, use `composer build` rather than `app:build` — it leaves out the dev
dependencies:

```bash
composer install
composer build            # → builds/blbackup
```

### From source

```bash
composer install --no-dev
php blbackup app:validate
```

## Configuration

Settings go in `.env`. `.env.example` lists every one with its default, and `blbackup app:config`
shows what an install resolved to. Credentials are never printed.

| setting | default | what it does |
|---|---|---|
| `BINARYLANE_API_TOKEN` | — | **Required.** Tokens are not scoped, so this one can do anything the account can |
| `BINARYLANE_TIMEOUT` / `BINARYLANE_CONNECT_TIMEOUT` | `10` / `5` | Seconds per API request |
| `APP_TIMEZONE` | `UTC` | For filename datestamps and log records |
| `DOWNLOAD_PATH` | `storage/backups` | Where images are downloaded to |
| `DOWNLOAD_TIMEOUT` | `3600` | Seconds to wait for a backup to be taken |
| `KEEPONLY_DAYS` | `7` | Age at which `clean` deletes a backup |
| `KEEPLEAST_DAYS` | `3` | Days of each server's backups `clean` always keeps. `0` turns it off |
| `INCLUDE_FILE` / `EXCLUDE_FILE` | — | Server lists for `--all`. See [Server lists](#server-lists) |
| `LOCK_FILE` | storage path | **Must be on a shared mount in a container.** See [The lock](#the-lock) |
| `WGET_BINARY` / `ZSTD_BINARY` / `RCLONE_BINARY` | `/usr/bin/…` | |
| `RCLONE_REMOTE` | — | `remote:path`. Unset keeps images on this machine |
| `LOG_CHANNEL` / `LOG_STACK` | `stack` / `null` | **Nothing is logged until `LOG_STACK` names a channel** |
| `LOG_STORAGE_PATH` | `storage/blbackup.log` | |
| `LOG_HOSTNAME` | — | Added to every log record. Set it in a container |
| `LOG_SLACK_WEBHOOK_URL` | — | Posts log records at `LOG_SLACK_LEVEL` (`error`) and above |
| `BLBACKUP_SUMMARY_SLACK_WEBHOOK` | — | Posts one summary per `cron` run |
| `BLBACKUP_SUMMARY_NOTIFY` | `always` | `failure` to post only failed runs |

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

### cron

Runs `create --all --download`, then `clean`. It never prompts, and has no `--dry-run`.

- If `RCLONE_REMOTE` is set, images are moved to the remote and `clean` expires it too.
- `--no-move` downloads only, for one run. The remote is still cleaned.
- A failed stage does not stop the later ones.

### Server lists

`--include` and `--exclude` take a plain-text file with one hostname per line. `INCLUDE_FILE` and
`EXCLUDE_FILE` set them in `.env`, where `app:config` and `app:validate` can check them; the
options override them for one run.

- **They apply only to `--all`.** A server named on the command line is always processed.
- With neither set, every server on the account is backed up.
- A list file that does not exist fails the run. An empty list is ignored.

### clean

Deletes backups older than `KEEPONLY_DAYS`, and asks first unless given `--dry-run` or
`--no-interaction`. `--days` overrides `KEEPONLY_DAYS` for one run.

It always keeps the most recent `KEEPLEAST_DAYS` days of each server's backups, so a server that
stops being backed up keeps its last good ones:

- It counts days, not files.
- A server is the directory its backups sit in, so a renamed server keeps its old backups until
  you delete them.
- `--days` does not change it.
- `clean -v` lists the files it kept.

### download

Uses `wget` by default; `--no-wget` downloads in-process instead. A file whose size does not match
the API's is left alone and reported; `--force` downloads it again.

## Where backups end up

```text
<DOWNLOAD_PATH>/<server name>/backup-<short name>-<Ymd-His>-<image id>.zst
```

The datestamp is the image's creation time in `APP_TIMEZONE`, and the short name is the first
part of the hostname: `web1.example.com` gives `backup-web1-20260826-020144-12345.zst`. `move`
keeps the same path on the remote.

Without `RCLONE_REMOTE`, backups stay in `DOWNLOAD_PATH` and rclone is never run.

## Running it from cron

```cron
0 2 * * * cd /opt/blbackup && \
          docker compose run --rm blbackup php blbackup cron >> /var/log/blbackup/cron.log 2>&1
```

- **Nothing rotates `cron.log`.** Use logrotate, or write it with `>` instead of `>>`.
- **Any failure exits non-zero**, after the rest of the run has finished. So does a mistyped
  command.

### The lock

Every command that writes takes one lock. If a run is still going when the next one starts, the
next one is skipped and reports what holds the lock. A killed run releases it.

**In a container, `LOCK_FILE` must be on a shared mount.** Each `docker compose run` has its own
filesystem, so a lock inside the container does nothing. `app:validate` prints the path.

## Logging and alerting

- **Log channels** record what happened. The Slack channel posts at `LOG_SLACK_LEVEL` and above;
  keep it at `error`, the highest level anything here logs at. A night with no errors posts
  nothing.
- **The run summary** posts one message per `cron` run: servers, bytes downloaded, files moved
  and expired, duration, failures. Set `BLBACKUP_SUMMARY_SLACK_WEBHOOK`.

With `BLBACKUP_SUMMARY_NOTIFY=failure`, a successful night and a night that never ran look the
same. Neither setting can report a run that never started. For that, use a dead-man's switch that
is pinged after each successful run:

```cron
0 2 * * * cd /opt/blbackup && \
          docker compose run --rm blbackup php blbackup cron >> /var/log/blbackup/cron.log 2>&1 && \
          curl -fsS --retry 3 https://checks.example.test/blbackup
```

## Validating an install

```bash
blbackup app:validate
```

It runs each binary, takes the lock, write-tests the download directory, lists the rclone remote,
writes a log record at every level, sends a test run summary and calls the API. Each check reports
`[ ok ]`, `[warn]`, `[fail]` or blank (not applicable). Any failure exits non-zero.

- `--download=<url>` also downloads a real URL.
- **It posts to Slack.** Warn anyone else watching the channel first. It prints how many records
  it posted — four at `error` — plus one if the summary shares the webhook.
- It warns if `LOG_SLACK_LEVEL` is above `error`, since the channel would then never post.

| flag | use it when | it skips |
|---|---|---|
| `--offline` | there is no network | everything that leaves the machine |
| `--unattended` | nobody is watching the Slack channel | the two Slack posts |
| `--no-api` | you do not want the API called | the API calls |

`--offline` implies `--unattended`. Skipped checks are reported as skips, and warnings and
failures are still logged. Blanking a webhook on the command line does not stop a post, because
`.env` wins.

## Development

```bash
composer install
composer test                     # or: php blbackup test
php vendor/bin/pest --filter='some name'
```

CI runs the tests, builds the container image and runs `app:validate --no-api` in it, and builds
the PHAR. `CLAUDE.md` covers the architecture and conventions.

## Built with

[Laravel Zero](https://laravel-zero.com/), [Pest](https://pestphp.com/),
[hampel/console-report](https://github.com/hampel/console-report) and
[hampel/slack-message](https://github.com/hampel/slack-message).

## Changelog

See [CHANGELOG.md](CHANGELOG.md).

## License

MIT — see [LICENSE](LICENSE).
