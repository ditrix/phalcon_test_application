<?php

namespace App\Services;

class CsvImporter
{
    public function processChunk($db, $importId, $filePath, $startOffset, $rowsReadSoFar, $rowsInsertedSoFar, $timeBudgetSeconds = 20)
    {
        $deadline = microtime(true) + $timeBudgetSeconds;
        $handle = fopen($filePath, 'r');
        if ($handle === false) {
            throw new \RuntimeException('Unable to open import file: ' . $filePath);
        }

        $header = $this->readHeader($handle, $startOffset);
        $rowNumber = (int) $rowsReadSoFar + 1;
        $rowsReadTotal = (int) $rowsReadSoFar;
        $rowsInsertedTotal = (int) $rowsInsertedSoFar;
        $warningCounts = array();
        $batch = array();
        $done = false;

        while (!feof($handle) && microtime(true) < $deadline) {
            $values = fgetcsv($handle);
            if ($values === false || $values === array(null)) {
                $done = true;
                break;
            }

            $rowsReadTotal++;
            try {
                $normalized = RowNormalizer::normalizeRow($header, $values);
                $rowsInsertedTotal++;
                $this->accumulateWarnings($warningCounts, $normalized['warnings']);

                $batch[] = array(
                    'row_no' => $rowNumber,
                    'external_id' => $normalized['external_id'],
                    'created_at' => $normalized['created_at'],
                    'first_name' => $normalized['first_name'],
                    'last_name' => $normalized['last_name'],
                    'phone' => $normalized['phone'],
                    'email' => $normalized['email'],
                    'city' => $normalized['city'],
                    'source' => $normalized['source'],
                    'utm_campaign' => $normalized['utm_campaign'],
                    'product' => $normalized['product'],
                    'budget_uah' => $normalized['budget_uah'],
                    'status' => $normalized['status'],
                    'manager' => $normalized['manager'],
                    'comment' => $normalized['comment'],
                    'next_contact_at' => $normalized['next_contact_at'],
                    'warnings' => $normalized['warnings'],
                );
            } catch (\Throwable $e) {
                $this->recordHardError($db, $importId, $e->getMessage(), $rowNumber);
            }

            $rowNumber++;

            if (count($batch) >= 500) {
                $this->persistBatch($db, $importId, $batch);
                $batch = array();
            }

            if ($rowsInsertedTotal > $rowsReadSoFar + 999) {
                break;
            }
        }

        if (!empty($batch)) {
            $this->persistBatch($db, $importId, $batch);
        }

        $byteOffset = ftell($handle);
        if ($byteOffset === false) {
            $byteOffset = $startOffset;
        }

        fclose($handle);

        $done = $done || feof(fopen($filePath, 'r'));

        return array(
            'done' => $done,
            'rows_read' => $rowsReadTotal,
            'rows_inserted' => $rowsInsertedTotal,
            'byte_offset' => (int) $byteOffset,
            'warning_counts' => $warningCounts,
        );
    }

    private function readHeader($handle, $startOffset)
    {
        if ($startOffset > 0) {
            fseek($handle, 0);
            $header = fgetcsv($handle);
            fseek($handle, $startOffset);
            return $this->normalizeHeader($header);
        }

        $header = fgetcsv($handle);
        return $this->normalizeHeader($header);
    }

    private function normalizeHeader($header)
    {
        if ($header === false) {
            return array();
        }

        foreach ($header as $index => $value) {
            $header[$index] = trim(str_replace("\xEF\xBB\xBF", '', (string) $value));
        }

        return $header;
    }

    private function persistBatch($db, $importId, array $batch)
    {
        if (empty($batch)) {
            return;
        }

        $parts = array();
        $values = array();
        foreach ($batch as $row) {
            $parts[] = '(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)';
            $values[] = (int) $importId;
            $values[] = (int) $row['row_no'];
            $values[] = $row['external_id'];
            $values[] = $row['created_at'];
            $values[] = $row['first_name'];
            $values[] = $row['last_name'];
            $values[] = $row['phone'];
            $values[] = $row['email'];
            $values[] = $row['city'];
            $values[] = $row['source'];
            $values[] = $row['utm_campaign'];
            $values[] = $row['product'];
            $values[] = $row['budget_uah'];
            $values[] = $row['status'];
            $values[] = $row['manager'];
            $values[] = $row['comment'];
            $values[] = $row['next_contact_at'];
            $values[] = $row['warnings'];
        }

        $sql = 'INSERT INTO requests (import_id, row_no, external_id, created_at, first_name, last_name, phone, email, city, source, utm_campaign, product, budget_uah, status, manager, comment, next_contact_at, warnings) VALUES ' . implode(', ', $parts);

        $db->begin();
        try {
            $db->execute($sql, $values);
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollback();
            throw $e;
        }
    }

    private function accumulateWarnings(array &$warningCounts, $warnings)
    {
        if ($warnings === '') {
            return;
        }

        foreach (explode(',', $warnings) as $warning) {
            if ($warning === '') {
                continue;
            }
            if (!isset($warningCounts[$warning])) {
                $warningCounts[$warning] = 0;
            }
            $warningCounts[$warning]++;
        }
    }

    private function recordHardError($db, $importId, $message, $rowNumber)
    {
        $errorText = $message . ' at row ' . $rowNumber;
        $existing = $db->fetchOne('SELECT error FROM imports WHERE id = ?', \Phalcon\Db::FETCH_ASSOC, [(int) $importId]);
        $error = $existing['error'] ?? '';
        if ($error === '') {
            $error = $errorText;
        } else {
            $error = substr($error . '; ' . $errorText, 0, 500);
        }

        $db->execute('UPDATE imports SET error = ? WHERE id = ?', [substr($error, 0, 500), (int) $importId]);
    }
}
