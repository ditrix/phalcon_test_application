# AGENTS.md

Instructions for AI agents (Cursor) working in this repository.
Read this file fully before writing any code. If a rule here conflicts with your habits, this file wins.

> Communicate with the developer in **Russian**. Code, comments, identifiers, commit messages: **English**.
> The developer is an experienced **Laravel** dev, new to Phalcon. Briefly explain Phalcon-specific differences when they matter, don't explain generic PHP.

---

## 1. Task

Test assignment: build a **requests (leads) import page**.

> "Make a page for importing requests (the import file is provided).
> The task is done if ALL rows are written to the DB with the standard `max_execution_time=30`."

Acceptance criteria:

1. `SELECT COUNT(*) FROM requests` equals the number of data rows in the file (**100 000**) after import.
2. Works with default `max_execution_time=30`. **Never** call `set_time_limit()` / `ini_set('max_execution_time')` to cheat.
3. Accepts both `.csv` and `.xlsx` (xlsx is converted to CSV internally).
4. UI: one page, Tailwind CSS. File picker -> progress -> result (stats area + paginated table).

Provided files (in `/docs` or project root): `База_даних_-_Аркуш1.csv` (11 MB), `База_даних.xlsx` (26 MB).

---

## 2. Hard stack constraints (do NOT violate)

| Component | Version | Notes |
|---|---|---|
| PHP | **7.2.34** | No typed properties, no arrow functions `fn`, no `??=`, no `match`, no union types, no named args, no `str_contains`/`str_starts_with`. Nullable types `?string` and `void` are OK. |
| Phalcon | **3.x (cext)** | NOT Phalcon 4/5. See section 6. |
| MySQL | **5.7** | Strict mode ON by default. Out-of-range / bad values raise errors. `utf8mb4`. |
| Composer | 2.x | Use only libs compatible with PHP 7.2. Prefer **zero extra dependencies**. |
| Runtime | Docker Compose | services: `web` (:8080), `db` (:3306), `phpmyadmin` (:8081) |

Before adding any dependency, check `composer.json` `require.php` compatibility and ask the developer.

### Commands (run inside containers)

```bash
docker compose up -d
docker compose exec web php -v
docker compose exec web composer install
docker compose exec web php cli/import.php /path/to/file.csv   # CLI benchmark (create if missing)
docker compose exec db mysql -uphalcon -psecret phalcon_app
```

Env (see `public/index.php`): `DB_HOST=db DB_PORT=3306 DB_NAME=phalcon_app DB_USER=phalcon DB_PASS=secret`.

---

## 3. Data facts (verified against the provided files)

CSV columns, in order:
`external_id, created_at, first_name, last_name, phone, email, city, source, utm_campaign, product, budget_uah, status, manager, comment, next_contact_at`

- **100 000** data rows; **99 795** unique `external_id` -> **205 duplicate ids with DIFFERENT content** (not exact copies).
- `phone`: 205 rows are literal `#ERROR!`; ~800 rows have < 10 digits (lengths 3/5/6/7); valid ones are 12 digits starting with `380`. In xlsx some are floats like `380672341057.0`.
- `email`: ~820 invalid (`user@@ukr.net`, missing `@`, trailing `@`); 173 empty.
- `budget_uah`: 20 058 empty; formatted with thousand separators (`"23 700"`, may be NBSP); xlsx has `23700.0`; scientific notation like `1.2E+15` may appear (overflows INT).
- Empty: `first_name` 516, `utm_campaign` 9 055, `manager` 15 948, `comment` 28 531, `next_contact_at` 24 256.
- `status` values: new, in_progress, contacted, lost, qualified, won, proposal_sent, spam.
- **xlsx**: dates are Excel serial numbers (e.g. `45848.213125`), not strings.
- BOM: not present in the provided CSV, but **strip it anyway** (`\xEF\xBB\xBF`).
- Date format in CSV: `Y-m-d H:i:s`.

---

## 4. Database schema (source of truth: `migrations/`)

**Decision (change only if the developer says so):** `external_id` is **NOT unique**. We must store all rows. Duplicates are flagged, not dropped.

```sql
CREATE TABLE IF NOT EXISTS `requests` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `import_id` INT UNSIGNED NOT NULL,
  `row_no` INT UNSIGNED NOT NULL,                 -- 1-based data row number in the file
  `external_id` VARCHAR(32) NOT NULL,
  `created_at` DATETIME NOT NULL,
  `first_name` VARCHAR(100) DEFAULT NULL,
  `last_name` VARCHAR(100) DEFAULT NULL,
  `phone` VARCHAR(20) DEFAULT NULL,
  `email` VARCHAR(150) DEFAULT NULL,
  `city` VARCHAR(100) DEFAULT NULL,
  `source` VARCHAR(100) DEFAULT NULL,
  `utm_campaign` VARCHAR(100) DEFAULT NULL,
  `product` VARCHAR(100) DEFAULT NULL,
  `budget_uah` BIGINT UNSIGNED DEFAULT NULL,
  `status` VARCHAR(50) DEFAULT NULL,
  `manager` VARCHAR(100) DEFAULT NULL,
  `comment` TEXT DEFAULT NULL,
  `next_contact_at` DATETIME DEFAULT NULL,
  `is_duplicate` TINYINT(1) NOT NULL DEFAULT 0,
  `warnings` VARCHAR(255) DEFAULT NULL,           -- e.g. "phone_invalid,email_invalid"
  `imported_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_external_id` (`external_id`),
  KEY `idx_import_row` (`import_id`, `row_no`),
  KEY `idx_created_at` (`created_at`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `imports` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `original_name` VARCHAR(255) NOT NULL,
  `stored_path` VARCHAR(500) NOT NULL,            -- csv path actually being read
  `source_format` VARCHAR(10) NOT NULL,           -- csv | xlsx
  `status` VARCHAR(20) NOT NULL,                  -- uploaded|converting|importing|finalizing|done|failed
  `byte_offset` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `rows_read` INT UNSIGNED NOT NULL DEFAULT 0,
  `rows_inserted` INT UNSIGNED NOT NULL DEFAULT 0,
  `rows_duplicate` INT UNSIGNED NOT NULL DEFAULT 0,
  `rows_with_warnings` INT UNSIGNED NOT NULL DEFAULT 0,
  `warning_counts` TEXT DEFAULT NULL,             -- JSON {"phone_invalid":813,...}
  `error` TEXT DEFAULT NULL,
  `started_at` DATETIME DEFAULT NULL,
  `finished_at` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

`is_duplicate` rule: for each `external_id`, the row with the smallest `row_no` is the original (0); all others are duplicates (1).
Compute it in a single SQL `UPDATE ... JOIN` during the `finalizing` step, not in PHP memory (state does not survive between HTTP requests).

---

## 5. Import rules

### Golden rule
**Never drop or reject a row.** A bad field becomes `NULL` and a code is appended to `warnings`. Only a structurally broken row (wrong column count that cannot be repaired) is a hard error, and it must be counted and reported, not silently skipped.

### Normalization (pure functions in `RowNormalizer`, unit-testable, no DB/Phalcon inside)

| Field | Rule | Warning code |
|---|---|---|
| any string | `trim`, strip BOM, empty -> `NULL` | – |
| `external_id` | trim; required (empty -> `NULL` is not allowed, use hard error) | `external_id_missing` |
| `phone` | keep digits only (this removes `.0`); valid iff 12 digits and starts with `380`; else `NULL` | `phone_invalid` |
| `email` | `trim` + `strtolower`, `filter_var(..., FILTER_VALIDATE_EMAIL)`; else `NULL`. Do NOT try to "fix" `@@`. | `email_invalid` |
| `budget_uah` | remove spaces and NBSP (`\xC2\xA0`), cast via float for `E+` notation, must be integer >= 0 and <= BIGINT range; else `NULL` | `budget_invalid` |
| `created_at`, `next_contact_at` | accept `Y-m-d H:i:s` or Excel serial float; output `Y-m-d H:i:s`; invalid optional -> `NULL`; invalid `created_at` -> hard error (NOT NULL column) | `date_invalid` |
| `first_name` etc. | truncate to column length with `mb_substr` | – |

Excel serial -> datetime: `$ts = (int) round(($serial - 25569) * 86400); gmdate('Y-m-d H:i:s', $ts)`.

### Performance rules (the 30 s limit)

- Read CSV with `fopen` + `fgetcsv` streaming. **Never** `file()`, `file_get_contents()` or load the whole file into an array.
- Insert with **multi-row `INSERT`**, batches of 500-1000 rows, inside **one transaction per batch**. Use raw SQL via `$db->execute($sql, $bindParams)`. **No Phalcon ORM models in the import loop.**
- No per-row queries. No per-row `SELECT`.
- Free memory between batches (`$batch = []`). Keep peak memory low; do not assume `memory_limit` > 128M.
- **Chunked import over multiple HTTP requests:** one request processes rows until a **time budget of ~20 s** is reached (check `microtime(true)`), saves `byte_offset` (from `ftell`) and counters into `imports`, and returns progress JSON. The frontend loops until `status=done`. Resume with `fseek($h, $byte_offset)`.
- xlsx: **no PhpSpreadsheet** (too slow/heavy for 26 MB). Stream with `ZipArchive` + `XMLReader` over `xl/worksheets/sheet1.xml`, resolve `xl/sharedStrings.xml`, write rows with `fputcsv` to a temp CSV. Conversion is its own step (`converting`) and must also respect the time budget (make it resumable, or measure and document the timing). Then run the normal CSV path.
- Always benchmark on the real files from CLI first (`php cli/import.php`) and report timings to the developer.

### Pipeline

```
upload (POST /import/upload)  -> save file, create imports row
  [xlsx] converting            -> stream xlsx -> csv
  importing  (POST /import/step, repeated by JS) -> parse -> normalize -> batch insert
  finalizing                   -> mark duplicates (single UPDATE), compute stats
done                           -> GET /import/{id}/stats, GET /import/{id}/rows?page=N
```

---

## 6. Phalcon 3 rules (agents hallucinate Phalcon 4/5 here: be careful)

- Docs: https://docs.phalcon.io/3.4/en/ . **Always use the 3.4 docs. Do not copy examples from 4.x/5.x.**
- Phalcon is a **C extension**, not in `vendor/`. You cannot "go to definition" in vendor. If unsure whether a class/method exists in 3.4, say so and verify with `docker compose exec web php -r 'var_dump(method_exists("Phalcon\\Http\\Request","getUploadedFiles"));'` or a reflection one-liner. **Do not guess APIs.**
- Current app is `Phalcon\Mvc\Micro` with `Phalcon\Di\FactoryDefault`. Keep Micro for endpoints; render the page with `Phalcon\Mvc\View\Simple` or a plain PHP template. Do not migrate to full MVC unless asked.
- DB: `Phalcon\Db\Adapter\Pdo\Mysql` (service `db`). Useful: `execute()`, `fetchAll()`, `fetchOne()`, `begin()`, `commit()`, `rollback()`, `lastInsertId()`. `fetchAll` uses `Phalcon\Db::FETCH_ASSOC`.
- Uploads: `$this->request->hasFiles()`, `getUploadedFiles()`, `$file->moveTo($path)`, `getSize()`, `getName()`, `getExtension()` (verify in 3.4 before use).
- JSON responses: `Phalcon\Http\Response::setJsonContent()`, `setContentType('application/json')`, and return the response object from the handler.
- Micro handlers: either `return` a `Response` or `echo`; do not do both.
- Namespaces are `Phalcon\...` (not `Phalcon\Mvc\...` for everything). `Phalcon\Mvc\Model\Manager`, `Phalcon\Db\Column` etc. exist but are not needed here.
- Laravel habits that DO NOT apply: no Eloquent, no `request()`/`response()` helpers, no Blade, no facades, no `.env` auto-loading (use `getenv()`), no artisan, no service-container auto-resolve by type hint in the same way.

---

## 7. Project layout (target)

```
public/index.php          # front controller, DI, routes (keep thin)
app/
  Controllers/ImportController.php   # or closures in routes; thin, no business logic
  Services/
    ImportService.php     # orchestrates steps, state in `imports`
    CsvImporter.php       # streaming read + batch insert
    XlsxToCsvConverter.php
    RowNormalizer.php     # pure, no I/O
    ImportRepository.php  # SQL for imports/requests (raw SQL)
  views/index.php         # single page, Tailwind (CDN is fine), vanilla JS
migrations/001_init.sql
cli/import.php            # benchmark / manual run
tests/RowNormalizerTest.php   # PHPUnit 8 (PHP 7.2 compatible) or a plain assert script
storage/uploads/          # git-ignored
```

Autoload with PSR-4 via Composer (`App\` -> `app/`). Keep controllers thin and logic in services.

---

## 8. Frontend rules

- One page, Tailwind (CDN OK for a test task), vanilla JS (`fetch`), no build step.
- Three states: **1) file form** (drag & drop optional, accept `.csv,.xlsx`), **2) progress** (bar + counters read/inserted, updates from `/import/step` responses), **3) result**: stats cards (total, inserted, duplicates, rows with warnings, per-warning counts, elapsed time) and a **paginated table** (server-side pagination, e.g. 50 per page; show `warnings` badge and duplicate badge).
- Escape all output (XSS): data contains arbitrary text.
- Handle errors (network, server error JSON) and allow retry/resume.

---

## 9. Workflow rules for the agent

1. **Small vertical steps.** Propose a plan, then implement one step at a time; stop after each step so the developer can run it.
2. **After every change, give the exact command to verify it** (docker compose exec ...).
3. Before UI work, the **CLI import of the real CSV must be proven** (rows in DB = 100 000, time measured). Report: elapsed seconds, peak memory (`memory_get_peak_usage(true)`), rows, warnings.
4. Write a test or assert-script for `RowNormalizer` using the real problem values from section 3.
5. Don't refactor unrelated code. Don't add features not listed in section 1.
6. Don't invent Phalcon APIs; if unsure, say "not verified" and verify (section 6).
7. Don't change the schema silently. Schema changes = new file in `migrations/` + mention it.
8. Don't commit uploaded data files or `storage/uploads`.
9. If a requirement is ambiguous, ask **one** concise question instead of guessing.

## 10. Definition of done

- [ ] Import of the provided CSV: `COUNT(*) = 100000`, run through the UI, with default `max_execution_time=30` and no `set_time_limit` anywhere (`grep -rn "set_time_limit\|max_execution_time" app public cli` returns nothing).
- [ ] Import of the provided XLSX gives the same result.
- [ ] Problem rows from section 3 are stored with `NULL` + `warnings`, none dropped.
- [ ] Duplicates flagged: 205 rows with `is_duplicate=1`.
- [ ] Re-import / repeated click does not corrupt state (each upload = new `import_id`).
- [ ] README: how to run, how to import, design decisions (esp. duplicate policy and chunking), measured timings.
