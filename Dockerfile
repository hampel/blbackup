# blbackup runs as a one-shot container, driven by cron on the host.
#
#   docker compose build
#   docker compose run --rm blbackup php blbackup app:validate
#
# Everything specific to an installation - the API token, the rclone config,
# the download and log directories - is mounted at runtime by
# docker-compose.yml. See .dockerignore for why none of it is copied in.

FROM php:8.3-cli

# zstd verifies a downloaded backup, wget fetches it, rclone ships it to
# secondary storage; the paths to all three are configurable, and app:validate
# runs each one to prove the image really has them.
#
# libicu-dev is here to build intl, which Illuminate\Support\Number needs to
# format the sizes and speeds every command reports. Without it those calls
# fatal, so it is a hard requirement rather than a nicety - composer.json
# declares it as ext-intl.
RUN apt-get update \
 && apt-get install -y --no-install-recommends \
        ca-certificates \
        curl \
        libicu-dev \
        unzip \
        wget \
        zstd \
 && rm -rf /var/lib/apt/lists/*

RUN docker-php-ext-install intl

# Pinned deliberately. `curl https://rclone.org/install.sh | bash` installs
# whatever is current at build time, so an image rebuilt to fix something
# unrelated can silently change the tool that moves the backups.
ARG RCLONE_VERSION=v1.69.2
RUN curl -fsSLO "https://downloads.rclone.org/${RCLONE_VERSION}/rclone-${RCLONE_VERSION}-linux-amd64.zip" \
 && unzip -q "rclone-${RCLONE_VERSION}-linux-amd64.zip" \
 && install -m 0755 "rclone-${RCLONE_VERSION}-linux-amd64/rclone" /usr/local/bin/rclone \
 && ln -s /usr/local/bin/rclone /usr/bin/rclone \
 && rm -rf "rclone-${RCLONE_VERSION}-linux-amd64" "rclone-${RCLONE_VERSION}-linux-amd64.zip"

# The symlink above is not decoration. /usr/local/bin is the right place for a
# binary installed by hand and is where rclone's own installer puts it - but
# RCLONE_BINARY defaults to /usr/bin/rclone, which is where apt would have put
# it and what .env.example documents. wget and zstd come from apt and land there
# already; rclone was the one that did not, so an install following the
# documented defaults had `move` and `clean --remote` fail on a path that does
# not exist. Both paths now work, whatever an existing .env says.

# .dockerignore excludes /storage - it holds a working checkout's downloads,
# logs and compiled phar, none of which belong in an image. But the directory
# itself has to exist: rclone is run with storage_path() as its working
# directory, and Symfony's Process refuses to start at all when its cwd does
# not, so `move`, `clean --remote` and download's already-on-the-remote check
# died with "The provided cwd /app/storage does not exist" - after the backup
# had been taken, which is the worst point in the run to fall over.
RUN mkdir -p /app/storage

# Backup images are large and are streamed to disk rather than held in memory,
# but the API responses and progress handling still want headroom.
RUN echo "memory_limit=512M" > /usr/local/etc/php/conf.d/memory.ini

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

# Dependencies before application code, so editing a command does not re-resolve
# them. --no-dev is what keeps the test and lint tooling out of the image.
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-progress --no-scripts --optimize-autoloader

COPY . .

RUN composer dump-autoload --no-dev --optimize

# No command by default prints the command list and exits 0 - cron supplies the
# command it wants, which for a nightly run is:
#   docker compose run --rm blbackup php blbackup cron
CMD ["php", "blbackup"]
