<?php

/**
 * @return array{
 *     default: string,
 *     providers: array{
 *         koncile_ai: array{
 *             url: ?string,
 *             key: ?string,
 *             webhook_secret: ?string,
 *         },
 *     },
 * }
 */
return [
    'default' => env('EXTRACTION_PROVIDER', 'koncile_ai'),

    'providers' => [
        'koncile_ai' => [
            'url' => env('KONCILE_AI_API_URL', 'https://api.koncile.ai'),
            'key' => env('KONCILE_AI_API_KEY'),
            'webhook_secret' => env('KONCILE_AI_WEBHOOK_SECRET'),
        ],
    ],
];
