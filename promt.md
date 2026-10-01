

You are working in an empty project directory. Goal: build a reproducible Docker environment
with PHP 7.2 + Phalcon 3 (cphalcon v3.4.5), plus a short manual in Ukrainian.

## Constraints
- Base image: php:7.2-apache (Debian Buster). Buster is EOL, so in the Dockerfile
  rewrite apt sources to archive.debian.org (and disable Valid-Until check) BEFORE apt-get update.
- Phalcon 3.4.x supports PHP 5.5–7.3 only. Do not use Phalcon 4/5 or PHP > 7.3.
- No cPanel, no systemd, no privileged containers.
- No secrets in the repo: DB credentials go to .env (provide .env.example), add .gitignore.

## Tasks
1. Dockerfile:
   - php:7.2-apache, install build deps (git, gcc, make, re2c, autoconf, libpcre3-dev, unzip, libzip-dev etc.)
   - install PHP extensions: pdo_mysql, mysqli, mbstring, gd, zip, intl, opcache (as needed)
   - clone phalcon/cphalcon at tag v3.4.5 (depth 1), build from build/php7/64bits with
     phpize / configure --enable-phalcon / make -j$(nproc) / make install
   - enable via /usr/local/etc/php/conf.d/docker-php-ext-phalcon.ini (`extension=phalcon.so`)
   - enable apache mod_rewrite, set DocumentRoot to /var/www/html/public with AllowOverride All
   - remove sources and build deps afterwards to keep the image small (multi-stage build if convenient)
   - install composer
2. docker-compose.yml:
   - service `web` (build from Dockerfile), port 8080:80, mount ./app -> /var/www/html
   - service `db` (mysql:5.7 or mariadb:10.3), credentials from .env, named volume for data
   - optional `phpmyadmin` on 8081
   - healthchecks, restart: unless-stopped
3. Demo app in ./app:
   - minimal Phalcon 3 MVC (or Phalcon\Mvc\Micro) with public/index.php, .htaccess (rewrite to index.php),
     one controller that prints Phalcon\Version::get(), PHP version and a successful DB connection check
4. Makefile: up, down, logs, shell, rebuild, reset (down -v)
5. scripts/check.sh: verify
   - `docker compose exec web php -m | grep phalcon`
   - `php -v` shows 7.2.x
   - http://localhost:8080 returns 200 and contains the Phalcon version
   - DB connection works
   Run it and show results. If something fails — debug and fix, don't just report.
6. Write MANUAL.uk.md — a SHORT manual in UKRAINIAN (max ~1 page):
   вимоги (Docker, Docker Compose), запуск (`make up`), перевірка Phalcon,
   структура проєкту, типові проблеми (apt/Buster архів, порти зайняті, права на файли, 
   помилка збирання розширення), як зупинити/видалити.

## Rules
- Work step by step, verify each step by running commands, show only concise output.
- Don't ask questions unless blocked; list assumptions in the final summary.
- At the end print a summary: what was created, how to start, what is unverified.