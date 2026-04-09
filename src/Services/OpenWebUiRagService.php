<?php

namespace Hwkdo\OpenwebuiApiLaravel\Services;

use Illuminate\Support\Facades\Http;

class OpenWebUiRagService
{
    protected string $url;

    public function __construct()
    {
        $this->url = config('openwebui-api-laravel.base_api_url');
    }

    /**
     * Erstellt jedes Mal einen frischen PendingRequest.
     * Ein gespeichertes PendingRequest-Objekt würde durch verkettete Methoden wie
     * asJson(), withQueryParameters() etc. permanent mutiert (Laravel nutzt tap($this, ...)),
     * was bei Singletons zu akkumulierten Query-Params und falschen Content-Types führt.
     */
    private function client(int $timeout = 30, ?string $bearerToken = null, ?int $connectTimeoutSeconds = null): \Illuminate\Http\Client\PendingRequest
    {
        $token = $bearerToken ?? config('openwebui-api-laravel.api_key');

        $request = Http::withHeaders([
            'Authorization' => 'Bearer '.$token,
            'Accept' => 'application/json',
        ])->timeout($timeout);

        if ($connectTimeoutSeconds !== null && $connectTimeoutSeconds > 0) {
            $request = $request->connectTimeout($connectTimeoutSeconds);
        }

        return $request;
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
    public function uploadFile(string $filePath, bool $process = true, bool $processInBackground = true, ?string $bearerToken = null): array
    {
        if (! file_exists($filePath)) {
            throw new \Exception("Datei nicht gefunden: {$filePath}");
        }

        $token = $bearerToken ?? config('openwebui-api-laravel.api_key');

        // Query-Params direkt in die URL einbauen – withQueryParameters() nach attach()
        // kann den Attachment-State im PendingRequest verlieren.
        // WICHTIG: process muss explizit als "false" gesendet werden. array_filter(null)
        // ließ den Param zuvor weg → Open WebUI hat standardmäßig RAG/Text-Extraktion
        // ausgeführt (bei Scan-PDFs: leerer Text → ValueError EMPTY_CONTENT).
        $query = http_build_query([
            'process' => $process ? 'true' : 'false',
            'process_in_background' => $processInBackground ? 'true' : 'false',
        ]);
        $url = $this->url.'/v1/files/?'.$query;

        $fileContents = file_get_contents($filePath);
        if ($fileContents === false || $fileContents === '') {
            throw new \Exception("Datei ist leer oder nicht lesbar: {$filePath}");
        }

        // Frisches Http-Objekt – gespeicherter PendingRequest ($this->client) kann
        // bei Multipart-Uploads internen State aus vorherigen Anfragen mitschleppen.
        $result = Http::withHeaders([
            'Authorization' => 'Bearer '.$token,
            'Accept' => 'application/json',
        ])
            ->attach('file', $fileContents, basename($filePath))
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
        $result = $this->client()->get($this->url.'/v1/files/'.$fileId.'/process/status', [
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
        $result = $this->client()->asJson()->post($this->url.'/v1/knowledge/'.$knowledgeId.'/file/add', [
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
     * Sendet eine Chat Completion mit mehreren Dateien
     *
     * @param  string  $model  Das zu verwendende Modell
     * @param  array  $messages  Die Chat-Nachrichten
     * @param  string[]  $fileIds  Die IDs der Dateien
     *
     * @throws \Exception
     */
    public function chatWithFiles(string $model, array $messages, array $fileIds): array
    {
        $files = array_map(fn (string $id) => ['type' => 'file', 'id' => $id], $fileIds);

        $result = $this->client(300)->asJson()->post($this->url.'/chat/completions', [
            'model' => $model,
            'messages' => $messages,
            'files' => $files,
        ]);

        if (! $result->successful()) {
            throw new \Exception('Chat Completion fehlgeschlagen: '.$result->status().' - '.$result->body());
        }

        return $result->json();
    }

    /**
     * Vision ohne OWUI-„files“-RAG: Open WebUI hängt bei {@see chatWithFile} Dateien an die RAG-Pipeline
     * ({@code chat_completion_files_handler}) und kann völlig fremden Kontext aus der Vector-DB in den Prompt
     * injizieren — das Modell sieht dann oft kein echtes Bild. Diese Methode entspricht eher der UI: eine
     * User-Nachricht mit Text + data-URL-Bild (OpenAI-Multimodal), Ollama erhält Base64 in {@code images}.
     *
     * @param  array<string, mixed>  $additionalPayload  Zusätzliche Top-Level-Felder; {@code params} wird mit num_ctx gemerged (OWUI setzt daraus Ollama-options)
     * @param  int|null  $httpRequestTimeoutSeconds  Gesamt-Timeout für die HTTP-Anfrage (null = vision_chat_timeout aus Config)
     * @param  int|null  $httpConnectTimeoutSeconds  Connect-Timeout (null = vision_chat_connect_timeout aus Config)
     *
     * @throws \Exception
     */
    public function chatWithImageFilePath(
        string $model,
        string $userTextPrompt,
        string $absoluteImagePath,
        ?string $bearerToken = null,
        array $additionalPayload = [],
        ?int $httpRequestTimeoutSeconds = null,
        ?int $httpConnectTimeoutSeconds = null,
    ): array {
        if (! is_readable($absoluteImagePath)) {
            throw new \Exception('Bilddatei nicht lesbar: '.$absoluteImagePath);
        }

        $raw = file_get_contents($absoluteImagePath);
        if ($raw === false || $raw === '') {
            throw new \Exception('Bilddatei ist leer oder nicht lesbar.');
        }

        $mime = @mime_content_type($absoluteImagePath);
        if (! is_string($mime) || ! str_starts_with($mime, 'image/')) {
            $mime = match (strtolower((string) pathinfo($absoluteImagePath, PATHINFO_EXTENSION))) {
                'png' => 'image/png',
                'jpg', 'jpeg' => 'image/jpeg',
                'webp' => 'image/webp',
                'gif' => 'image/gif',
                default => 'image/png',
            };
        }

        $dataUrl = 'data:'.$mime.';base64,'.base64_encode($raw);

        $messages = [
            [
                'role' => 'user',
                'content' => [
                    ['type' => 'text', 'text' => $userTextPrompt],
                    ['type' => 'image_url', 'image_url' => ['url' => $dataUrl]],
                ],
            ],
        ];

        return $this->postVisionChatCompletion($model, $messages, $bearerToken, $additionalPayload, $httpRequestTimeoutSeconds, $httpConnectTimeoutSeconds);
    }

    /**
     * Sendet eine Chat Completion mit einer einzelnen Datei (OpenWebUI-Dateireferenz — triggert ggf. RAG).
     *
     * @param  array<string, mixed>  $additionalPayload  Zusätzliche Felder; {@code params} wird mit num_ctx gemerged
     *
     * @throws \Exception
     */
    public function chatWithFile(
        string $model,
        array $messages,
        string $fileId,
        ?string $bearerToken = null,
        array $additionalPayload = [],
    ): array {
        // Open Web UI (Ollama): request-„params“ werden zu form_data.options; Top-Level „options“ kann dabei mit [] überschrieben werden.
        $params = array_merge(
            [
                'num_ctx' => (int) config('openwebui-api-laravel.ocr_num_ctx', 32768),
            ],
            $additionalPayload['params'] ?? [],
        );
        unset($additionalPayload['params']);

        $body = array_merge([
            'model' => $model,
            'messages' => $messages,
            'files' => [
                ['type' => 'file', 'id' => $fileId],
            ],
            'stream' => false,
            'max_tokens' => (int) config('openwebui-api-laravel.ocr_max_tokens', 8192),
            'params' => $params,
        ], $additionalPayload);

        return $this->postChatCompletionsJson($body, $bearerToken);
    }

    /**
     * @param  array<string, mixed>  $messages  OpenAI-kompatible messages (inkl. Multimodal-Content)
     * @param  array<string, mixed>  $additionalPayload
     * @param  int|null  $httpRequestTimeoutSeconds  null = vision_chat_timeout aus Config
     * @param  int|null  $httpConnectTimeoutSeconds  null = vision_chat_connect_timeout aus Config
     *
     * @throws \Exception
     */
    public function postVisionChatCompletion(
        string $model,
        array $messages,
        ?string $bearerToken = null,
        array $additionalPayload = [],
        ?int $httpRequestTimeoutSeconds = null,
        ?int $httpConnectTimeoutSeconds = null,
    ): array {
        $params = array_merge(
            [
                'num_ctx' => (int) config('openwebui-api-laravel.ocr_num_ctx', 32768),
            ],
            $additionalPayload['params'] ?? [],
        );
        unset($additionalPayload['params']);

        $body = array_merge([
            'model' => $model,
            'messages' => $messages,
            'stream' => false,
            'max_tokens' => (int) config('openwebui-api-laravel.ocr_max_tokens', 8192),
            'params' => $params,
        ], $additionalPayload);

        return $this->postChatCompletionsJson($body, $bearerToken, $httpRequestTimeoutSeconds, $httpConnectTimeoutSeconds);
    }

    /**
     * @param  array<string, mixed>  $body
     * @param  int|null  $requestTimeoutSeconds  null = vision_chat_timeout aus Config
     * @param  int|null  $connectTimeoutSeconds  null = vision_chat_connect_timeout aus Config
     *
     * @throws \Exception
     */
    protected function postChatCompletionsJson(
        array $body,
        ?string $bearerToken = null,
        ?int $requestTimeoutSeconds = null,
        ?int $connectTimeoutSeconds = null,
    ): array {
        $timeout = $requestTimeoutSeconds ?? (int) config('openwebui-api-laravel.vision_chat_timeout', 600);
        $connect = $connectTimeoutSeconds ?? (int) config('openwebui-api-laravel.vision_chat_connect_timeout', 30);
        $connectForClient = $connect > 0 ? $connect : null;

        $result = $this->client($timeout, $bearerToken, $connectForClient)->asJson()->post($this->url.'/chat/completions', $body);

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
        $result = $this->client(300)->asJson()->post($this->url.'/chat/completions', [
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
    public function deleteFile(string $fileId, ?string $bearerToken = null): void
    {
        $result = $this->client(30, $bearerToken)->delete($this->url.'/v1/files/'.$fileId);

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

        $result = $this->client()->asJson()
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
            $result = $this->client()->withQueryParameters(['knowledge_id' => $knowledgeId])
                ->post($url);
        } else {
            $result = $this->client()->post($url);
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
            $result = $this->client()->withQueryParameters(['knowledge_id' => $knowledgeId])
                ->post($url);
        } else {
            $result = $this->client()->post($url);
        }

        if (! $result->successful()) {
            throw new \Exception('Reindexierung der Knowledge Collection Metadaten fehlgeschlagen: '.$result->status().' - '.$result->body());
        }

        return $result->json();
    }
}
