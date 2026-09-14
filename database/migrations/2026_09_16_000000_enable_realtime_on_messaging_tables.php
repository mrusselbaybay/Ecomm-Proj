<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Enables Supabase Realtime (postgres_changes) for buyer/seller/logistics/
 * driver messaging: adds `conversations` and `messages` to the
 * `supabase_realtime` publication, and enables row-level security on both
 * so a browser subscribing directly with the signed-in user's own Supabase
 * session (the anon/authenticated Postgres role) only ever receives rows
 * for conversations that user actually participates in.
 *
 * Laravel's own DB connection uses direct Postgres credentials (not the
 * anon/authenticated PostgREST roles Realtime evaluates these policies
 * against), so existing Eloquent queries are unaffected by RLS being
 * enabled here — see AuthenticateSupabaseUser's docblock for how the app's
 * own auth already relies on Supabase's auth server rather than PostgREST.
 *
 * Postgres-only: RLS/publications don't exist on the sqlite connection
 * used elsewhere in local/test setups (see conversation_participants'
 * own creation migration for the same guard).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE conversations ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE messages ENABLE ROW LEVEL SECURITY');

        // profiles.id *is* the Supabase auth user id (see
        // AuthenticateSupabaseUser::resolveSupabaseUserId() ->
        // Profile::find($supabaseUserId)), so auth.uid() can be compared to
        // it directly without a lookup.
        DB::statement(<<<'SQL'
            CREATE POLICY conversations_select_participant ON conversations
            FOR SELECT
            USING (
                EXISTS (
                    SELECT 1 FROM conversation_participants cp
                    WHERE cp.conversation_id = conversations.id
                      AND cp.user_id = auth.uid()
                      AND cp.left_at IS NULL
                )
            )
        SQL);

        DB::statement(<<<'SQL'
            CREATE POLICY messages_select_participant ON messages
            FOR SELECT
            USING (
                EXISTS (
                    SELECT 1 FROM conversation_participants cp
                    WHERE cp.conversation_id = messages.conversation_id
                      AND cp.user_id = auth.uid()
                      AND cp.left_at IS NULL
                )
            )
        SQL);

        DB::statement('ALTER PUBLICATION supabase_realtime ADD TABLE conversations, messages');
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER PUBLICATION supabase_realtime DROP TABLE conversations, messages');
        DB::statement('DROP POLICY IF EXISTS messages_select_participant ON messages');
        DB::statement('DROP POLICY IF EXISTS conversations_select_participant ON conversations');
        DB::statement('ALTER TABLE messages DISABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE conversations DISABLE ROW LEVEL SECURITY');
    }
};
