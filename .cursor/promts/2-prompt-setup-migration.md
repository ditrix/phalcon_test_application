# 2. Промпт: окружение, структура проекта, миграции

> Новый чат в Cursor. Файлы `AGENTS.md` и правила из `1-rules.md` уже подключены.

## Промпт

Прочитай `AGENTS.md`. Мы начинаем проект с нуля поверх рабочего Docker-окружения (Phalcon 3, PHP 7.2, MySQL 5.7). Сейчас есть только `public/index.php` с проверкой версии и подключения к БД.

**Задача — шаг 1 из 8: основа проекта.**

1. **Лимиты PHP.** Дефолтные `upload_max_filesize=2M` и `post_max_size=8M` не пропустят файлы 11 МБ (CSV) и 26 МБ (XLSX). Найди, как собран образ `web` (Dockerfile, docker-compose), и добавь отдельный ini-файл (например `docker/php/uploads.ini`) с `upload_max_filesize=64M` и `post_max_size=64M`. **`max_execution_time` не трогай**, он должен остаться 30. Проверь, есть ли перед PHP nginx, и если есть — `client_max_body_size`. Покажи, как применить (`docker compose up -d --build`) и как проверить (`php -i | grep -E "upload_max|post_max|max_execution"`).
2. **Структура каталогов** из раздела 7 AGENTS.md: `app/Services`, `app/Controllers` (если нужны), `app/views`, `migrations`, `cli`, `tests`, `storage/uploads` (с `.gitkeep`, содержимое в `.gitignore`).
3. **Composer:** создай или поправь `composer.json` — PSR-4 `App\` → `app/`, `require.php: >=7.2`. Без сторонних пакетов. `composer dump-autoload` выполни внутри контейнера и подключи `vendor/autoload.php` в `public/index.php`.
4. **Миграции:** файл `migrations/001_init.sql` с двумя таблицами `requests` и `imports` **ровно по схеме из раздела 4 AGENTS.md**. Напиши простой раннер `cli/migrate.php` (PDO или Phalcon Db — на твой выбор, объясни выбор): выполняет все `migrations/*.sql` по порядку, ведёт таблицу `migrations` с уже применёнными, повторный запуск ничего не ломает.
5. **Конфиг БД:** вынеси создание подключения в одно место, которое используют и `public/index.php`, и CLI-скрипты (чтобы не дублировать код).

**Не делай:** никакой логики импорта, UI, нормализации. Не меняй главную страницу с проверкой версии, кроме подключения autoload.

**Критерии готовности:**
- `docker compose exec web php -i | grep -E "upload_max_filesize|post_max_size|max_execution_time"` показывает 64M / 64M / 30.
- `docker compose exec web php cli/migrate.php` создаёт таблицы; повторный запуск пишет «nothing to migrate».
- В phpMyAdmin (http://localhost:8081) видны `requests`, `imports`, `migrations`.
- `http://localhost:8080/` по-прежнему показывает версии и «DB connection OK».

В конце выдай ответ по формату из правил (что сделано / как проверить / риски / следующий шаг).
