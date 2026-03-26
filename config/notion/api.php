<?php

return [
    'url' => "https://api.notion.com/v1",
    'backlogDatabaseUrl' => env('NOTION_BACKLOG_DATABASE_URL'),
    'parentDatabaseUrl' => env('NOTION_PARENT_DATABASE_URL'),
    'releaseScheduleDatabaseUrl' => env('NOTION_RELEASE_SCHEDULE_DATABASE_URL'),
    'roadmapDatabaseUrl' => env('NOTION_ROADMAP_DATABASE_URL'),
    'token' => env('NOTION_API_TOKEN'),
    // Laravel Http デフォルトは 30 秒。Notion の database query は重くタイムアウトしやすい
    'http_timeout' => (int) env('NOTION_HTTP_TIMEOUT', 120),
    'http_retry_times' => (int) env('NOTION_HTTP_RETRY_TIMES', 3),
    'http_retry_sleep_ms' => (int) env('NOTION_HTTP_RETRY_SLEEP_MS', 2000),
];
