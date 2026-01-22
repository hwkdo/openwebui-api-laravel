<?php

namespace Hwkdo\OpenwebuiApiLaravel\Services;

use App\Models\User;
use Illuminate\Support\Facades\Http;

class OpenWebUiUserService
{
    protected string $baseUrl;

    public function __construct()
    {
        $this->baseUrl = config('openwebui-api-laravel.base_api_url');
    }

    /**
     * Ruft die aktuellen User-Settings aus OpenWebUI ab
     *
     * @param  string  $userToken  Der OpenWebUI API Token des Users
     *
     * @throws \Exception
     */
    public function getUserSettings(string $userToken): array
    {
        $url = $this->baseUrl.'/v1/users/user/settings';

        $response = Http::withHeaders([
            'Authorization' => 'Bearer '.$userToken,
            'Accept' => 'application/json',
        ])
            ->get($url);

        if (! $response->successful()) {
            throw new \Exception('OpenWebUI User-Settings-Abruf fehlgeschlagen: '.$response->status().' - '.$response->body());
        }

        return $response->json() ?? [];
    }

    /**
     * Aktualisiert die User-Settings in OpenWebUI
     *
     * @param  string  $userToken  Der OpenWebUI API Token des Users
     * @param  array  $settings  Die Settings, die aktualisiert werden sollen
     *
     * @throws \Exception
     */
    public function updateUserSettings(string $userToken, array $settings): array
    {
        $url = $this->baseUrl.'/v1/users/user/settings/update';

        $response = Http::withHeaders([
            'Authorization' => 'Bearer '.$userToken,
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ])
            ->asJson()
            ->post($url, $settings);

        if (! $response->successful()) {
            throw new \Exception('OpenWebUI Settings-Update fehlgeschlagen: '.$response->status().' - '.$response->body());
        }

        return $response->json();
    }

    /**
     * Setzt den System-Prompt für einen User in OpenWebUI
     *
     * @param  User  $user  Der User, für den der System-Prompt gesetzt werden soll
     *
     * @throws \Exception
     */
    public function setSystemPrompt(User $user): void
    {
        // Prüfe, ob der User einen OpenWebUI API Token hat
        $userToken = $user->settings->ai->openWebUiApiToken ?? null;

        if (! $userToken) {
            throw new \Exception('User hat keinen OpenWebUI API Token - kann System-Prompt nicht setzen');
        }

        // Zuerst aktuelle Settings abrufen, um bestehende Werte zu behalten
        $currentSettings = $this->getUserSettings($userToken);

        // System-Prompt generieren
        $systemPrompt = $this->generateSystemPrompt($user);

        // Bestehende UI-Settings übernehmen und nur "system" ändern
        $existingUi = $currentSettings['ui'] ?? [];
        $existingUi['system'] = $systemPrompt;

        $settings = [
            'ui' => $existingUi,
        ];

        $this->updateUserSettings($userToken, $settings);
    }

    /**
     * Generiert den System-Prompt basierend auf User-Daten
     *
     * @param  User  $user  Der User
     */
    protected function generateSystemPrompt(User $user): string
    {
        $template = config('openwebui-api-laravel.system_prompt_template');
        $gvpTemplate = config('openwebui-api-laravel.system_prompt_gvp_template');

        // GVP-Teil generieren, falls vorhanden
        $gvpPart = '';
        if ($user->gvp) {
            $gvpPart = str_replace(
                '{gvp_bezeichnung}',
                $user->gvp->bezeichnung,
                $gvpTemplate
            );
        }

        // Haupt-Template mit User-Daten füllen
        $prompt = str_replace(
            ['{vorname}', '{nachname}', '{gvp_part}'],
            [$user->vorname ?? '', $user->nachname ?? '', $gvpPart],
            $template
        );

        return $prompt;
    }
}
