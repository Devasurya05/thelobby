<?php

return [

    // How long a tapped bed stays reserved for one person before it is released automatically.
    // 120 = 2 minutes (the default). For a live demo with friends set HOLD_SECONDS=30 in .env.
    'hold_seconds' => (int) env('HOLD_SECONDS', 120),

    // How often the lobby screen asks the server for fresh bed states (milliseconds).
    'poll_ms' => (int) env('LOBBY_POLL_MS', 2000),

    // Password for the owner-only pages (/admin/errors). Lives in .env only.
    // If it is empty or shorter than 16 characters the admin pages are switched OFF (404).
    'admin_key' => (string) env('ADMIN_KEY', ''),

    'vault' => [
        // Which filesystem disk holds the files (config/filesystems.php). Always the private S3 bucket.
        'disk' => env('VAULT_DISK', 's3'),

        // A file link works for this many seconds after someone taps it. Short on purpose: a leaked or
        // forwarded link is useless a minute later.
        'link_seconds' => 60,

        // Limits keep a trip's vault small (and the AWS bill at zero): 10 MB per file, 40 files per trip.
        // The PHP settings upload_max_filesize / post_max_size (and Nginx client_max_body_size) must be
        // at least as big as max_kb, or the file is rejected before Laravel ever sees it.
        'max_kb' => 10240,
        'max_files' => 40,

        // HEIC/HEIF are iPhone photos. Phones usually convert them to JPEG on upload; if one arrives as
        // HEIC we store it unchanged (no extra image library) and it can still be downloaded.
        'allowed_types' => ['pdf', 'jpg', 'jpeg', 'png', 'webp', 'heic', 'heif'],
    ],

];
