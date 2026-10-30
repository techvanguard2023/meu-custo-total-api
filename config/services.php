<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'stripe' => [
        'price_essential' => env('STRIPE_PRICE_ESSENTIAL'),
        'price_pro' => env('STRIPE_PRICE_PRO'),
    ],

    'waha' => [
        'url' => env('WAHA_URL'),
        'api_key' => env('WAHA_API_KEY'),
        // Webhook do fluxo do bot (n8n) aplicado em toda sessão nova — o lojista não vê nem edita
        'default_webhook_url' => env('WAHA_DEFAULT_WEBHOOK_URL'),
    ],

    // Contas da plataforma (separadas por vírgula) que veem as configurações técnicas: webhook, tokens, endpoints
    'platform' => [
        'admin_emails' => array_values(array_filter(array_map(
            fn ($email) => strtolower(trim($email)),
            explode(',', (string) env('PLATFORM_ADMIN_EMAILS', ''))
        ))),
    ],

    // Domínio próprio do catálogo (Pro): cadastra o domínio do lojista no projeto do frontend na Vercel
    'vercel' => [
        'token' => env('VERCEL_API_TOKEN'),
        'project_id' => env('VERCEL_PROJECT_ID'),
        'team_id' => env('VERCEL_TEAM_ID'),
    ],

    'frontend_url' => env('FRONTEND_URL', 'http://localhost:3000'),

];
