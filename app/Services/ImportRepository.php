<?php

namespace App\Services;

class ImportRepository
{
    public function createImport($db, $originalName, $storedPath, $sourceFormat)
    {
        $sourceFormat = strtolower((string) $sourceFormat);
        $db->execute(
            'INSERT INTO imports (original_name, stored_path, source_format, status, started_at) VALUES (?, ?, ?, ?, NOW())',
            [$originalName, $storedPath, $sourceFormat, 'uploaded']
        );

        return (int) $db->lastInsertId();
    }

    public function updateImport($db, $importId, array $data)
    {
        if (empty($data)) {
            return;
        }

        $assignments = array();
        $values = array();
        foreach ($data as $field => $value) {
            $assignments[] = $field . ' = ?';
            $values[] = $value;
        }

        $values[] = (int) $importId;
        $db->execute('UPDATE imports SET ' . implode(', ', $assignments) . ' WHERE id = ?', $values);
    }

    public function getImport($db, $importId)
    {
        $row = $db->fetchOne('SELECT * FROM imports WHERE id = ?', \Phalcon\Db::FETCH_ASSOC, [(int) $importId]);
        if ($row === false) {
            return null;
        }

        if (!empty($row['warning_counts'])) {
            $row['warning_counts_json'] = json_decode($row['warning_counts'], true);
        } else {
            $row['warning_counts_json'] = array();
        }

        return $row;
    }

    public function getStats($db, $importId)
    {
        $sql = 'SELECT COUNT(*) AS total_rows, SUM(CASE WHEN is_duplicate = 1 THEN 1 ELSE 0 END) AS duplicates, SUM(CASE WHEN warnings IS NOT NULL AND warnings <> "" THEN 1 ELSE 0 END) AS rows_with_warnings FROM requests WHERE import_id = ?';
        $row = $db->fetchOne($sql, \Phalcon\Db::FETCH_ASSOC, [(int) $importId]);
        $row = array_merge(array('total_rows' => 0, 'duplicates' => 0, 'rows_with_warnings' => 0, 'warning_counts' => array()), $row ?: array());
        $importRow = $db->fetchOne('SELECT rows_inserted FROM imports WHERE id = ?', \Phalcon\Db::FETCH_ASSOC, [(int) $importId]);
        $row['rows_inserted'] = (int) ($importRow['rows_inserted'] ?? 0);

        $warningRows = $db->fetchAll('SELECT warnings FROM requests WHERE import_id = ? AND warnings IS NOT NULL AND warnings <> ""', \Phalcon\Db::FETCH_ASSOC, [(int) $importId]);
        $warningCounts = array();
        foreach ($warningRows as $warningRow) {
            $codes = explode(',', (string) $warningRow['warnings']);
            foreach ($codes as $code) {
                if ($code === '') {
                    continue;
                }
                if (!isset($warningCounts[$code])) {
                    $warningCounts[$code] = 0;
                }
                $warningCounts[$code]++;
            }
        }

        $row['warning_counts'] = $warningCounts;
        $row['total_rows'] = (int) $row['total_rows'];
        $row['duplicates'] = (int) $row['duplicates'];
        $row['rows_with_warnings'] = (int) $row['rows_with_warnings'];

        return $row;
    }

    public function getRows($db, $importId, $page, $limit = 50)
    {
        $page = max(1, (int) $page);
        $limit = max(1, (int) $limit);
        $offset = ($page - 1) * $limit;
        $sql = 'SELECT * FROM requests WHERE import_id = ? ORDER BY id ASC LIMIT ' . (int) $offset . ', ' . (int) $limit;

        $rows = $db->fetchAll(
            $sql,
            \Phalcon\Db::FETCH_ASSOC,
            [(int) $importId]
        );

        $count = $db->fetchOne('SELECT COUNT(*) AS total FROM requests WHERE import_id = ?', \Phalcon\Db::FETCH_ASSOC, [(int) $importId]);

        return array(
            'rows' => $rows,
            'total' => (int) ($count['total'] ?? 0),
            'page' => $page,
            'limit' => $limit,
        );
    }

    public function finalizeDuplicates($db, $importId)
    {
        $db->execute(
            'UPDATE requests r
            JOIN (
                SELECT external_id, MIN(row_no) AS min_row_no
                FROM requests
                WHERE import_id = ?
                GROUP BY external_id
            ) x ON x.external_id = r.external_id AND r.import_id = ? AND r.row_no > x.min_row_no
            SET r.is_duplicate = 1',
            [(int) $importId, (int) $importId]
        );

        $duplicateRow = $db->fetchOne(
            'SELECT COUNT(*) AS total FROM requests WHERE import_id = ? AND is_duplicate = 1',
            \Phalcon\Db::FETCH_ASSOC,
            [(int) $importId]
        );

        $db->execute(
            'UPDATE imports SET rows_duplicate = ?, status = ?, finished_at = NOW() WHERE id = ?',
            [(int) ($duplicateRow['total'] ?? 0), 'done', (int) $importId]
        );

        return (int) ($duplicateRow['total'] ?? 0);
    }

    public function updateWarningSummary($db, $importId, array $warningCounts, $rowsWithWarnings)
    {
        $warningCountsJson = json_encode($warningCounts, JSON_UNESCAPED_UNICODE);
        $db->execute(
            'UPDATE imports SET warning_counts = ?, rows_with_warnings = ? WHERE id = ?',
            [$warningCountsJson, (int) $rowsWithWarnings, (int) $importId]
        );
    }
}
