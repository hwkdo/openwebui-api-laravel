<?php

namespace Hwkdo\OpenwebuiApiLaravel\Services;

use App\Services\Interfaces\AiCompletionInterface;
use OpenAI;
use OpenAI\Client;

class OpenWebUiCompletionService implements AiCompletionInterface
{
    private function getClient(): Client
    {
        return OpenAI::factory()
            ->withApiKey(config('openwebui-api-laravel.api_key'))
            ->withBaseUri(config('openwebui-api-laravel.base_api_url'))
            ->make();
    }

    public function getCompletion(string $input): string
    {
        $input .= 'Sag bei deiner Antwort, dass du von OpenWebUi generiert hast.';

        try {
            $result = $this->getClient()->chat()->create([
                'model' => config('openwebui-api-laravel.default_model'),
                'messages' => [['role' => 'user', 'content' => $input]],
            ]);

            if (! isset($result->choices[0]->message->content)) {
                throw new \Exception('Ungültige Response-Struktur von OpenWebUI API: '.json_encode($result));
            }

            return $result->choices[0]->message->content;
        } catch (\OpenAI\Exceptions\ErrorException $e) {
            $errorMessage = $e->getMessage();
            if (str_contains($errorMessage, 'Model not found') || str_contains($errorMessage, 'model not found')) {
                throw new \Exception('OpenWebUI Modell "'.config('openwebui-api-laravel.default_model').'" nicht gefunden. Bitte prüfe die Konfiguration oder verwende ein anderes Modell.');
            }

            throw new \Exception('OpenWebUI API Fehler: '.$errorMessage);
        }
    }
}
