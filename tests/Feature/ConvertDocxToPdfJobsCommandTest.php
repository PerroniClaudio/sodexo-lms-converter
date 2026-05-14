<?php

use App\Actions\ConvertDocumentJob;
use App\Enums\DocumentConversionJobStatus;
use App\Models\DocumentConversionJob;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

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

it('claims pending job and marks it completed after successful conversion', function () {
    config()->set('document-conversion.worker_id', 'worker-1');
    config()->set('document-conversion.max_jobs_per_run', 1);

    $job = createDocumentConversionJob();

    $mock = Mockery::mock(ConvertDocumentJob::class);
    $mock->shouldReceive('handle')
        ->once()
        ->withArgs(function (DocumentConversionJob $claimedJob): bool {
            expect($claimedJob->status)->toBe(DocumentConversionJobStatus::PROCESSING);
            expect($claimedJob->worker_id)->toBe('worker-1');
            expect($claimedJob->locked_at)->not->toBeNull();
            expect($claimedJob->started_at)->not->toBeNull();

            return true;
        });

    $this->app->instance(ConvertDocumentJob::class, $mock);

    $this->artisan('convert-docx-to-pdf')
        ->assertSuccessful();

    $job->refresh();

    expect($job->status)->toBe(DocumentConversionJobStatus::COMPLETED)
        ->and($job->completed_at)->not->toBeNull()
        ->and($job->error_message)->toBeNull();
});

it('requeues failed jobs while attempts remain', function () {
    config()->set('document-conversion.worker_id', 'worker-1');
    config()->set('document-conversion.max_jobs_per_run', 1);

    $job = createDocumentConversionJob();

    $mock = Mockery::mock(ConvertDocumentJob::class);
    $mock->shouldReceive('handle')
        ->once()
        ->andThrow(new RuntimeException('conversion failed'));

    $this->app->instance(ConvertDocumentJob::class, $mock);

    $this->artisan('convert-docx-to-pdf')
        ->assertSuccessful();

    $job->refresh();

    expect($job->attempts)->toBe(1)
        ->and($job->status)->toBe(DocumentConversionJobStatus::PENDING)
        ->and($job->worker_id)->toBeNull()
        ->and($job->locked_at)->toBeNull()
        ->and($job->failed_at)->toBeNull()
        ->and($job->error_message)->toBe('conversion failed');
});

it('marks failed jobs as terminal when max attempts reached', function () {
    config()->set('document-conversion.worker_id', 'worker-1');
    config()->set('document-conversion.max_jobs_per_run', 1);

    $job = createDocumentConversionJob([
        'attempts' => 2,
        'max_attempts' => 3,
    ]);

    $mock = Mockery::mock(ConvertDocumentJob::class);
    $mock->shouldReceive('handle')
        ->once()
        ->andThrow(new RuntimeException('conversion failed'));

    $this->app->instance(ConvertDocumentJob::class, $mock);

    $this->artisan('convert-docx-to-pdf')
        ->assertSuccessful();

    $job->refresh();

    expect($job->attempts)->toBe(3)
        ->and($job->status)->toBe(DocumentConversionJobStatus::FAILED)
        ->and($job->failed_at)->not->toBeNull()
        ->and($job->worker_id)->toBeNull()
        ->and($job->locked_at)->toBeNull();
});

it('uses configured max attempts when the job record has an invalid value', function () {
    config()->set('document-conversion.worker_id', 'worker-1');
    config()->set('document-conversion.max_jobs_per_run', 1);
    config()->set('document-conversion.max_attempts', 4);

    $job = createDocumentConversionJob([
        'max_attempts' => 0,
    ]);

    $mock = Mockery::mock(ConvertDocumentJob::class);
    $mock->shouldReceive('handle')
        ->once()
        ->andThrow(new RuntimeException('conversion failed'));

    $this->app->instance(ConvertDocumentJob::class, $mock);

    $this->artisan('convert-docx-to-pdf')
        ->assertSuccessful();

    $job->refresh();

    expect($job->attempts)->toBe(1)
        ->and($job->max_attempts)->toBe(4)
        ->and($job->status)->toBe(DocumentConversionJobStatus::PENDING);
});

it('stops after configured max jobs per run', function () {
    config()->set('document-conversion.worker_id', 'worker-1');
    config()->set('document-conversion.max_jobs_per_run', 2);

    $firstJob = createDocumentConversionJob(['created_at' => now()->subMinutes(3)]);
    $secondJob = createDocumentConversionJob(['created_at' => now()->subMinutes(2)]);
    $thirdJob = createDocumentConversionJob(['created_at' => now()->subMinute()]);

    $mock = Mockery::mock(ConvertDocumentJob::class);
    $mock->shouldReceive('handle')->twice();

    $this->app->instance(ConvertDocumentJob::class, $mock);

    $this->artisan('convert-docx-to-pdf')
        ->assertSuccessful();

    expect($firstJob->fresh()->status)->toBe(DocumentConversionJobStatus::COMPLETED)
        ->and($secondJob->fresh()->status)->toBe(DocumentConversionJobStatus::COMPLETED)
        ->and($thirdJob->fresh()->status)->toBe(DocumentConversionJobStatus::PENDING);
});

function createDocumentConversionJob(array $overrides = []): DocumentConversionJob
{
    return DocumentConversionJob::query()->create(array_merge([
        'status' => DocumentConversionJobStatus::PENDING,
        'input_disk' => 'ignored-input-disk',
        'input_path' => 'incoming/test.docx',
        'output_disk' => 'ignored-output-disk',
        'output_path' => 'converted/test.pdf',
        'attempts' => 0,
        'max_attempts' => 3,
        'error_message' => 'stale error',
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides));
}
