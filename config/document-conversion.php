<?php

return [
    'max_jobs_per_run' => max(1, (int) env('MAX_JOBS_PER_RUN', 10)),

    'max_attempts' => max(1, (int) env('MAX_ATTEMPTS', 3)),

    'worker_id' => env('WORKER_ID'),

    'libreoffice_binary' => 'soffice',

    'process_timeout' => max(1, (int) env('LIBREOFFICE_TIMEOUT', 120)),
];
