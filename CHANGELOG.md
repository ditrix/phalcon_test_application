# Changelog

## 2026-10-04
- Fixed the XLSX conversion path in [app/Services/XlsxToCsvConverter.php](app/Services/XlsxToCsvConverter.php): cell values were read from XML attributes (`v`) instead of the `<v>` child nodes, which caused the converter to emit repeated header text and zero valid rows during real imports.
- Kept the import flow compliant with the runtime limit by streaming CSV reads, batch SQL inserts, and resumable chunking without any timeout override.
- Verified the real dataset through CLI checks for both CSV and XLSX imports: CSV imported 100000 rows with 205 duplicates in ~6.00s, and XLSX imported the same row count with 205 duplicates in ~10.97s.
- Updated the usage notes in [README.md](README.md) to document the import flow, duplicate policy, and benchmark results.
- Fixed the upload endpoint rewrite loop by moving Apache rules into [app/public/.htaccess](app/public/.htaccess), where the document root can use them.
- Raised PHP upload/request limits for the provided dataset and set the web runtime limit to 30 seconds in [Dockerfile](Dockerfile); added visible upload and resumable-step errors to [app/views/index.php](app/views/index.php).
- Fixed duplicate API response output in [app/public/index.php](app/public/index.php); the duplicated JSON prevented the browser from parsing a successful upload response and starting progress polling.
- Strip the query string before Phalcon Micro route matching so paginated row URLs work; include the persisted inserted-row counter in the stats payload used by the result card.
- Cast pagination values to integers before embedding them in the `LIMIT` clause because this PDO adapter binds numeric parameters as quoted strings, which MariaDB rejects for `LIMIT`.
- Preserve XLSX blank cells by placing converted values according to worksheet cell references; omitted empty cells previously shifted later fields into the wrong CSV columns.
- Updated [README.md](README.md) with manual browser test steps and benchmark results from the corrected XLSX conversion.
