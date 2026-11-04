<?php

declare(strict_types=1);

namespace Hwkdo\OpenwebuiApiLaravel\Services;

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class OpenWebUiModelService
{
    /**
     * Modelle, die dieser OpenWebUI-Token sehen darf.
     *
     * @return list<array{id: string, name: string}>
     */
    public function listForToken(string $token): array
    {
        $token = trim($token);
        if ($token === '') {
            throw new RuntimeException('Der OpenWebUI-Token fehlt.');
        }

        $url = rtrim((string) config('openwebui-api-laravel.base_api_url'), '/').'/models';

        try {
            $response = Http::withToken($token)
                ->acceptJson()
                ->timeout(20)
                ->get($url)
                ->throw();
        } catch (RequestException $exception) {
            throw new RuntimeException($this->failureMessage($exception), previous: $exception);
        }

        $payload = $response->json();
        $rows = is_array($payload) ? ($payload['data'] ?? $payload) : [];
        if (! is_array($rows)) {
            return [];
        }

        $models = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $id = trim((string) ($row['id'] ?? ''));
            if ($id === '' || isset($models[$id])) {
                continue;
            }

            $name = trim((string) ($row['name'] ?? ''));
            $models[$id] = [
                'id' => $id,
                'name' => $name !== '' ? $name : $id,
            ];
        }

        $models = array_values($models);
        usort($models, fn (array $left, array $right): int => strcasecmp($left['name'], $right['name']));

        return $models;
    }

    private function failureMessage(RequestException $exception): string
    {
        $detail = $exception->response?->json('detail');
        if (is_string($detail) && $detail !== '') {
            return $detail;
        }

        return 'Die Modellliste von OpenWebUI konnte nicht geladen werden.';
    }
}
