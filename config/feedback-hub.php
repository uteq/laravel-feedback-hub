<?php

return [
    'ai' => [
        'enabled' => env('FEEDBACK_HUB_AI_ENABLED', false),
        'provider' => env('FEEDBACK_HUB_AI_PROVIDER', 'anthropic'),
        // Optional dedicated key for the feedback chat, leaving the app's provider key unchanged.
        'key' => env('FEEDBACK_HUB_AI_KEY'),
        'model' => env('FEEDBACK_HUB_AI_MODEL', 'claude-haiku-4-5'),
        'max_turns' => (int) env('FEEDBACK_HUB_AI_MAX_TURNS', 6),
    ],

    'enabled' => env('FEEDBACK_HUB_ENABLED', true),

    'project' => env('FEEDBACK_HUB_PROJECT', env('APP_NAME', 'Laravel')),

    'route_prefix' => env('FEEDBACK_HUB_ROUTE_PREFIX', 'feedback-hub'),

    'route_middleware' => ['web', 'auth'],

    'admin_middleware' => ['web', 'auth'],

    'storage_disk' => env('FEEDBACK_HUB_STORAGE_DISK', 'local'),

    'screenshot_path' => env('FEEDBACK_HUB_SCREENSHOT_PATH', 'feedback-hub/screenshots'),

    'queue' => env('FEEDBACK_HUB_QUEUE', 'default'),

    // Screen recording in the widget. The browser sends it in small chunks, so the default
    // nginx (1 MB) and PHP (2 MB) upload limits never get in the way. Chunks wait in
    // storage/app/feedback-hub/video-uploads until the report is sent; leftovers older than
    // a day are removed on the next upload of that user.
    'video' => [
        'enabled' => env('FEEDBACK_HUB_VIDEO_ENABLED', true),
        'path' => env('FEEDBACK_HUB_VIDEO_PATH', 'feedback-hub/videos'),
        'max_seconds' => (int) env('FEEDBACK_HUB_VIDEO_MAX_SECONDS', 120),
        'max_kilobytes' => (int) env('FEEDBACK_HUB_VIDEO_MAX_KILOBYTES', 30720),
        'chunk_kilobytes' => (int) env('FEEDBACK_HUB_VIDEO_CHUNK_KILOBYTES', 512),
    ],

    'github' => [
        'enabled' => env('FEEDBACK_HUB_GITHUB_ENABLED', true),
        'token' => env('FEEDBACK_HUB_GITHUB_TOKEN', env('GITHUB_API_TOKEN', env('GITHUB_TOKEN', env('GH_TOKEN')))),
        'repo' => env('FEEDBACK_HUB_GITHUB_REPO', env('GITHUB_REPO', env('GITHUB_REPOSITORY'))),
        'labels' => env('FEEDBACK_HUB_GITHUB_LABELS', 'feedback'),
        'assignees' => env('FEEDBACK_HUB_GITHUB_ASSIGNEES', env('GITHUB_ASSIGNEES', '')),
    ],

    'linear' => [
        'enabled' => env('FEEDBACK_HUB_LINEAR_ENABLED', true),
        'token' => env('FEEDBACK_HUB_LINEAR_TOKEN', env('LINEAR_API_KEY', env('LINEAR_API_TOKEN', env('LINEAR_TOKEN')))),
        'team_id' => env('FEEDBACK_HUB_LINEAR_TEAM_ID', env('LINEAR_TEAM_ID')),
        'project_id' => env('FEEDBACK_HUB_LINEAR_PROJECT_ID', env('LINEAR_PROJECT_ID')),
        'label_ids' => env('FEEDBACK_HUB_LINEAR_LABEL_IDS', env('LINEAR_LABEL_IDS', '')),
    ],

    'os_inbox' => [
        'url' => env('FEEDBACK_HUB_OS_INBOX_URL'),
        'key' => env('FEEDBACK_HUB_OS_INBOX_KEY'),
    ],

    'telegram' => [
        'enabled' => env('FEEDBACK_HUB_TELEGRAM_ENABLED', true),
        'bot_token' => env('FEEDBACK_HUB_TELEGRAM_BOT_TOKEN', env('TELEGRAM_BOT_TOKEN')),
        'chat_id' => env('FEEDBACK_HUB_TELEGRAM_CHAT_ID', env('TELEGRAM_CHANNEL_CHAT_ID', env('TELEGRAM_CHAT_ID'))),
    ],
];
