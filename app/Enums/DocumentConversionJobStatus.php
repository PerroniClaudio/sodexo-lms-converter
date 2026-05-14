<?php

namespace App\Enums;

enum DocumentConversionJobStatus: string
{
    case PENDING = 'pending';
    case PROCESSING = 'processing';
    case COMPLETED = 'completed';
    case FAILED = 'failed';
    case CANCELLED = 'cancelled';

    public function badgeClass(): string
    {
        return match ($this) {
            self::PENDING => 'badge-warning',
            self::PROCESSING => 'badge-info',
            self::COMPLETED => 'badge-success',
            self::FAILED => 'badge-error',
            self::CANCELLED => 'badge-neutral',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::PENDING => __('In coda'),
            self::PROCESSING => __('In lavorazione'),
            self::COMPLETED => __('Completato'),
            self::FAILED => __('Fallito'),
            self::CANCELLED => __('Annullato'),
        };
    }
}
