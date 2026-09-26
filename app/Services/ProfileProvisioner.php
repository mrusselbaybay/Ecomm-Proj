<?php

namespace App\Services;

use App\Models\Profile;
use Illuminate\Support\Facades\DB;

/**
 * Creates the app-database `profiles` row for a new Supabase Auth user.
 *
 * Replaces the Postgres `handle_new_user()` trigger on auth.users, which
 * can't reach the app database now that it isn't Supabase's Postgres.
 * Written with the query builder (like the trigger) so staff/admin roles
 * set server-side aren't blocked by Profile's HTTP admin-role guard.
 */
class ProfileProvisioner
{
    private const ROLES = [
        'buyer', 'seller', 'driver', 'courier', 'logistics', 'admin', 'logistics_admin',
        ...Profile::REGISTRABLE_ROLES,
    ];

    /**
     * @param  array<string, mixed>  $metadata   the user_metadata sent to Supabase
     * @param  array<string, mixed>  $overrides  explicit column values (e.g. approved staff)
     */
    public function provision(string $userId, ?string $email, array $metadata, array $overrides = []): void
    {
        $blankToNull = fn ($value) => $value === '' ? null : $value;
        $role = $metadata['role'] ?? null;

        $row = [
            'role' => in_array($role, self::ROLES, true) ? $role : 'buyer',
            'status' => 'pending',
            'email' => $email,
            'first_name' => $metadata['first_name'] ?? null,
            'last_name' => $metadata['last_name'] ?? null,
            'middle_initial' => $metadata['middle_initial'] ?? null,
            'sex' => in_array($metadata['sex'] ?? null, ['Male', 'Female'], true) ? $metadata['sex'] : null,
            'contact_no' => $blankToNull($metadata['contact_no'] ?? null),
            'birthday' => $blankToNull($metadata['birthday'] ?? null),
        ];

        $this->upsert(['id' => $userId, ...$row, ...$overrides]);
    }

    /** Insert or merge a profiles row keyed on id (PostgREST upsert semantics). */
    public function upsert(array $row): void
    {
        $id = $row['id'];
        unset($row['id']);

        $now = now();
        $exists = DB::table('profiles')->where('id', $id)->exists();

        $exists
            ? DB::table('profiles')->where('id', $id)->update([...$row, 'updated_at' => $now])
            : DB::table('profiles')->insert(['id' => $id, ...$row, 'created_at' => $now, 'updated_at' => $now]);
    }

    /** Remove a profile (and, via FK cascades, its addresses/details/documents). */
    public function remove(string $userId): void
    {
        DB::table('profiles')->where('id', $userId)->delete();
    }
}
