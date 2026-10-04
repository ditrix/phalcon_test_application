<?php

namespace App\Services;

class XlsxToCsvConverter
{
    public function convert($xlsxPath, $outputCsvPath)
    {
        $zip = new \ZipArchive();
        $opened = $zip->open($xlsxPath);
        if ($opened !== true) {
            throw new \RuntimeException('Unable to open XLSX archive: ' . $xlsxPath);
        }

        $sharedStrings = $this->readSharedStrings($zip);
        $sheetFile = $this->findSheetPath($zip);
        if ($sheetFile === null) {
            $zip->close();
            throw new \RuntimeException('Worksheet not found in XLSX archive');
        }

        $sheetXml = $zip->getFromName($sheetFile);
        $zip->close();
        if ($sheetXml === false) {
            throw new \RuntimeException('Unable to read worksheet XML');
        }

        $xml = new \XMLReader();
        $xml->xml($sheetXml);

        $handle = fopen($outputCsvPath, 'w');
        if ($handle === false) {
            throw new \RuntimeException('Unable to create temp CSV file');
        }

        while ($xml->read()) {
            if ($xml->nodeType !== \XMLReader::ELEMENT || $xml->name !== 'row') {
                continue;
            }

            $rowValues = array();
            $node = $xml->expand();
            if ($node === null) {
                continue;
            }

            $cells = $node->getElementsByTagName('c');
            $nextColumnIndex = 0;
            foreach ($cells as $cell) {
                $columnIndex = $this->columnIndex($cell->getAttribute('r'));
                if ($columnIndex === null) {
                    $columnIndex = $nextColumnIndex;
                }

                while (count($rowValues) <= $columnIndex) {
                    $rowValues[] = '';
                }

                $rowValues[$columnIndex] = $this->extractCellValue($cell, $sharedStrings);
                $nextColumnIndex = $columnIndex + 1;
            }

            while (count($rowValues) < 15) {
                $rowValues[] = '';
            }

            fputcsv($handle, array_values($rowValues));
        }

        fclose($handle);
        $xml->close();

        return $outputCsvPath;
    }

    private function readSharedStrings($zip)
    {
        $content = $zip->getFromName('xl/sharedStrings.xml');
        if ($content === false) {
            return array();
        }

        $sharedStrings = array();
        $xml = new \XMLReader();
        $xml->xml($content);
        while ($xml->read()) {
            if ($xml->nodeType !== \XMLReader::ELEMENT || $xml->name !== 'si') {
                continue;
            }

            $node = $xml->expand();
            if ($node === null) {
                continue;
            }

            $text = '';
            foreach ($node->getElementsByTagName('t') as $tNode) {
                $text .= $tNode->textContent;
            }
            $sharedStrings[] = $text;
        }
        $xml->close();

        return $sharedStrings;
    }

    private function findSheetPath($zip)
    {
        $workbook = $zip->getFromName('xl/workbook.xml');
        if ($workbook === false) {
            return null;
        }

        $xml = new \XMLReader();
        $xml->xml($workbook);
        $sheetPath = null;
        while ($xml->read()) {
            if ($xml->nodeType !== \XMLReader::ELEMENT || $xml->name !== 'sheet') {
                continue;
            }
            $sheetPath = $xml->getAttribute('r:id');
            break;
        }
        $xml->close();

        if ($sheetPath === null) {
            return null;
        }

        $rels = $zip->getFromName('xl/_rels/workbook.xml.rels');
        if ($rels === false) {
            return 'xl/worksheets/sheet1.xml';
        }

        $map = array();
        $reader = new \XMLReader();
        $reader->xml($rels);
        while ($reader->read()) {
            if ($reader->nodeType !== \XMLReader::ELEMENT || $reader->name !== 'Relationship') {
                continue;
            }
            $id = $reader->getAttribute('Id');
            $target = $reader->getAttribute('Target');
            $map[$id] = 'xl/' . ltrim($target, '/');
        }
        $reader->close();

        if (isset($map[$sheetPath])) {
            return $map[$sheetPath];
        }

        return 'xl/worksheets/sheet1.xml';
    }

    private function extractCellValue($cell, array $sharedStrings)
    {
        $type = $cell->getAttribute('t');

        if ($type === 'inlineStr') {
            $textNodes = $cell->getElementsByTagName('is');
            if ($textNodes->length > 0) {
                $node = $textNodes->item(0);
                if ($node !== null) {
                    $text = '';
                    foreach ($node->getElementsByTagName('t') as $tNode) {
                        $text .= $tNode->textContent;
                    }
                    return $text;
                }
            }
            return '';
        }

        $value = '';
        foreach ($cell->childNodes as $childNode) {
            if ($childNode instanceof \DOMElement && $childNode->localName === 'v') {
                $value = (string) $childNode->textContent;
                break;
            }
        }

        if ($type === 's') {
            $index = (int) trim((string) $value);
            return isset($sharedStrings[$index]) ? $sharedStrings[$index] : '';
        }

        if ($type === 'b') {
            return $value === '1' ? 'TRUE' : 'FALSE';
        }

        return $value;
    }

    private function columnIndex($cellReference)
    {
        if (!preg_match('/^([A-Z]+)/i', (string) $cellReference, $matches)) {
            return null;
        }

        $columnNumber = 0;
        foreach (str_split(strtoupper($matches[1])) as $letter) {
            $columnNumber = ($columnNumber * 26) + ord($letter) - ord('A') + 1;
        }

        return $columnNumber - 1;
    }
}
