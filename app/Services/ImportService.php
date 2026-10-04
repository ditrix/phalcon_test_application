<?php

namespace App\Services;

class ImportService
{
    protected $db;
    protected $repository;
    protected $converter;
    protected $csvImporter;

    public function __construct($db)
    {
        $this->db = $db;
        $this->repository = new ImportRepository();
        $this->converter = new XlsxToCsvConverter();
        $this->csvImporter = new CsvImporter();
    }

    public function uploadFile($uploadedFile)
    {
        $originalName = $uploadedFile->getName();
        $extension = strtolower((string) $uploadedFile->getExtension());
        if (!in_array($extension, array('csv', 'xlsx'), true)) {
            throw new \InvalidArgumentException('Unsupported file type. Only .csv and .xlsx are accepted.');
        }

        $directory = __DIR__ . '/../storage/uploads';
        if (!is_dir($directory)) {
            if (!mkdir($directory, 0777, true) && !is_dir($directory)) {
                throw new \RuntimeException('Unable to create upload directory');
            }
        }

        $safeName = preg_replace('/[^A-Za-z0-9_.-]+/', '_', pathinfo($originalName, PATHINFO_FILENAME));
        $storedName = $safeName . '_' . uniqid('', true) . '.' . $extension;
        $storedPath = $directory . '/' . $storedName;

        $uploadedFile->moveTo($storedPath);

        $importId = $this->repository->createImport($this->db, $originalName, $storedPath, $extension);
        $this->repository->updateImport($this->db, $importId, array('status' => 'uploaded'));

        if ($extension === 'xlsx') {
            $convertedPath = $directory . '/' . pathinfo($storedName, PATHINFO_FILENAME) . '.csv';
            $this->repository->updateImport($this->db, $importId, array('status' => 'converting', 'stored_path' => $storedPath));
            $converted = $this->converter->convert($storedPath, $convertedPath);
            $this->repository->updateImport($this->db, $importId, array('stored_path' => $converted, 'status' => 'importing'));
            return array('ok' => true, 'import_id' => $importId, 'status' => 'importing');
        }

        $this->repository->updateImport($this->db, $importId, array('status' => 'importing'));

        return array('ok' => true, 'import_id' => $importId, 'status' => 'importing');
    }

    public function processStep($importId)
    {
        $import = $this->repository->getImport($this->db, $importId);
        if ($import === null) {
            return array('ok' => false, 'status' => 'failed', 'error' => 'Import not found');
        }

        if ($import['status'] === 'done') {
            return array(
                'ok' => true,
                'status' => 'done',
                'import_id' => (int) $importId,
                'stats' => $this->getStats((int) $importId),
            );
        }

        if ($import['status'] === 'finalizing') {
            return $this->finalizeImport((int) $importId);
        }

        $result = $this->csvImporter->processChunk(
            $this->db,
            (int) $importId,
            $import['stored_path'],
            (int) ($import['byte_offset'] ?? 0),
            (int) ($import['rows_read'] ?? 0),
            (int) ($import['rows_inserted'] ?? 0),
            20
        );

        $status = $result['done'] ? 'finalizing' : 'importing';
        $this->repository->updateImport(
            $this->db,
            $importId,
            array(
                'status' => $status,
                'byte_offset' => (int) $result['byte_offset'],
                'rows_read' => (int) $result['rows_read'],
                'rows_inserted' => (int) $result['rows_inserted'],
                'warning_counts' => json_encode($result['warning_counts'], JSON_UNESCAPED_UNICODE),
            )
        );

        if ($result['done']) {
            return $this->finalizeImport((int) $importId);
        }

        return array(
            'ok' => true,
            'status' => 'importing',
            'import_id' => (int) $importId,
            'rows_read' => (int) $result['rows_read'],
            'rows_inserted' => (int) $result['rows_inserted'],
            'byte_offset' => (int) $result['byte_offset'],
            'warning_counts' => $result['warning_counts'],
        );
    }

    public function importFile($path)
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $sourceFile = $path;
        if (!file_exists($sourceFile)) {
            throw new \RuntimeException('Import file not found: ' . $path);
        }

        if (!in_array($extension, array('csv', 'xlsx'), true)) {
            throw new \InvalidArgumentException('Unsupported file type for CLI import');
        }

        $importId = $this->repository->createImport($this->db, basename($path), $sourceFile, $extension);
        if ($extension === 'xlsx') {
            $converted = __DIR__ . '/../storage/uploads/' . md5($path . microtime(true)) . '.csv';
            $this->repository->updateImport($this->db, $importId, array('status' => 'converting', 'stored_path' => $sourceFile));
            $sourceFile = $this->converter->convert($path, $converted);
            $this->repository->updateImport($this->db, $importId, array('stored_path' => $sourceFile, 'status' => 'importing'));
        } else {
            $this->repository->updateImport($this->db, $importId, array('status' => 'importing', 'stored_path' => $sourceFile));
        }

        while (true) {
            $step = $this->processStep($importId);
            if (!isset($step['status']) || $step['status'] === 'done') {
                break;
            }
        }

        return $this->getStats($importId);
    }

    public function getStats($importId)
    {
        $stats = $this->repository->getStats($this->db, (int) $importId);
        $import = $this->repository->getImport($this->db, (int) $importId);
        $stats['import_id'] = (int) $importId;
        $stats['status'] = $import['status'];
        $stats['original_name'] = $import['original_name'];
        $stats['source_format'] = $import['source_format'];

        return $stats;
    }

    public function getRows($importId, $page = 1)
    {
        return $this->repository->getRows($this->db, (int) $importId, (int) $page, 50);
    }

    public function finalizeImport($importId)
    {
        $this->repository->updateImport($this->db, $importId, array('status' => 'finalizing'));
        $duplicateCount = $this->repository->finalizeDuplicates($this->db, (int) $importId);

        $stats = $this->repository->getStats($this->db, (int) $importId);
        $import = $this->repository->getImport($this->db, (int) $importId);
        $warningCounts = array();
        if (!empty($import['warning_counts'])) {
            $warningCounts = json_decode($import['warning_counts'], true);
        }
        if (!is_array($warningCounts)) {
            $warningCounts = array();
        }

        $rowsWithWarnings = 0;
        $warningRows = $this->db->fetchAll('SELECT warnings FROM requests WHERE import_id = ? AND warnings IS NOT NULL AND warnings <> ""', \Phalcon\Db::FETCH_ASSOC, [(int) $importId]);
        foreach ($warningRows as $row) {
            if ($row['warnings'] !== '') {
                $rowsWithWarnings++;
            }
        }

        $this->repository->updateWarningSummary($this->db, (int) $importId, $warningCounts, $rowsWithWarnings);

        $finalStats = $this->repository->getStats($this->db, (int) $importId);
        $finalStats['duplicate_count'] = $duplicateCount;
        $finalStats['warning_counts'] = $warningCounts;
        $finalStats['status'] = 'done';

        $this->repository->updateImport($this->db, (int) $importId, array('rows_duplicate' => $duplicateCount, 'rows_with_warnings' => $rowsWithWarnings, 'status' => 'done'));

        return array(
            'ok' => true,
            'status' => 'done',
            'import_id' => (int) $importId,
            'stats' => $finalStats,
        );
    }
}
