# Phalcon 3 demo

## Вимоги
- Docker
- Docker Compose

## Запуск
1. Скопіюйте `.env.example` в `.env` та, за потреби, відредагуйте параметри БД.
2. Запустіть:
   ```bash
   make up
   ```
3. Перевірте сайт: http://localhost:8080

## Перевірка Phalcon
- Після запуску вивід сторінки показує версію Phalcon і PHP.
- Команда перевірки:
  ```bash
  ./scripts/check.sh
  ```

## Структура проєкту
- `Dockerfile` — збірка образу PHP 7.2 + Phalcon 3.4.5.
- `docker-compose.yml` — сервіси `web` і `db`.
- `app/public/index.php` — точка входу запиту.
- `app/.htaccess` — редірект на `index.php`.
- `app/vendor`, `app/cache`, `app/logs` — каталоги хоста, змонтовані відповідно в `/var/www/html/vendor`, `/var/www/html/cache`, `/var/www/html/logs`; зміни файлів із контейнера доступні в IDE та зберігаються на хості.
- `scripts/check.sh` — швидка перевірка готовності.

## Типові проблеми
- `apt` не працює через EOL Debian Buster: у `Dockerfile` вже використано archive.debian.org.
- Порт 8080 або 3306 зайнятий: змініть порт у `docker-compose.yml` або завершіть процеси.
- Права на файли: якщо в контейнері не працює запис, перевірте права в `./app`.
- Помилка збірки Phalcon: переконайтеся, що базовий образ `php:7.2-apache` і збірка виконується в `build/php7/64bits`.

## Зупинка та видалення
```bash
make down
make reset
```
`make down` зупиняє контейнери, `make reset` знищує томи з даними БД.
