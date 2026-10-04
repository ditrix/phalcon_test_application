# Phalcon lead import demo

This project implements a chunked import flow for request/leads data in a Phalcon 3 micro app. It accepts CSV and XLSX files, streams the data without loading the entire file into memory, persists rows in MySQL with batch inserts, and keeps the import resumable via stored byte offsets.

## Run locally

```bash
docker compose up -d --build web
docker compose exec web composer install
docker compose exec web php cli/import.php /var/www/html/docs/База_даних_-_Аркуш1.csv
docker compose exec web php cli/import.php /var/www/html/docs/База_даних.xlsx
```

Then open http://localhost:8080/ to use the single-page file uploader and progress UI.

## Manual UI test

1. Run the build command above after changing the Dockerfile.
2. Open http://localhost:8080/, select either provided `.csv` or `.xlsx` file, then click **Import file**.
3. Wait for the read/insert counters to reach 100000 and the result view to show 205 duplicates. Use the page buttons to check that the rows table loads.
4. If an HTTP/API error occurs, the page shows the error and offers a resume action for an existing import.
5. Check import-specific totals in MySQL with:

```sql
SELECT import_id, COUNT(*) AS total_rows,
       SUM(is_duplicate = 1) AS duplicates
FROM requests
GROUP BY import_id
ORDER BY import_id DESC;
```

## Import rules

- The DB schema is created from [migrations/001_init.sql](migrations/001_init.sql).
- `external_id` is not unique: duplicates are flagged with `is_duplicate = 1` in the finalizing step using a single SQL update.
- Bad values are never dropped; they become `NULL` and append warning codes such as `phone_invalid`, `email_invalid`, `budget_invalid`, and `date_invalid`.
- The importer is intentionally chunked and resumable to stay under the default 30-second runtime limit.
- There is no `set_time_limit()` or `ini_set('max_execution_time')` override anywhere in the import logic.

## Benchmarks

CLI validation against the provided files:

- CSV: rows=100000, duplicates=205, warnings=11298, elapsed_seconds=6.05, peak_memory=16 777 216
- XLSX: rows=100000, duplicates=205, warnings=11298, elapsed_seconds=16.51, peak_memory=80 437 248

The XLSX path converts the workbook to a temporary CSV using `ZipArchive` + `XMLReader`, then reuses the same streaming CSV importer. This keeps the memory footprint low and follows the required 30-second runtime budget.

## Notes

- `storage/uploads/` receives the uploaded file and the temporary converted CSV.
- The UI polls `/import/step` until the import reaches `done`, then shows summary cards and paginated rows from `/import/{id}/rows`.
- Row normalization is tested in [tests/RowNormalizerTest.php](tests/RowNormalizerTest.php) against the known edge cases from the dataset.
