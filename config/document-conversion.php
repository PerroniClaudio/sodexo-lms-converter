<?php

return [
    'max_jobs_per_run' => max(1, (int) env('MAX_JOBS_PER_RUN', 10)),

    'worker_id' => env('WORKER_ID'),

    'libreoffice_binary' => 'soffice',

    'process_timeout' => 120,
];
