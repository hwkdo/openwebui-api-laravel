<?php

namespace Hwkdo\OpenwebuiApiLaravel\Services;

use Illuminate\Support\Facades\Http;

class OpenWebUiModelService
{
    protected string $baseUrl;

    public function __construct()
    {
        $this->baseUrl = rtrim(config('openwebui-api-laravel.base_api_url', ''), '/');
    }

    /**
     * @return array<string, mixed>
     *
     * @throws \Exception
     */
    public function listModels(?string $userToken = null): array
    {
        $token = $userToken ?: config('openwebui-api-laravel.api_key');

        if (empty($token)) {
            throw new \Exception('OpenWebUI API-Token fehlt.');
        }

        $response = Http::withHeaders([
            'Authorization' => 'Bearer '.$token,
            'Accept' => 'application/json',
        ])->get($this->baseUrl.'/models');

        if (! $response->successful()) {
            throw new \Exception('OpenWebUI Modelle-Abruf fehlgeschlagen: '.$response->status().' - '.$response->body());
        }

        return $response->json() ?? [];
    }
}
