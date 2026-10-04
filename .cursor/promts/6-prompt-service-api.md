# 6. Промпт: ImportService и HTTP API

> Новый чат. Шаги 2-5 проверены. Решение по времени конвертации xlsx принято (напишите его в начале чата!).

## Промпт

Прочитай `AGENTS.md`, раздел 5 (Pipeline). **Шаг 5 из 8: HTTP API импорта.** UI пока не делаем.

Контекст: решение по xlsx из предыдущего шага: **[ВСТАВЬТЕ СВОЁ РЕШЕНИЕ: одним запросом / возобновляемая конвертация / фоновый CLI-процесс]**.

**Создай `app/Services/ImportService.php`** — оркестратор, состояние хранится только в таблице `imports` (между HTTP-запросами в памяти ничего нет). Статусы: `uploaded → converting → importing → finalizing → done | failed`.

**Эндпоинты** (Phalcon 3 Micro, JSON, тонкие обработчики, логика в сервисах):

1. `POST /import/upload` — multipart, поле `file`. Валидации: файл есть, расширение `csv` или `xlsx`, размер > 0, ошибки PHP-загрузки (`UPLOAD_ERR_*`) превращаются в понятные сообщения. Сохрани файл в `storage/uploads/` под безопасным именем (не доверяй `getName()`), создай запись в `imports`, верни `{import_id, status, source_format}`.
2. `POST /import/step` — тело `import_id`. Выполняет **один шаг** с бюджетом ~20 секунд:
   - `uploaded` + xlsx → `converting` и конвертация (по принятому решению);
   - `uploaded` + csv или после конвертации → `importing`: вызывает `CsvImporter::run`;
   - файл закончился → `finalizing`: `markDuplicates`, подсчёт итоговой статистики, `finished_at`, `done`;
   - любая ошибка → `status=failed`, `error` с текстом; ответ с понятным JSON и HTTP-кодом.
   - Ответ: `{status, rows_read, rows_inserted, percent, elapsed_ms}`. `percent` считай по `byte_offset / filesize`.
   - Защита от гонок: повторный параллельный вызов `step` для одного импорта не должен портить данные (например, атомарный захват через `UPDATE ... WHERE status=...` или `GET_LOCK`; выбери и объясни).
3. `GET /import/{id}/stats` — итоговая статистика: всего строк, вставлено, дублей, строк с warnings, счётчики по кодам warnings, fatal-строки, длительность, формат исходного файла.
4. `GET /import/{id}/rows?page=1&per_page=50[&filter=duplicates|warnings]` — постраничный список из `requests` этого импорта: `data`, `page`, `per_page`, `total`, `pages`. `per_page` ограничь (1-200). Сортировка по `row_no`. Запрос должен быть быстрым на 100k строк (используй индекс `idx_import_row`, не `OFFSET` на миллионы — для теста OFFSET допустим, но отметь это).
5. `GET /` — пока может отдавать заглушку или текущую проверку версий.

**Правила Phalcon 3** (раздел 6 AGENTS.md): любой метод `Request`/`Response`/`Micro`, в котором ты не уверен, проверь в контейнере. Ответы JSON через `Phalcon\Http\Response`; не смешивай `echo` и `return`. Для Micro-обработчиков подключай контроллеры/сервисы через DI.

**Не делай:** UI, фронтенд-скрипты, авторизацию, очереди, `set_time_limit`.

**Критерии готовности** (проверь через `curl` из хоста и покажи вывод):
```bash
curl -F "file=@docs/База_даних_-_Аркуш1.csv" http://localhost:8080/import/upload
curl -X POST -d "import_id=1" http://localhost:8080/import/step     # повторять до status=done
curl http://localhost:8080/import/1/stats
curl "http://localhost:8080/import/1/rows?page=2&per_page=50"
```
- После цикла `step` в БД ровно 100 000 строк; такой же результат для xlsx.
- Цикл `step` занимает несколько запросов, каждый < 30 с (замерь `elapsed_ms` каждого).
- Загрузка файла неверного типа / без файла возвращает 4xx и JSON с ошибкой.
