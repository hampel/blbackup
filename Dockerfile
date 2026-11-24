# blbackup runs as a one-shot container, driven by cron on the host.
#
#   VERSION=$(git describe --tags --abbrev=0) docker compose build
#   docker compose run --rm blbackup php blbackup app:validate
#
# Everything specific to an installation - the API token, the rclone config,
# the download and log directories - is mounted at runtime by
# docker-compose.yml. See .dockerignore for why none of it is copied in.
#
# TWO STAGES, AND THE IMAGE RUNS THE COMPILED PHAR rather than the source tree.
# The first stage builds the same artefact a release publishes; the second holds
# only that and the tools it shells out to. Until 3.0.0 the image ran the source,
# and config/app.php names the environment `development` as a literal that only
# app:build replaces - so the container listed app:build, make:command, test and
# three more that no release has, and ran something no release had ever been.

FROM php:8.5-cli AS build

# libicu-dev and intl: composer.json requires ext-intl, so the install refuses
# without it. unzip: Composer unpacks dist archives with it.
RUN apt-get update \
 && apt-get install -y --no-install-recommends libicu-dev unzip \
 && rm -rf /var/lib/apt/lists/* \
 && docker-php-ext-install intl

# Box writes a phar, which PHP refuses by default - and app:build runs Box as a
# process of its own, so a -d on the command line would not reach it. CI sets the
# same thing through setup-php's ini-values.
RUN echo "phar.readonly=0" > /usr/local/etc/php/conf.d/phar.ini

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /src

# Dependencies before application code, so editing a command does not re-resolve
# them. --no-dev is what keeps the test and lint tooling out of the phar: Box
# takes vendor/ wholesale, as `composer build` explains.
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-progress --no-scripts --optimize-autoloader

COPY . .

RUN composer dump-autoload --no-dev --optimize

# What the phar calls itself. .dockerignore leaves .git out and there is no git
# binary here, so `git describe` - which is where app.version comes from - finds
# nothing. --build-version compiles the tag in instead, so the binary knows its
# version exactly as a released one does. Pass it at build time:
#
#   VERSION=$(git describe --tags --abbrev=0) docker compose build
#
# Forgetting is not silent: the phar calls itself `unreleased`, app:validate
# warns about that, and app:validate is the gate a rebuild has to pass anyway.
ARG VERSION=
RUN php blbackup app:build blbackup --build-version="${VERSION:-unreleased}" --no-interaction \
 && if grep -aq 'laravel/pint' builds/blbackup; then \
        echo 'ERROR: the phar contains dev dependencies'; exit 1; \
    fi


# PHP 8.5, not the 8.3 floor. The floor is what the published phar promises and
# what the lock is resolved for (config.platform.php); this image is one
# installation, and it runs the newest supported release, which CI also tests.
FROM php:8.5-cli

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

# A phar's storage path is the working directory unless LARAVEL_STORAGE_PATH says
# otherwise (bootstrap/app.php). Pinned here to /app/storage, which is where it
# was when the image ran the source tree, so nothing that defaults under it - the
# lock, the log, the download path - moves for an install that leaves them unset.
#
# And the directory has to exist: rclone is run with storage_path() as its
# working directory, and Symfony's Process refuses to start at all when its cwd
# does not, so `move`, `clean --remote` and download's already-on-the-remote
# check died with "The provided cwd /app/storage does not exist" - after the
# backup had been downloaded, which is the worst point in the run to fall over.
ENV LARAVEL_STORAGE_PATH=/app/storage
RUN mkdir -p /app/storage

# Backup images are large and are streamed to disk rather than held in memory,
# but the API responses and progress handling still want headroom.
RUN echo "memory_limit=512M" > /usr/local/etc/php/conf.d/memory.ini

WORKDIR /app

# Named blbackup and placed in /app so that everything outside the image keeps
# working unchanged: `php blbackup cron` still names it, and a phar reads its .env
# from beside itself - /app/.env, which is where docker-compose.yml mounts it.
COPY --from=build /src/builds/blbackup /app/blbackup

# The phar has the version compiled in; this repeats it for the runtime override
# in AppServiceProvider, which an image running the source tree depended on and
# which does no harm now that the two agree.
ARG VERSION=
ENV BLBACKUP_VERSION=${VERSION}

# No command by default prints the command list and exits 0 - cron supplies the
# command it wants, which for a nightly run is:
#   docker compose run --rm blbackup php blbackup cron
CMD ["php", "blbackup"]
