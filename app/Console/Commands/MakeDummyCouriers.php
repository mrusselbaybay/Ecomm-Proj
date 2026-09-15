<?php

namespace App\Console\Commands;

use App\Models\Address;
use App\Models\CourierApplication;
use App\Models\CourierDetail;
use App\Models\LogisticsCompany;
use App\Models\Profile;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Dev-only helper: creates real, logged-in-able courier accounts (Supabase
 * Auth user + profile, same admin-API path as
 * App\Http\Controllers\Admin\StaffAccountController) so barangay-assignment
 * / rider-picker work can be tested without hand-registering couriers
 * through the public signup form. Optionally files + accepts a
 * courier_application for a logistics company so the courier shows up
 * immediately in that company's "accepted riders" pool.
 */
class MakeDummyCouriers extends Command
{
    protected $signature = 'logistics:make-dummy-couriers
        {company? : Logistics company id or (partial, case-insensitive) name to accept the couriers into}
        {--count=5 : How many dummy couriers to create}
        {--password=Password123! : Login password for every created account}
        {--status=accepted : Application status when --company is given: accepted, pending, or none to skip the application entirely}';

    protected $description = 'Create dummy, login-capable courier accounts for local testing';

    private const FIRST_NAMES = ['Juan', 'Maria', 'Jose', 'Ana', 'Pedro', 'Rosa', 'Carlos', 'Liza', 'Mark', 'Grace'];
    private const LAST_NAMES = ['Santos', 'Reyes', 'Cruz', 'Bautista', 'Garcia', 'Torres', 'Mendoza', 'Flores', 'Ramos', 'Diaz'];
    private const VEHICLES = ['Motorcycle', 'Car', 'Van', 'Bicycle', 'Truck'];

    public function handle(): int
    {
        if (app()->environment('production')) {
            $this->error('Refusing to create dummy accounts in production.');

            return self::FAILURE;
        }

        $company = null;
        $companyArg = $this->argument('company');
        $status = $this->option('status');

        if ($companyArg) {
            $company = LogisticsCompany::query()
                ->when(
                    preg_match('/^[0-9a-f-]{36}$/i', $companyArg) === 1,
                    fn ($query) => $query->where('id', $companyArg),
                    fn ($query) => $query->whereRaw('LOWER(company_name) LIKE ?', ['%'.mb_strtolower($companyArg).'%']),
                )
                ->first();

            if (! $company) {
                $this->error("No logistics company matches \"{$companyArg}\".");

                return self::FAILURE;
            }

            $this->info("Accepting couriers into: {$company->company_name} ({$company->id})");
        } else {
            $this->warn('No company given — couriers will be created without any courier_application. Pass a company name/id to make them immediately assignable.');
        }

        $count = max(1, (int) $this->option('count'));
        $password = (string) $this->option('password');
        $created = [];
        $stamp = now()->format('mdHis');

        for ($i = 1; $i <= $count; $i++) {
            $firstName = self::FIRST_NAMES[array_rand(self::FIRST_NAMES)];
            $lastName = self::LAST_NAMES[array_rand(self::LAST_NAMES)];
            $email = "dummy.courier.{$stamp}.{$i}@example.test";
            $userId = null;

            try {
                $userId = $this->createSupabaseAuthUser($email, $password, [
                    'role' => 'courier',
                    'first_name' => $firstName,
                    'last_name' => $lastName,
                    'status' => 'approved',
                    'account_status' => 'active',
                ]);

                $this->activateProfile($userId, $email, $firstName, $lastName);

                Address::query()->create([
                    'owner_kind' => 'profile',
                    'profile_id' => $userId,
                    'region_name' => 'Luzon',
                    'province_code' => '043400000',
                    'province_name' => 'Laguna',
                    'municipality_code' => '043409000',
                    'municipality_name' => 'Kalayaan',
                    'barangay' => 'San Antonio',
                    'street' => 'Sample Street',
                    'house_no' => (string) random_int(1, 200),
                ]);

                CourierDetail::query()->create([
                    'profile_id' => $userId,
                    'vehicle' => self::VEHICLES[array_rand(self::VEHICLES)],
                    'plate_number' => strtoupper(substr(bin2hex(random_bytes(3)), 0, 6)),
                    'logistics_company_id' => $company?->id,
                ]);

                if ($company && $status !== 'none') {
                    CourierApplication::query()->create([
                        'courier_profile_id' => $userId,
                        'logistics_company_id' => $company->id,
                        'status' => $status === 'pending' ? CourierApplication::STATUS_PENDING : CourierApplication::STATUS_ACCEPTED,
                        'cover_note' => 'Dummy application created for local testing.',
                        'applied_at' => now(),
                        'reviewed_at' => $status === 'pending' ? null : now(),
                    ]);
                }

                $created[] = [$email, $password, "{$firstName} {$lastName}", $userId];
                $this->line("  ✓ {$email} ({$firstName} {$lastName})");
            } catch (Throwable $exception) {
                if ($userId) {
                    $this->deleteSupabaseAuthUser($userId);
                }

                $this->error("  ✗ Failed to create courier #{$i}: {$exception->getMessage()}");
                Log::error('Dummy courier creation failed.', ['error' => $exception->getMessage()]);
            }
        }

        if ($created === []) {
            $this->error('No dummy couriers were created.');

            return self::FAILURE;
        }

        $this->newLine();
        $this->table(['Email', 'Password', 'Name', 'Profile ID'], $created);

        return self::SUCCESS;
    }

    private function createSupabaseAuthUser(string $email, string $password, array $metadata): string
    {
        $response = $this->supabaseRequest()
            ->post($this->supabaseUrl('/auth/v1/admin/users'), [
                'email' => $email,
                'password' => $password,
                'email_confirm' => true,
                'user_metadata' => $metadata,
            ]);

        if (! $response->successful()) {
            $error = $response->json();

            throw new RuntimeException($error['msg'] ?? $error['message'] ?? 'Failed to create the Supabase auth user.');
        }

        $userId = $response->json('id');

        if (! is_string($userId) || $userId === '') {
            throw new RuntimeException('Supabase did not return a user id.');
        }

        return $userId;
    }

    private function activateProfile(string $userId, string $email, string $firstName, string $lastName): void
    {
        $response = $this->supabaseRequest()
            ->withHeader('Prefer', 'return=representation')
            ->patch($this->supabaseUrl("/rest/v1/profiles?id=eq.{$userId}"), [
                'role' => 'courier',
                'status' => 'approved',
                'account_status' => 'active',
                'first_name' => $firstName,
                'last_name' => $lastName,
                'email' => $email,
            ]);

        if (! $response->successful() || $response->json() === []) {
            throw new RuntimeException('Unable to activate the dummy courier profile.');
        }
    }

    private function deleteSupabaseAuthUser(string $userId): void
    {
        try {
            $this->supabaseRequest()->delete($this->supabaseUrl("/auth/v1/admin/users/{$userId}"));
        } catch (Throwable $exception) {
            Log::error('Failed to roll back dummy courier Supabase user.', [
                'user_id' => $userId,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    private function supabaseRequest()
    {
        $serviceRoleKey = config('services.supabase.service_role_key');

        if (! is_string($serviceRoleKey) || $serviceRoleKey === '') {
            throw new RuntimeException('Supabase service-role credentials are not configured.');
        }

        return Http::withHeaders([
            'apikey' => $serviceRoleKey,
            'Authorization' => "Bearer {$serviceRoleKey}",
            'Content-Type' => 'application/json',
        ]);
    }

    private function supabaseUrl(string $path): string
    {
        return rtrim((string) config('services.supabase.url'), '/').$path;
    }
}
