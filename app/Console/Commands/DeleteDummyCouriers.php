<?php

namespace App\Console\Commands;

use App\Models\Address;
use App\Models\CourierApplication;
use App\Models\CourierDetail;
use App\Models\LogisticsBarangayAssignment;
use App\Models\Profile;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;
use App\Services\AccountRegistrar;

/**
 * Dev-only counterpart to logistics:make-dummy-couriers — removes dummy
 * accounts (email like dummy.courier.%) cleanly: the Supabase auth user
 * (which also drops the profiles row via the DB trigger), courier_details,
 * addresses, courier_applications, and unassigns them from any barangay
 * they're covering. Real accounts (never matching the dummy.courier.%
 * prefix) are never touched regardless of --keep.
 */
class DeleteDummyCouriers extends Command
{
    protected $signature = 'logistics:delete-dummy-couriers
        {--keep=* : Profile ids or emails of dummy couriers to keep — everyone else matching dummy.courier.% is deleted}';

    protected $description = 'Delete dummy courier accounts created by logistics:make-dummy-couriers';

    public function handle(): int
    {
        if (app()->environment('production')) {
            $this->error('Refusing to delete accounts in production.');

            return self::FAILURE;
        }

        $keep = collect($this->option('keep'))->map(fn (string $v) => mb_strtolower(trim($v)));

        $toDelete = Profile::query()
            ->where('email', 'like', 'dummy.courier.%')
            ->get()
            ->reject(fn (Profile $p) => $keep->contains(mb_strtolower($p->id)) || $keep->contains(mb_strtolower($p->email)));

        if ($toDelete->isEmpty()) {
            $this->info('No dummy couriers to delete.');

            return self::SUCCESS;
        }

        $this->info("Deleting {$toDelete->count()} dummy courier(s)...");

        foreach ($toDelete as $profile) {
            try {
                LogisticsBarangayAssignment::query()
                    ->where('rider_profile_id', $profile->id)
                    ->update(['rider_profile_id' => null]);

                CourierApplication::query()->where('courier_profile_id', $profile->id)->delete();
                CourierDetail::query()->where('profile_id', $profile->id)->delete();
                Address::query()->where('owner_kind', 'profile')->where('profile_id', $profile->id)->delete();

                app(AccountRegistrar::class)->deleteUser($profile->id);

                $this->line("  \xE2\x9C\x93 {$profile->email} ({$profile->first_name} {$profile->last_name})");
            } catch (Throwable $exception) {
                $this->error("  \xE2\x9C\x97 Failed to delete {$profile->email}: {$exception->getMessage()}");
                Log::error('Dummy courier deletion failed.', ['profile_id' => $profile->id, 'error' => $exception->getMessage()]);
            }
        }

        return self::SUCCESS;
    }



}
