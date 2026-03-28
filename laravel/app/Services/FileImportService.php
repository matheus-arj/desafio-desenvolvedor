<?php

namespace App\Services;

use App\Models\Instrument;
use App\Models\Upload;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

class FileImportService
{
    public function import(UploadedFile $file): Upload
    {
        $hash = md5_file($file->getRealPath());

        if (Upload::where('hash', $hash)->exists()) {
            throw new \DomainException('This file has already been uploaded.');
        }

        $storedName = $hash . '.' . $file->getClientOriginalExtension();
        Storage::put("uploads/{$storedName}", file_get_contents($file->getRealPath()));

        $upload = Upload::create([
            'filename'       => $storedName,
            'original_name'  => $file->getClientOriginalName(),
            'size'           => $file->getSize(),
            'hash'           => $hash,
            'reference_date' => null,
            'rows_imported'  => 0,
            'status'         => 'processing',
        ]);

        try {
            $rows = $this->parseFile($file);
            $this->insertInChunks($rows, (string) $upload->_id, $upload);

            $upload->update(['status' => 'done']);
        } catch (\Throwable $e) {
            $upload->update(['status' => 'error']);
            throw $e;
        }

        return $upload->fresh();
    }

    private function parseFile(UploadedFile $file): \Generator
    {
        $extension = strtolower($file->getClientOriginalExtension());

        if ($extension === 'csv') {
            yield from $this->parseCsv($file->getRealPath());
        } elseif (in_array($extension, ['xlsx', 'xls'], true)) {
            yield from $this->parseExcel($file->getRealPath());
        } else {
            throw new \InvalidArgumentException('Unsupported file format. Please upload a CSV or Excel file.');
        }
    }

    private function parseCsv(string $path): \Generator
    {
        $handle = fopen($path, 'r');

        fgets($handle);

        $header = null;

        while (($row = fgetcsv($handle, 0, ';')) !== false) {
            if ($header === null) {
                $header = array_map('trim', $row);
                continue;
            }

            if (count($row) !== count($header)) {
                continue;
            }

            $data = array_combine($header, $row);

            $data = array_map(function ($value) {
                return mb_detect_encoding($value, 'UTF-8', true)
                    ? $value
                    : mb_convert_encoding($value, 'UTF-8', 'ISO-8859-1');
            }, $data);

            if ($this->hasRequiredColumns($data)) {
                yield $data;
            }
        }

        fclose($handle);
    }

    private function parseExcel(string $path): \Generator
    {
        $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile($path);
        $reader->setReadDataOnly(true);
        $spreadsheet = $reader->load($path);
        $sheet       = $spreadsheet->getActiveSheet();
        $rows        = $sheet->toArray(null, true, true, false);

        $header = null;

        foreach ($rows as $row) {
            if ($header === null) {
                $header = array_map('trim', $row);
                continue;
            }

            $data = array_combine($header, $row);

            if ($this->hasRequiredColumns($data)) {
                yield $data;
            }
        }
    }
}