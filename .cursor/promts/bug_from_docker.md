согласно README.md выполняю команду
```bash
docker compose exec web composer install
```
получаю ошибку:
```bash
Composer could not detect the root package (phalcon/request-import) version, defaulting to '1.0.0'. See https://getcomposer.org/root-version
Installing dependencies from lock file (including require-dev)
Verifying lock file contents can be installed on current platform.
Warning: The lock file is not up to date with the latest changes in composer.json. You may be getting outdated dependencies. It is recommended that you run `composer update` or `composer update <package name>`.
Nothing to install, update or remove
```
