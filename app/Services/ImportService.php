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
        if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
            throw new \RuntimeException('Unable to create upload directory');
        }

        $safeName = preg_replace('/[^A-Za-z0-9_.-]+/', '_', pathinfo($originalName, PATHINFO_FILENAME));
        $storedPath = $directory . '/' . $safeName . '_' . uniqid('', true) . '.' . $extension;
        if (!$uploadedFile->moveTo($storedPath)) {
            throw new \RuntimeException('Unable to save uploaded file');
        }

        $convertedPath = null;
        $start = microtime(true);
        try {
            $sourcePath = $storedPath;
            if ($extension === 'xlsx') {
                $convertedPath = $directory . '/' . $safeName . '_' . uniqid('', true) . '.csv';
                $sourcePath = $this->converter->convert($storedPath, $convertedPath);
            }

            $this->csvImporter->validateFile($sourcePath);
            $this->repository->replaceRequests($this->db);
            $stats = $this->csvImporter->importFile($this->db, $sourcePath);
            $stats['elapsed_seconds'] = round(microtime(true) - $start, 2);
            $stats['original_name'] = $originalName;
            $stats['source_format'] = $extension;

            return array_merge(array('ok' => true), $stats);
        } finally {
            if (is_file($storedPath)) {
                unlink($storedPath);
            }
            if ($convertedPath !== null && is_file($convertedPath)) {
                unlink($convertedPath);
            }
        }
    }

    public function importFile($path)
    {
        if (!is_file($path)) {
            throw new \RuntimeException('Import file not found: ' . $path);
        }

        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if (!in_array($extension, array('csv', 'xlsx'), true)) {
            throw new \InvalidArgumentException('Unsupported file type for CLI import');
        }

        $start = microtime(true);
        $sourcePath = $path;
        $convertedPath = null;

        try {
            if ($extension === 'xlsx') {
                $directory = __DIR__ . '/../storage/uploads';
                if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
                    throw new \RuntimeException('Unable to create upload directory');
                }
                $convertedPath = $directory . '/' . uniqid('cli_', true) . '.csv';
                $sourcePath = $this->converter->convert($path, $convertedPath);
            }

            $this->csvImporter->validateFile($sourcePath);
            $this->repository->replaceRequests($this->db);
            $stats = $this->csvImporter->importFile($this->db, $sourcePath);
            $stats['elapsed_seconds'] = round(microtime(true) - $start, 2);

            return $stats;
        } finally {
            if ($convertedPath !== null && is_file($convertedPath)) {
                unlink($convertedPath);
            }
        }
    }

    public function getRows($page = 1)
    {
        return $this->repository->getRows($this->db, $page, 50);
    }
}
