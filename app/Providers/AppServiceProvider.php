<?php

namespace App\Providers;

use App\Models\Conversation;
use App\Models\ConversationParticipant;
use App\Models\Message;
use App\Services\ConversationBroadcaster;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->broadcastChatUpdates();
    }

    /**
     * Live chat: push "something changed" signals over Reverb once the
     * write is committed, so clients never re-fetch ahead of the data.
     */
    protected function broadcastChatUpdates(): void
    {
        $broadcaster = fn (): ConversationBroadcaster => app(ConversationBroadcaster::class);

        Message::created(fn (Message $message) => DB::afterCommit(
            fn () => $broadcaster()->messageCreated($message->conversation_id, $message->id)
        ));

        Conversation::updated(fn (Conversation $conversation) => DB::afterCommit(
            fn () => $broadcaster()->inboxChanged($conversation->id)
        ));

        ConversationParticipant::saved(fn (ConversationParticipant $participant) => DB::afterCommit(
            fn () => $broadcaster()->inboxChanged($participant->conversation_id, [$participant->user_id])
        ));
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
