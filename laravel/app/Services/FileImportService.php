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
}