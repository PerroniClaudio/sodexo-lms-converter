<?php

use App\Actions\ConvertDocumentJob;
use App\Enums\DocumentConversionJobStatus;
use App\Models\DocumentConversionJob;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    Schema::create('document_conversion_jobs', function (Blueprint $table): void {
        $table->id();
        $table->string('status')->default(DocumentConversionJobStatus::PENDING->value);
        $table->string('input_disk');
        $table->string('input_path');
        $table->string('output_disk')->nullable();
        $table->string('output_path')->nullable();
        $table->unsignedInteger('attempts')->default(0);
        $table->unsignedInteger('max_attempts')->default(3);
        $table->timestamp('locked_at')->nullable();
        $table->timestamp('started_at')->nullable();
        $table->timestamp('completed_at')->nullable();
        $table->timestamp('failed_at')->nullable();
        $table->text('error_message')->nullable();
        $table->string('worker_id')->nullable();
        $table->timestamps();
    });
});

it('uploads converted pdf to exact output path on s3 disk', function () {
    Storage::fake('s3');
    Storage::disk('s3')->put('incoming/source.docx', 'docx-content');

    $job = DocumentConversionJob::query()->create([
        'status' => DocumentConversionJobStatus::PENDING,
        'input_disk' => 'ignored-input-disk',
        'input_path' => 'incoming/source.docx',
        'output_disk' => 'ignored-output-disk',
        'output_path' => 'exports/final/custom-name.pdf',
        'attempts' => 0,
        'max_attempts' => 3,
    ]);

    $action = new class extends ConvertDocumentJob
    {
        protected function runConversion(string $inputFilePath, string $outputDirectory): void
        {
            expect($inputFilePath)->toEndWith('source.docx');
            expect(file_get_contents($inputFilePath))->toBe('docx-content');

            file_put_contents($outputDirectory.DIRECTORY_SEPARATOR.'source.pdf', 'pdf-content');
        }
    };

    $action->handle($job);

    Storage::disk('s3')->assertExists('exports/final/custom-name.pdf');
    expect(Storage::disk('s3')->get('exports/final/custom-name.pdf'))->toBe('pdf-content');
});

it('uses configured max attempts when not provided during creation', function () {
    config()->set('document-conversion.max_attempts', 7);

    $job = DocumentConversionJob::query()->create([
        'status' => DocumentConversionJobStatus::PENDING,
        'input_disk' => 'ignored-input-disk',
        'input_path' => 'incoming/source.docx',
        'output_disk' => 'ignored-output-disk',
        'output_path' => 'exports/final/custom-name.pdf',
        'attempts' => 0,
    ]);

    expect($job->max_attempts)->toBe(7);
});

it('runs libreoffice with a writable user profile', function () {
    Process::fake();

    $temporaryDirectory = storage_path('app/tmp/document-conversions/process-test-'.bin2hex(random_bytes(8)));

    mkdir($temporaryDirectory, 0777, true);
    file_put_contents($temporaryDirectory.DIRECTORY_SEPARATOR.'source.docx', 'docx-content');

    $action = new class extends ConvertDocumentJob
    {
        public function convert(string $inputFilePath, string $outputDirectory): void
        {
            $this->runConversion($inputFilePath, $outputDirectory);
        }
    };

    try {
        $action->convert($temporaryDirectory.DIRECTORY_SEPARATOR.'source.docx', $temporaryDirectory);

        Process::assertRan(function (PendingProcess $process) use ($temporaryDirectory): bool {
            return $process->path === $temporaryDirectory
                && $process->environment['HOME'] === $temporaryDirectory.DIRECTORY_SEPARATOR.'libreoffice-home'
                && $process->environment['XDG_CACHE_HOME'] === $temporaryDirectory.DIRECTORY_SEPARATOR.'libreoffice-home'.DIRECTORY_SEPARATOR.'.cache'
                && in_array('-env:UserInstallation=file://'.$temporaryDirectory.DIRECTORY_SEPARATOR.'libreoffice-profile', $process->command, true);
        });
    } finally {
        deleteDirectory($temporaryDirectory);
    }
});

function deleteDirectory(string $directory): void
{
    if (! is_dir($directory)) {
        return;
    }

    $items = array_diff(scandir($directory) ?: [], ['.', '..']);

    foreach ($items as $item) {
        $path = $directory.DIRECTORY_SEPARATOR.$item;

        if (is_dir($path)) {
            deleteDirectory($path);

            continue;
        }

        @unlink($path);
    }

    @rmdir($directory);
}
