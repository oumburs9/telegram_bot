<?php

return [
    'max_file_size' => (int) env('TELEGRAM_MAX_FILE_SIZE', 5 * 1024 * 1024),
    'allowed_mime_types' => [
        'application/pdf',
        'image/png',
        'image/jpeg',
        'image/jpg',
    ],
    'python_bin' => env('PYTHON_BIN', 'python3'),
    'python_processor_path' => env('PYTHON_PROCESSOR_PATH', base_path('processor/main.py')),
    'process_timeout' => (int) env('TELEGRAM_PROCESS_TIMEOUT', 120),
    'cleanup_after_hours' => (int) env('TELEGRAM_CLEANUP_AFTER_HOURS', 72),
    'expected_output_files' => [
        'normal.png' => 'image/png',
        'mirror.png' => 'image/png',
        'a4_color.pdf' => 'application/pdf',
        'a4_gray.pdf' => 'application/pdf',
    ],
];
