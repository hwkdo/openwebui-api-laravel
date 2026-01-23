<?php

namespace Hwkdo\OpenwebuiApiLaravel\Services;

use Illuminate\Support\Facades\Http;

class OpenWebUiRagService
{
    protected string $url;

    protected \Illuminate\Http\Client\PendingRequest $client;

    public function __construct()
    {
        $this->url = config('openwebui-api-laravel.base_api_url');
        $this->client = Http::withHeaders([
            'Authorization' => 'Bearer '.config('openwebui-api-laravel.api_key'),
            'Accept' => 'application/json',
        ]);
    }

    /**
     * Lädt eine Datei hoch und gibt die File-ID zurück
     *
     * @param  string  $filePath  Pfad zur hochzuladenden Datei
     * @param  bool  $process  Ob der Inhalt extrahiert und Embeddings berechnet werden sollen
     * @param  bool  $processInBackground  Ob die Verarbeitung asynchron erfolgen soll
     *
     * @throws \Exception
     */
    public function uploadFile(string $filePath, bool $process = true, bool $processInBackground = true): array
    {
        if (! file_exists($filePath)) {
            throw new \Exception("Datei nicht gefunden: {$filePath}");
        }

        $url = $this->url.'/v1/files/';

        $result = $this->client->asMultipart()
            ->withQueryParameters([
                'process' => $process,
                'process_in_background' => $processInBackground,
            ])
            ->attach('file', file_get_contents($filePath), basename($filePath))
            ->post($url);

        if (! $result->successful()) {
            throw new \Exception('Upload fehlgeschlagen: '.$result->status().' - '.$result->body());
        }

        return $result->json();
    }

    /**
     * Prüft den Verarbeitungsstatus einer hochgeladenen Datei
     *
     * @param  string  $fileId  Die ID der hochgeladenen Datei
     * @param  bool  $stream  Ob ein SSE-Stream zurückgegeben werden soll
     *
     * @throws \Exception
     */
    public function getFileProcessingStatus(string $fileId, bool $stream = false): array
    {
        $result = $this->client->get($this->url.'/v1/files/'.$fileId.'/process/status', [
            'stream' => $stream,
        ]);

        if (! $result->successful()) {
            throw new \Exception('Status-Abfrage fehlgeschlagen: '.$result->status().' - '.$result->body());
        }

        return $result->json();
    }

    /**
     * Wartet auf den Abschluss der Dateiverarbeitung
     *
     * @param  string  $fileId  Die ID der hochgeladenen Datei
     * @param  int  $timeout  Timeout in Sekunden
     * @param  int  $pollInterval  Abfrageintervall in Sekunden
     *
     * @throws \Exception
     */
    public function waitForFileProcessing(string $fileId, int $timeout = 300, int $pollInterval = 2): array
    {
        $startTime = time();

        while (time() - $startTime < $timeout) {
            $status = $this->getFileProcessingStatus($fileId);
            $statusValue = $status['status'] ?? null;

            if ($statusValue === 'completed') {
                return $status;
            }

            if ($statusValue === 'failed') {
                throw new \Exception('Dateiverarbeitung fehlgeschlagen: '.($status['error'] ?? 'Unbekannter Fehler'));
            }

            sleep($pollInterval);
        }

        throw new \Exception("Timeout: Dateiverarbeitung wurde nicht innerhalb von {$timeout} Sekunden abgeschlossen");
    }

    /**
     * Fügt eine Datei zu einer Knowledge Collection hinzu
     *
     * @param  string  $knowledgeId  Die ID der Knowledge Collection
     * @param  string  $fileId  Die ID der hochgeladenen Datei
     *
     * @throws \Exception
     */
    public function addFileToKnowledge(string $knowledgeId, string $fileId): array
    {
        $result = $this->client->asJson()->post($this->url.'/v1/knowledge/'.$knowledgeId.'/file/add', [
            'file_id' => $fileId,
        ]);

        if (! $result->successful()) {
            $errorBody = $result->body();
            $errorMessage = 'Hinzufügen zur Knowledge Collection fehlgeschlagen: '.$result->status().' - '.$errorBody;

            // Wenn "Duplicate content" kommt, bedeutet das, dass der Inhalt bereits verarbeitet wurde
            // Aber die Datei selbst könnte trotzdem nicht in der Collection sein
            // Wir werfen eine spezielle Exception, damit der Caller entscheiden kann, wie damit umzugehen ist
            if (str_contains($errorBody, 'Duplicate content') || str_contains($errorBody, 'duplicate')) {
                throw new \Exception('Duplicate content: '.$errorMessage);
            }

            throw new \Exception($errorMessage);
        }

        return $result->json();
    }

    /**
     * Lädt eine Datei hoch, wartet auf die Verarbeitung und fügt sie zu einer Knowledge Collection hinzu
     *
     * @param  string  $filePath  Pfad zur hochzuladenden Datei
     * @param  string  $knowledgeId  Die ID der Knowledge Collection
     * @param  int  $timeout  Timeout für die Verarbeitung in Sekunden
     *
     * @throws \Exception
     */
    public function uploadAndAddToKnowledge(string $filePath, string $knowledgeId, int $timeout = 300): array
    {
        // Schritt 1: Datei hochladen
        $uploadResult = $this->uploadFile($filePath);
        $fileId = $uploadResult['id'] ?? null;

        if (! $fileId) {
            throw new \Exception('Keine File-ID in Upload-Response: '.json_encode($uploadResult));
        }

        // Schritt 2: Auf Verarbeitung warten
        $this->waitForFileProcessing($fileId, $timeout);

        // Schritt 3: Zu Knowledge Collection hinzufügen
        return $this->addFileToKnowledge($knowledgeId, $fileId);
    }

    /**
     * Sendet eine Chat Completion mit einer einzelnen Datei
     *
     * @param  string  $model  Das zu verwendende Modell
     * @param  array  $messages  Die Chat-Nachrichten
     * @param  string  $fileId  Die ID der Datei
     *
     * @throws \Exception
     */
    public function chatWithFile(string $model, array $messages, string $fileId): array
    {
        $result = $this->client->asJson()->post($this->url.'/chat/completions', [
            'model' => $model,
            'messages' => $messages,
            'files' => [
                ['type' => 'file', 'id' => $fileId],
            ],
        ]);

        if (! $result->successful()) {
            throw new \Exception('Chat Completion fehlgeschlagen: '.$result->status().' - '.$result->body());
        }

        return $result->json();
    }

    /**
     * Sendet eine Chat Completion mit einer Knowledge Collection
     *
     * @param  string  $model  Das zu verwendende Modell
     * @param  array  $messages  Die Chat-Nachrichten
     * @param  string  $collectionId  Die ID der Knowledge Collection
     *
     * @throws \Exception
     */
    public function chatWithCollection(string $model, array $messages, string $collectionId): array
    {
        $result = $this->client->asJson()->post($this->url.'/chat/completions', [
            'model' => $model,
            'messages' => $messages,
            'files' => [
                ['type' => 'collection', 'id' => $collectionId],
            ],
        ]);

        if (! $result->successful()) {
            throw new \Exception('Chat Completion fehlgeschlagen: '.$result->status().' - '.$result->body());
        }

        return $result->json();
    }

    /**
     * Löscht eine Datei in OpenWebUI
     *
     * @param  string  $fileId  Die ID der zu löschenden Datei
     *
     * @throws \Exception
     */
    public function deleteFile(string $fileId): void
    {
        $result = $this->client->delete($this->url.'/v1/files/'.$fileId);

        if (! $result->successful()) {
            throw new \Exception('Löschen der Datei fehlgeschlagen: '.$result->status().' - '.$result->body());
        }
    }

    /**
     * Entfernt eine Datei aus einer Knowledge Collection und löscht sie optional
     *
     * @param  string  $knowledgeId  Die ID der Knowledge Collection
     * @param  string  $fileId  Die ID der zu entfernenden Datei
     * @param  bool  $deleteFile  Ob die Datei auch gelöscht werden soll (Standard: true)
     *
     * @throws \Exception
     */
    public function removeFileFromKnowledge(string $knowledgeId, string $fileId, bool $deleteFile = true): array
    {
        $url = $this->url.'/v1/knowledge/'.$knowledgeId.'/file/remove';

        $result = $this->client->asJson()
            ->withQueryParameters(['delete_file' => $deleteFile])
            ->post($url, [
                'file_id' => $fileId,
            ]);

        if (! $result->successful()) {
            throw new \Exception('Entfernen der Datei aus Knowledge Collection fehlgeschlagen: '.$result->status().' - '.$result->body());
        }

        return $result->json();
    }

    /**
     * Reindiziert die Dateien einer Knowledge Collection
     * Hinweis: Laut OpenAPI-Spezifikation akzeptiert dieser Endpunkt keine Parameter
     * und reindiziert alle Knowledge Bases. Falls eine spezifische Collection reindiziert
     * werden soll, wird die knowledge_id als Query-Parameter übergeben (falls unterstützt).
     *
     * @param  string|null  $knowledgeId  Optional: Die ID der Knowledge Collection
     *
     * @throws \Exception
     */
    public function reindexKnowledgeFiles(?string $knowledgeId = null): bool
    {
        $url = $this->url.'/v1/knowledge/reindex';

        if ($knowledgeId) {
            $result = $this->client->withQueryParameters(['knowledge_id' => $knowledgeId])
                ->post($url);
        } else {
            $result = $this->client->post($url);
        }

        if (! $result->successful()) {
            throw new \Exception('Reindexierung der Knowledge Collection fehlgeschlagen: '.$result->status().' - '.$result->body());
        }

        return $result->json() === true || $result->json() === 'true';
    }

    /**
     * Reindiziert die Metadaten-Embeddings einer Knowledge Collection
     * Hinweis: Laut OpenAPI-Spezifikation akzeptiert dieser Endpunkt keine Parameter
     * und reindiziert alle Knowledge Bases. Falls eine spezifische Collection reindiziert
     * werden soll, wird die knowledge_id als Query-Parameter übergeben (falls unterstützt).
     *
     * @param  string|null  $knowledgeId  Optional: Die ID der Knowledge Collection
     *
     * @throws \Exception
     */
    public function reindexKnowledgeMetadata(?string $knowledgeId = null): array
    {
        $url = $this->url.'/v1/knowledge/metadata/reindex';

        if ($knowledgeId) {
            $result = $this->client->withQueryParameters(['knowledge_id' => $knowledgeId])
                ->post($url);
        } else {
            $result = $this->client->post($url);
        }

        if (! $result->successful()) {
            throw new \Exception('Reindexierung der Knowledge Collection Metadaten fehlgeschlagen: '.$result->status().' - '.$result->body());
        }

        return $result->json();
    }
}
