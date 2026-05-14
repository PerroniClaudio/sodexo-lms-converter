<?php

namespace App\Support;

class WorkerId
{
    public function resolve(): string
    {
        $configuredWorkerId = trim((string) config('document-conversion.worker_id'));

        if ($configuredWorkerId !== '') {
            return $configuredWorkerId;
        }

        $hostname = gethostname();

        if (is_string($hostname) && $hostname !== '') {
            return $hostname;
        }

        return 'unknown-worker';
    }
}
