<?php

// config for Hwkdo/OpenwebuiApiLaravel
return [
    'api_key' => env('OPENWEBUI_API_KEY'),
    'base_api_url' => env('OPENWEBUI_BASE_API_URL', 'https://chat.ai.hwk-do.com/api'),
    'default_model' => env('OPENWEBUI_DEFAULT_MODEL', 'gpt-oss:20b'),
];
