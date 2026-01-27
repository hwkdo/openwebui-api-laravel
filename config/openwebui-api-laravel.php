<?php

// config for Hwkdo/OpenwebuiApiLaravel
return [
    'api_key' => env('OPENWEBUI_API_KEY'),
    'base_api_url' => env('OPENWEBUI_BASE_API_URL', 'https://chat.ai.hwk-do.com/api'),
    'base_api_url_ollama' => env('OPENWEBUI_BASE_API_URL_OLLAMA', 'https://chat-local.ai.hwkdo.com/ollama'),
    'default_model' => env('OPENWEBUI_DEFAULT_MODEL', 'gpt-oss:20b'),
    'system_prompt_template' => env('OPENWEBUI_SYSTEM_PROMPT_TEMPLATE', 'Du bist mein persönlicher Assistent. Mein Name ist {vorname} {nachname}{gvp_part} bei der Handwerkskammer Dortmund.'),
    'system_prompt_gvp_template' => env('OPENWEBUI_SYSTEM_PROMPT_GVP_TEMPLATE', ' und ich arbeite in der Abteilung {gvp_bezeichnung}'),
];
