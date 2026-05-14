<?php

namespace App\Console\Commands;

use App\Actions\ConvertDocumentJob;
use App\Enums\DocumentConversionJobStatus;
use App\Models\DocumentConversionJob;
use App\Support\WorkerId;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Str;

#[Signature('convert-docx-to-pdf')]
#[Description('Convert pending DOCX files to PDF and upload them to S3')]
class ConvertDocxToPdfJobsCommand extends Command
{
    public function __construct(
        private readonly DatabaseManager $database,
        private readonly WorkerId $workerId,
    ) {
        parent::__construct();
    }

    public function handle(ConvertDocumentJob $convertDocumentJob): int
    {
        $workerId = $this->workerId->resolve();
        $maxJobsPerRun = max(1, (int) config('document-conversion.max_jobs_per_run', 10));
        $processedJobs = 0;

        $this->info(sprintf('Worker [%s] processing up to %d job(s).', $workerId, $maxJobsPerRun));

        while ($processedJobs < $maxJobsPerRun) {
            $job = $this->claimNextPendingJob($workerId);

            if ($job === null) {
                break;
            }

            $processedJobs++;

            try {
                $convertDocumentJob->handle($job);

                $job->forceFill([
                    'status' => DocumentConversionJobStatus::COMPLETED,
                    'completed_at' => now(),
                    'error_message' => null,
                ])->save();

                $this->line(sprintf('Job [%d] completed.', $job->getKey()));
            } catch (\Throwable $throwable) {
                $attempts = $job->attempts + 1;
                $maxAttempts = $job->resolvedMaxAttempts();
                $hasRetriesRemaining = $attempts < $maxAttempts;

                $job->forceFill([
                    'attempts' => $attempts,
                    'max_attempts' => $maxAttempts,
                    'status' => $hasRetriesRemaining
                        ? DocumentConversionJobStatus::PENDING
                        : DocumentConversionJobStatus::FAILED,
                    'error_message' => $this->formatErrorMessage($throwable),
                    'failed_at' => $hasRetriesRemaining ? null : now(),
                    'worker_id' => null,
                    'locked_at' => null,
                ])->save();

                $this->error(sprintf(
                    'Job [%d] failed on attempt %d/%d: %s',
                    $job->getKey(),
                    $attempts,
                    $maxAttempts,
                    $job->error_message,
                ));
            }
        }

        $this->info(sprintf('Processed %d job(s).', $processedJobs));

        return self::SUCCESS;
    }

    private function claimNextPendingJob(string $workerId): ?DocumentConversionJob
    {
        return $this->database->transaction(function () use ($workerId): ?DocumentConversionJob {
            $query = DocumentConversionJob::query()
                ->where('status', DocumentConversionJobStatus::PENDING)
                ->orderBy('created_at')
                ->orderBy('id');

            $driver = $this->database->connection()->getDriverName();

            if (in_array($driver, ['mysql', 'pgsql'], true)) {
                $query->lock('for update skip locked');
            } elseif ($driver !== 'sqlite') {
                $query->lockForUpdate();
            }

            $job = $query->first();

            if ($job === null) {
                return null;
            }

            $job->forceFill([
                'status' => DocumentConversionJobStatus::PROCESSING,
                'worker_id' => $workerId,
                'locked_at' => now(),
                'started_at' => now(),
                'completed_at' => null,
                'failed_at' => null,
                'error_message' => null,
            ])->save();

            return $job;
        }, 1);
    }

    private function formatErrorMessage(\Throwable $throwable): string
    {
        $message = trim($throwable->getMessage());

        if ($message === '') {
            $message = $throwable::class;
        }

        return Str::limit($message, 500);
    }
}
