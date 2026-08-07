<?php

return [
    'disk' => env('STUDY_MATERIALS_DISK', 'public'),
    'prefix' => env('STUDY_MATERIALS_PREFIX', 'materials'),
    'teacher_quota_bytes' => (int) env('STUDY_MATERIALS_TEACHER_QUOTA_BYTES', 5 * 1024 ** 3),
    'max_upload_kilobytes' => (int) env('STUDY_MATERIALS_MAX_UPLOAD_KILOBYTES', 50 * 1024),
];
