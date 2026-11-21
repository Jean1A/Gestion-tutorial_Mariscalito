<?php

namespace App\Services;

use Generator;
use OpenSpout\Reader\XLSX\Options;
use OpenSpout\Reader\XLSX\Reader;
use RuntimeException;

class SpreadsheetRows
{
    public function read(string $path, string $format, bool $preserveEmptyRows = false): Generator
    {
        if ($format === 'csv') {
            $handle = fopen($path, 'rb');
            if (! $handle) {
                throw new RuntimeException('No se pudo abrir el archivo.');
            }
            try {
                while (($row = fgetcsv($handle, 0, ',', '"', '')) !== false) {
                    yield $row;
                }
            } finally {
                fclose($handle);
            }

            return;
        }
        $reader = new Reader(new Options(SHOULD_PRESERVE_EMPTY_ROWS: $preserveEmptyRows));
        $reader->open($path);
        try {
            foreach ($reader->getSheetIterator() as $sheet) {
                foreach ($sheet->getRowIterator() as $row) {
                    yield array_map(fn ($value) => $value instanceof \DateTimeInterface ? $value->format('Y-m-d') : $value, $row->toArray());
                }
                break;
            }
        } finally {
            $reader->close();
        }
    }
}
