<?php

return [
    'output_directory' => 'prompt',

    'max_size_kb' => 300,

    'ignored_directories' => [
        'vendor',
        'node_modules',
        'storage',
        'bootstrap/cache',
        '.git',
        '.idea',
        '.vscode',
        'public/build',
    ],

    'ignored_extensions' => [
        'png',
        'jpg',
        'jpeg',
        'gif',
        'webp',
        'svg',
        'ico',
        'mp3',
        'mp4',
        'mov',
        'avi',
        'zip',
        'rar',
        '7z',
        'pdf',
        'ttf',
        'otf',
        'woff',
        'woff2',
        'lock',
        'pem',
        'key',
        'p12',
        'pfx',
    ],

    'ignored_file_patterns' => [
        '.env',
        '.env.*',
        'auth.json',
        'credentials.json',
        'service-account*.json',
        'id_rsa',
        'id_ed25519',
    ],
];
