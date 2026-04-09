<?php

// config for Hwkdo/OpenwebuiApiLaravel
return [
    'api_key' => env('OPENWEBUI_API_KEY'),
    'base_api_url' => env('OPENWEBUI_BASE_API_URL', 'https://chat.ai.hwk-do.com/api'),
    'base_api_url_ollama' => env('OPENWEBUI_BASE_API_URL_OLLAMA', 'https://chat-local.ai.hwkdo.com/ollama'),
    'default_model' => env('OPENWEBUI_DEFAULT_MODEL', 'gpt-oss:20b'),
    'system_prompt_template' => env('OPENWEBUI_SYSTEM_PROMPT_TEMPLATE', 'Du bist mein persönlicher Assistent. Mein Name ist {vorname} {nachname}{gvp_part} bei der Handwerkskammer Dortmund. Meine Benutzer-ID im Intranet ist {user_id}.'),
    'system_prompt_gvp_template' => env('OPENWEBUI_SYSTEM_PROMPT_GVP_TEMPLATE', ' und ich arbeite in der Abteilung {gvp_bezeichnung}'),

    /*
    | Vision/OCR über /api/chat/completions (Ollama): zu großes num_ctx (z. B. 262144 bei Modell-Max 128k)
    | erzeugt Ollama-Warnungen und kann „endlos“ laden. Werte hier sind Request-Defaults; in Open WebUI
    | hinterlegte Modell-Parameter (Advanced) können sie überschreiben — dann num_ctx dort anpassen.
    */
    'ocr_num_ctx' => (int) env('OPENWEBUI_OCR_NUM_CTX', 32_768),
    'ocr_max_tokens' => (int) env('OPENWEBUI_OCR_MAX_TOKENS', 8192),
    'vision_chat_timeout' => (int) env('OPENWEBUI_VISION_CHAT_TIMEOUT', 600),
    'vision_chat_connect_timeout' => (int) env('OPENWEBUI_VISION_CHAT_CONNECT_TIMEOUT', 30),
];
