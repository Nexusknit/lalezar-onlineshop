<?php

return [
    'default_disk' => env('UPLOAD_DEFAULT_DISK', 'public'),
    'allowed_disks' => array_values(array_filter(array_map(
        static fn (string $disk): string => trim($disk),
        explode(',', (string) env('UPLOAD_ALLOWED_DISKS', 'public'))
    ))),
    'max_kilobytes' => (int) env('UPLOAD_MAX_KILOBYTES', 5120),
    'image_mimes' => ['jpg', 'jpeg', 'png', 'webp', 'gif', 'avif'],
];
