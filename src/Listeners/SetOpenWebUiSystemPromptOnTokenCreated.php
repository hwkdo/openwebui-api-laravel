<?php

namespace Hwkdo\OpenwebuiApiLaravel\Listeners;

use App\Models\User;
use Hwkdo\OpenwebuiApiLaravel\Services\OpenWebUiUserService;
use Illuminate\Support\Facades\Log;
use Laravel\Passport\Events\AccessTokenCreated;

class SetOpenWebUiSystemPromptOnTokenCreated
{
    public function __construct(
        protected OpenWebUiUserService $openWebUiUserService
    ) {}

    /**
     * Handle the event.
     */
    public function handle(AccessTokenCreated $event): void
    {
        if (! $event->userId) {
            return;
        }

        /** @var User|null $user */
        $user = User::find($event->userId);

        if (! $user) {
            return;
        }

        try {
            $this->openWebUiUserService->setSystemPrompt($user);
        } catch (\Exception $e) {
            // Fehler loggen, aber Event nicht blockieren
            Log::warning('OpenWebUI System-Prompt konnte nicht gesetzt werden: '.$e->getMessage());
        }
    }
}
