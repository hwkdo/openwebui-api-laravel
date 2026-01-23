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
        $result = $this->getClient()->chat()->create([
            'model' => config('openwebui-api-laravel.default_model'),
            'messages' => [['role' => 'user', 'content' => $input]],
        ]);

        return $result;
        #return $result->choices[0]->message->content;
    }
}
