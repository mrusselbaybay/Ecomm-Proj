<?php

namespace App\Console\Commands;

use App\Services\ProfileProvisioner;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * One-time import of Supabase Auth accounts into `profiles`: bcrypt password
 * hashes (checked by Laravel as-is, so users keep their passwords), Google
 * identities and email-verified dates. Safe to re-run.
 */
class ImportSupabaseAuth extends Command
{
    protected $signature = 'db:import-supabase-auth {--source=supabase_source}';

    protected $description = 'Import Supabase Auth users (passwords, Google identities) into profiles';

    public function handle(ProfileProvisioner $provisioner): int
    {
        $source = DB::connection($this->option('source'));

        $users = $source->select(<<<'SQL'
            select u.id, u.email, u.encrypted_password, u.email_confirmed_at,
                   u.raw_app_meta_data->>'provider' as provider,
                   u.raw_user_meta_data::text as metadata,
                   (select i.provider_id from auth.identities i
                     where i.user_id = u.id and i.provider = 'google' limit 1) as google_id
            from auth.users u
        SQL);

        $created = 0;

        foreach ($users as $user) {
            if (! DB::table('profiles')->where('id', $user->id)->exists()) {
                // Auth account that never got a profile (e.g. unfinished Google signup).
                $provisioner->provision($user->id, $user->email, json_decode($user->metadata ?? '{}', true) ?: []);
                $created++;
            }

            $hasPassword = is_string($user->encrypted_password) && str_starts_with($user->encrypted_password, '$2');

            DB::table('profiles')->where('id', $user->id)->update([
                // Supabase writes bcrypt as $2a$; PHP/Laravel expect the equivalent $2y$ prefix.
                'password' => $hasPassword ? preg_replace('/^\$2[ab]\$/', '\$2y\$', $user->encrypted_password) : null,
                'auth_provider' => $user->provider === 'google' ? 'google' : 'email',
                'google_id' => $user->google_id,
                'email_verified_at' => $user->email_confirmed_at
                    ? CarbonImmutable::parse($user->email_confirmed_at)->utc()->format('Y-m-d H:i:s')
                    : null,
                'email' => DB::raw('COALESCE(email, '.DB::getPdo()->quote((string) $user->email).')'),
            ]);
        }

        $this->info(sprintf('Imported %d auth accounts (%d new stub profiles).', count($users), $created));

        return self::SUCCESS;
    }
}
