<?php

namespace App\Models;

use App\Enums\DocumentConversionJobStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DocumentConversionJob extends Model
{
    use HasFactory;

    protected $fillable = [
        'status',
        'input_disk',
        'input_path',
        'output_disk',
        'output_path',
        'attempts',
        'max_attempts',
        'locked_at',
        'started_at',
        'completed_at',
        'failed_at',
        'error_message',
        'worker_id',
    ];

    protected function casts(): array
    {
        return [
            'status' => DocumentConversionJobStatus::class,
            'attempts' => 'integer',
            'max_attempts' => 'integer',
            'locked_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }

    public function canBeRetried(): bool
    {
        return filled($this->input_disk)
            && filled($this->input_path)
            && in_array($this->status, [
                DocumentConversionJobStatus::COMPLETED,
                DocumentConversionJobStatus::FAILED,
                DocumentConversionJobStatus::CANCELLED,
            ], true);
    }

    public function hasGeneratedFile(): bool
    {
        return filled($this->output_disk) && filled($this->output_path);
    }

    public function outputFileName(): ?string
    {
        if (! $this->hasGeneratedFile()) {
            return null;
        }

        return basename((string) $this->output_path);
    }
}
