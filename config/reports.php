<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Reports Configuration
    |--------------------------------------------------------------------------
    |
    | Thresholds and storage settings for the per-class report generation.
    | All values are overridable via environment variables.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Synchronous Generation Threshold
    |--------------------------------------------------------------------------
    |
    | Maximum number of total attempts in a class for synchronous PDF/Excel
    | generation. When total attempts is less than this threshold, the file
    | is generated and returned immediately. When greater than or equal to
    | this threshold, a queue job is dispatched instead.
    |
    */
    'sync_threshold' => env('REPORTS_SYNC_THRESHOLD', 100),

    /*
    |--------------------------------------------------------------------------
    | Pass Rate Threshold
    |--------------------------------------------------------------------------
    |
    | Multiplier against exam.max_score to determine if an attempt counts as
    | passing. Example: threshold=0.6 and max_score=20 means score >= 12 is
    | a passing attempt. Pass rate is then (passing / total) * 100%.
    |
    */
    'pass_rate_threshold' => env('REPORTS_PASS_RATE_THRESHOLD', 0.6),

    /*
    |--------------------------------------------------------------------------
    | Storage Disk
    |--------------------------------------------------------------------------
    |
    | The filesystem disk where generated report files (PDF, Excel) are stored.
    | Must correspond to a disk defined in config/filesystems.php.
    |
    */
    'storage_disk' => env('REPORTS_STORAGE_DISK', 'reports'),

    /*
    |--------------------------------------------------------------------------
    | Storage Path
    |--------------------------------------------------------------------------
    |
    | The subdirectory within the storage disk where report files are written.
    |
    */
    'storage_path' => env('REPORTS_STORAGE_PATH', 'reports'),

];
