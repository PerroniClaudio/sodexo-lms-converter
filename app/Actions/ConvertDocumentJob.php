<?php

namespace App\Actions;

use App\Exceptions\DocumentConversionException;
use App\Models\DocumentConversionJob;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

class ConvertDocumentJob
{
    public function handle(DocumentConversionJob $job): void
    {
        $temporaryDirectory = $this->makeTemporaryDirectory($job);

        try {
            $inputFilePath = $temporaryDirectory.DIRECTORY_SEPARATOR.'source.docx';
            $outputFilePath = $temporaryDirectory.DIRECTORY_SEPARATOR.'source.pdf';

            $this->downloadSourceDocument($job, $inputFilePath);
            $this->runConversion($inputFilePath, $temporaryDirectory);
            $this->assertConvertedFileExists($outputFilePath);
            $this->uploadConvertedDocument($job, $outputFilePath);
        } finally {
            $this->deleteDirectory($temporaryDirectory);
        }
    }

    protected function runConversion(string $inputFilePath, string $outputDirectory): void
    {
        $result = Process::path($outputDirectory)
            ->timeout((int) config('document-conversion.process_timeout', 120))
            ->run([
                config('document-conversion.libreoffice_binary', 'soffice'),
                '--headless',
                '--convert-to',
                'pdf',
                '--outdir',
                $outputDirectory,
                $inputFilePath,
            ]);

        if ($result->failed()) {
            throw new DocumentConversionException(
                $result->errorOutput() !== '' ? trim($result->errorOutput()) : 'LibreOffice conversion failed.',
            );
        }
    }

    private function downloadSourceDocument(DocumentConversionJob $job, string $destinationPath): void
    {
        $stream = Storage::disk('s3')->readStream($job->input_path);

        if ($stream === false) {
            throw new DocumentConversionException('Unable to read source DOCX file.');
        }

        $handle = fopen($destinationPath, 'wb');

        if ($handle === false) {
            fclose($stream);

            throw new DocumentConversionException('Unable to create temporary DOCX file.');
        }

        try {
            if (stream_copy_to_stream($stream, $handle) === false) {
                throw new DocumentConversionException('Unable to download source DOCX file.');
            }
        } finally {
            fclose($stream);
            fclose($handle);
        }
    }

    private function uploadConvertedDocument(DocumentConversionJob $job, string $sourcePath): void
    {
        $stream = fopen($sourcePath, 'rb');

        if ($stream === false) {
            throw new DocumentConversionException('Unable to open generated PDF file.');
        }

        try {
            $uploaded = Storage::disk('s3')->writeStream($job->output_path, $stream);
        } finally {
            fclose($stream);
        }

        if ($uploaded === false) {
            throw new DocumentConversionException('Unable to upload generated PDF file.');
        }
    }

    private function assertConvertedFileExists(string $outputFilePath): void
    {
        if (! is_file($outputFilePath) || filesize($outputFilePath) === 0) {
            throw new DocumentConversionException('Generated PDF file is missing.');
        }
    }

    private function makeTemporaryDirectory(DocumentConversionJob $job): string
    {
        $directory = storage_path('app/tmp/document-conversions/'.$job->getKey().'-'.bin2hex(random_bytes(8)));

        if (! mkdir($directory, 0777, true) && ! is_dir($directory)) {
            throw new DocumentConversionException('Unable to create temporary directory.');
        }

        return $directory;
    }

    private function deleteDirectory(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }

        $items = array_diff(scandir($directory) ?: [], ['.', '..']);

        foreach ($items as $item) {
            $path = $directory.DIRECTORY_SEPARATOR.$item;

            if (is_dir($path)) {
                $this->deleteDirectory($path);

                continue;
            }

            @unlink($path);
        }

        @rmdir($directory);
    }
}
