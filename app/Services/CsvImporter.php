<?php

namespace App\Services;

class CsvImporter
{
    private $expectedHeaders = array(
        'external_id',
        'created_at',
        'first_name',
        'last_name',
        'phone',
        'email',
        'city',
        'source',
        'utm_campaign',
        'product',
        'budget_uah',
        'status',
        'manager',
        'comment',
        'next_contact_at',
    );

    public function validateFile($filePath)
    {
        $handle = fopen($filePath, 'r');
        if ($handle === false) {
            throw new \RuntimeException('Unable to open import file: ' . $filePath);
        }

        try {
            $header = $this->normalizeHeader(fgetcsv($handle));
            if ($header !== $this->expectedHeaders) {
                throw new \RuntimeException('The import file headers do not match the expected requests columns');
            }
        } finally {
            fclose($handle);
        }
    }

    public function importFile($db, $filePath)
    {
        $handle = fopen($filePath, 'r');
        if ($handle === false) {
            throw new \RuntimeException('Unable to open import file: ' . $filePath);
        }

        $header = $this->normalizeHeader(fgetcsv($handle));
        if ($header !== $this->expectedHeaders) {
            fclose($handle);
            throw new \RuntimeException('The import file headers do not match the expected requests columns');
        }

        $rowNumber = 0;
        $rowsInserted = 0;
        $duplicateCount = 0;
        $rowsWithWarnings = 0;
        $warningCounts = array();
        $seenExternalIds = array();
        $batch = array();

        try {
            while (($values = fgetcsv($handle)) !== false) {
                if ($values === array(null)) {
                    continue;
                }

                $rowNumber++;
                if (count($values) !== count($header)) {
                    throw new \RuntimeException('Invalid column count at data row ' . $rowNumber);
                }

                try {
                    $normalized = RowNormalizer::normalizeRow($header, $values);
                } catch (\Throwable $e) {
                    throw new \RuntimeException($e->getMessage() . ' at data row ' . $rowNumber, 0, $e);
                }

                $externalId = $normalized['external_id'];
                $rowWarnings = array();
                if (isset($seenExternalIds[$externalId])) {
                    $duplicateCount++;
                    $rowWarnings[] = 'duplicate external_id';
                } else {
                    $seenExternalIds[$externalId] = true;
                }

                if (strpos($normalized['warnings'], 'phone_invalid') !== false) {
                    $rowWarnings[] = 'wrong phone';
                }
                if (strpos($normalized['warnings'], 'email_invalid') !== false) {
                    $rowWarnings[] = 'wrong email';
                }

                if ($normalized['warnings'] !== '') {
                    $rowsWithWarnings++;
                    foreach (explode(',', $normalized['warnings']) as $warning) {
                        if (!isset($warningCounts[$warning])) {
                            $warningCounts[$warning] = 0;
                        }
                        $warningCounts[$warning]++;
                    }
                }

                $batch[] = array(
                    $normalized['external_id'],
                    $normalized['created_at'],
                    $normalized['first_name'],
                    $normalized['last_name'],
                    $normalized['phone'],
                    $normalized['email'],
                    $normalized['city'],
                    $normalized['source'],
                    $normalized['utm_campaign'],
                    $normalized['product'],
                    $normalized['budget_uah'],
                    $normalized['status'],
                    $normalized['manager'],
                    $normalized['comment'],
                    $normalized['next_contact_at'],
                    empty($rowWarnings) ? '-' : implode(', ', $rowWarnings),
                );

                if (count($batch) >= 1000) {
                    $this->persistBatch($db, $batch);
                    $rowsInserted += count($batch);
                    $batch = array();
                }
            }

            if (!empty($batch)) {
                $this->persistBatch($db, $batch);
                $rowsInserted += count($batch);
                $batch = array();
            }
        } finally {
            fclose($handle);
        }

        return array(
            'total_rows' => $rowNumber,
            'rows_inserted' => $rowsInserted,
            'duplicates' => $duplicateCount,
            'rows_with_warnings' => $rowsWithWarnings,
            'warning_counts' => $warningCounts,
        );
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

    private function persistBatch($db, array $batch)
    {
        $placeholders = array();
        $values = array();

        foreach ($batch as $row) {
            $placeholders[] = '(' . implode(', ', array_fill(0, 16, '?')) . ')';
            foreach ($row as $value) {
                $values[] = $value;
            }
        }

        $sql = 'INSERT INTO requests (external_id, created_at, first_name, last_name, phone, email, city, source, utm_campaign, product, budget_uah, status, manager, comment, next_contact_at, warning) VALUES ' . implode(', ', $placeholders);

        $db->begin();
        try {
            $db->execute($sql, $values);
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollback();
            throw $e;
        }
    }
}
