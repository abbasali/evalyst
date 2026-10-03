<?php

return [

    /*
    |--------------------------------------------------------------------------
    | AI
    |--------------------------------------------------------------------------
    |
    | Every AI task (question generation, verification, grading) uses the
    | provider and model below via the Laravel AI SDK. See docs/03-ai.md.
    |
    */

    'ai' => [
        'provider' => env('EVALYST_AI_PROVIDER', 'openai'),
        'model' => env('EVALYST_AI_MODEL', 'gpt-5.4-mini'),

        // USD per 1M tokens, used to compute ai_runs.cost_usd.
        'pricing' => [
            'gpt-5.4-mini' => ['input' => 0.75, 'output' => 4.50],
            'gpt-5.4-nano' => ['input' => 0.20, 'output' => 1.25],
        ],

        'auto_publish_threshold' => 0.80,
        'max_generation_questions' => 30,
        'max_concurrent_generations' => 3,
        'rate_limit_per_minute' => 60,
    ],

    /*
    |--------------------------------------------------------------------------
    | GitHub
    |--------------------------------------------------------------------------
    |
    | Repositories are only read through the GitHub API, never cloned.
    | Ignored paths are filtered before any file content is downloaded.
    | See docs/04-github-ingestion.md.
    |
    */

    'github' => [
        'token' => env('GITHUB_TOKEN'),
        'max_context_tokens' => 100_000,
        'max_file_bytes' => 100_000,
        'max_commits' => 500,

        'ignored_paths' => [
            'directories' => [
                'vendor', 'node_modules', '.git', 'storage', 'bootstrap/cache',
                'public/build', 'public/hot', 'public/storage', 'dist', 'build',
                'coverage', '.idea', '.vscode', '.fleet', '.next', '.nuxt', '.cache',
                '__pycache__', '.venv', 'venv', 'target', '.phpunit.cache', '.pest', '.turbo',
            ],
            'files' => [
                '*.lock', 'package-lock.json', 'pnpm-lock.yaml', 'bun.lockb',
                '*.min.js', '*.min.css', '*.map', '.env', '.env.*', '*.log',
                '*.sqlite', '*.sqlite3', '.DS_Store', '*.phar',
            ],
            'files_allowed' => ['.env.example'],
            'extensions' => [
                'png', 'jpg', 'jpeg', 'gif', 'webp', 'ico', 'bmp', 'svg',
                'woff', 'woff2', 'ttf', 'otf', 'eot',
                'zip', 'tar', 'gz', 'rar', '7z',
                'mp3', 'mp4', 'mov', 'webm', 'wav',
                'pdf', 'exe', 'dll', 'so', 'dylib', 'bin',
            ],
        ],
    ],

    'assignments' => [
        // Temporary guard: submissions are not graded until M10 lands.
        'auto_grade' => (bool) env('EVALYST_ASSIGNMENTS_AUTO_GRADE', false),
    ],

    'quiz' => [
        // Answer saves accepted after the deadline to absorb network latency.
        'save_grace_seconds' => 30,
    ],

    'student' => [
        'join_attempts_per_minute' => 10,
    ],

];
