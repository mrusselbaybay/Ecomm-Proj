<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AuthController extends Controller
{
    public function index()
    {
        return view('auth.app', [
            'config' => [
                'supabase_url' => config('services.supabase.url'),
                'supabase_anon_key' => config('services.supabase.anon_key'),
            ]
        ]);
    }

    public function register(Request $request)
    {
        $request->validate([
            'email'    => 'required|email',
            'password' => 'required|string|min:8',
        ]);

        $response = Http::withHeaders([
            'apikey'        => config('services.supabase.service_role_key'),
            'Authorization' => 'Bearer ' . config('services.supabase.service_role_key'),
            'Content-Type'  => 'application/json',
        ])->post(config('services.supabase.url') . '/auth/v1/admin/users', [
            'email'            => strtolower(trim($request->email)),
            'password'         => $request->password,
            'email_confirm'    => true,
            'user_metadata'    => $request->except(['email', 'password']),
        ]);

        if (!$response->successful()) {
            Log::error('Supabase register failed', ['body' => $response->body()]);
            $error = $response->json();
            return response()->json([
                'message' => $error['msg'] ?? $error['message'] ?? 'Registration failed.',
            ], $response->status());
        }

        return response()->json([
            'message' => 'Registration successful.',
            'user'    => $response->json(),
        ], 201);
    }

    /**
     * Register a buyer/seller/courier/driver account.
     *
     * Mirrors what the frontend used to do with `supabaseAdmin` directly,
     * except the service role key now only ever lives on the server.
     */
    public function registerUser(Request $request)
    {
        $data = $request->validate([
            'email'    => 'required|email',
            'password' => 'required|string|min:8',
            'role'     => 'required|string|in:buyer,seller,courier,driver',

            'first_name'     => 'required|string',
            'last_name'      => 'required|string',
            'middle_initial' => 'nullable|string',
            'sex'            => 'nullable|string',
            'contact_no'     => 'nullable|string',
            'birthday'       => 'nullable|date',

            'province_code'      => 'nullable|string',
            'province_name'      => 'nullable|string',
            'municipality_code'  => 'nullable|string',
            'municipality_name'  => 'nullable|string',
            'barangay'           => 'nullable|string',
            'street'             => 'nullable|string',
            'house_no'           => 'nullable|string',

            // seller
            'business_name'    => 'required_if:role,seller|string',
            'line_of_business' => 'required_if:role,seller|string',

            // courier
            'vehicle'      => 'required_if:role,courier|string',
            'plate_number' => 'required_if:role,courier|string',

            // driver
            'driver_vehicle'        => 'required_if:role,driver|string',
            'driver_plate_number'   => 'required_if:role,driver|string',
            'driver_license_number' => 'nullable|string',

            // files
            'id_file'         => 'nullable|file|max:10240',
            'business_permit' => 'nullable|file|max:10240',
            'orcr_file'        => 'nullable|file|max:10240',
            'license_file'     => 'nullable|file|max:10240',
        ]);

        $email = strtolower(trim($data['email']));
        $userId = null;

        try {
            // 1. Create the Supabase Auth user (service-role only, server-side)
            $authUser = $this->createSupabaseAuthUser($email, $data['password'], [
                'role'           => $data['role'],
                'first_name'     => $data['first_name'],
                'last_name'      => $data['last_name'],
                'middle_initial' => $data['middle_initial'] ?? '',
                'sex'            => $data['sex'] ?? null,
                'contact_no'     => $data['contact_no'] ?? null,
                'birthday'       => $data['birthday'] ?? null,
                'status'         => 'pending',
            ]);

            $userId = $authUser['id'];

            // profiles row is expected to be created by your existing Postgres
            // trigger on auth.users insert (reading raw_user_meta_data) — same
            // as the old frontend flow relied on. No manual insert needed here.

            // 2. Address (optional)
            if (!empty($data['province_code']) && !empty($data['municipality_code']) && !empty($data['barangay'])) {
                $this->supabaseInsert('addresses', [
                    'owner_kind'         => 'profile',
                    'profile_id'         => $userId,
                    'province_code'      => $data['province_code'],
                    'province_name'      => $data['province_name'] ?? '',
                    'municipality_code'  => $data['municipality_code'],
                    'municipality_name'  => $data['municipality_name'] ?? '',
                    'barangay'           => $data['barangay'],
                    'street'             => $data['street'] ?? '',
                    'house_no'           => $data['house_no'] ?? null,
                ]);
            }

            // 3. Role-specific details
            if ($data['role'] === 'seller') {
                $this->supabaseInsert('seller_details', [
                    'profile_id'       => $userId,
                    'business_name'    => $data['business_name'],
                    'line_of_business' => $data['line_of_business'],
                ]);
            } elseif ($data['role'] === 'courier') {
                $this->supabaseInsert('courier_details', [
                    'profile_id'   => $userId,
                    'vehicle'      => $data['vehicle'],
                    'plate_number' => $data['plate_number'],
                ]);
            } elseif ($data['role'] === 'driver') {
                $this->supabaseInsert('driver_details', [
                    'profile_id'            => $userId,
                    'logistics_company_id'  => null,
                    'vehicle'               => $data['driver_vehicle'],
                    'plate_number'          => $data['driver_plate_number'],
                    'license_number'        => $data['driver_license_number'] ?? null,
                ]);
            }

            // 4. Documents
            $fileMap = $this->fileMapForRole($data['role'], $request);
            foreach ($fileMap as $docType => $file) {
                if (!$file) {
                    continue;
                }
                $this->uploadProfileDocument($userId, $docType, $file);
            }

            return response()->json([
                'message' => 'Registration submitted! Please wait for administrator approval.',
                'user_id' => $userId,
            ], 201);

        } catch (\Throwable $e) {
            Log::error('registerUser failed', ['error' => $e->getMessage()]);

            // Roll back the auth user so retries don't hit "already exists"
            if ($userId) {
                $this->deleteSupabaseAuthUser($userId);
            }

            $message = str_contains($e->getMessage(), 'already exists') || str_contains($e->getMessage(), 'already registered')
                ? 'This email is already registered. Please login instead.'
                : 'Registration failed. Please try again.';

            return response()->json(['message' => $message], 422);
        }
    }

    /**
     * Register a logistics company account.
     */
    public function registerLogistics(Request $request)
    {
        $data = $request->validate([
            'company_email'    => 'required|email',
            'password'         => 'required|string|min:8',
            'company_name'     => 'required|string',
            'company_contact_no' => 'required|string',
            'company_tin'      => 'required|string',
            'company_sec_registration' => 'nullable|string',
            'company_region'   => 'nullable|string',

            'owner_first_name'     => 'required|string',
            'owner_last_name'      => 'required|string',
            'owner_middle_initial' => 'nullable|string',
            'owner_sex'            => 'nullable|string',
            'owner_birthday'       => 'nullable|date',

            'company_province_code'     => 'nullable|string',
            'company_province_name'     => 'nullable|string',
            'company_municipality_code' => 'nullable|string',
            'company_municipality_name' => 'nullable|string',
            'company_barangay'          => 'nullable|string',
            'company_street'            => 'nullable|string',
            'company_house_no'          => 'nullable|string',

            'owner_id_file'         => 'nullable|file|max:10240',
            'business_permit_file'  => 'nullable|file|max:10240',
            'mayor_permit_file'     => 'nullable|file|max:10240',
            'dti_reg_file'          => 'nullable|file|max:10240',
        ]);

        $email = strtolower(trim($data['company_email']));
        $userId = null;

        try {
            $authUser = $this->createSupabaseAuthUser($email, $data['password'], [
                'role'           => 'logistics',
                'company_name'   => $data['company_name'],
                'first_name'     => $data['owner_first_name'],
                'last_name'      => $data['owner_last_name'],
                'middle_initial' => $data['owner_middle_initial'] ?? '',
                'sex'            => $data['owner_sex'] ?? null,
                'birthday'       => $data['owner_birthday'] ?? null,
                'status'         => 'pending',
            ]);

            $userId = $authUser['id'];

            $company = $this->supabaseInsert('logistics_companies', [
                'owner_profile_id'   => $userId,
                'company_name'       => $data['company_name'],
                'company_email'      => $email,
                'company_contact_no' => $data['company_contact_no'],
                'tin'                => $data['company_tin'],
                'sec_registration'   => $data['company_sec_registration'] ?? null,
                'region'             => $data['company_region'] ?? null,
                'status'             => 'pending',
            ]);

            $companyId = $company[0]['id'] ?? null;

            if (!$companyId) {
                throw new \RuntimeException('Failed to create logistics company record.');
            }

            if (!empty($data['company_province_code'])) {
                $this->supabaseInsert('addresses', [
                    'owner_kind'            => 'logistics_company',
                    'logistics_company_id'  => $companyId,
                    'province_code'         => $data['company_province_code'],
                    'province_name'         => $data['company_province_name'] ?? '',
                    'municipality_code'     => $data['company_municipality_code'] ?? '',
                    'municipality_name'     => $data['company_municipality_name'] ?? '',
                    'barangay'              => $data['company_barangay'] ?? '',
                    'street'                => $data['company_street'] ?? '',
                    'house_no'              => $data['company_house_no'] ?? null,
                ]);
            }

            $fileMap = [
                'valid_id'                => $request->file('owner_id_file'),
                'business_permit'         => $request->file('business_permit_file'),
                'mayors_permit'           => $request->file('mayor_permit_file'),
                'dti_sec_registration'    => $request->file('dti_reg_file'),
            ];

            foreach ($fileMap as $docType => $file) {
                if (!$file) {
                    continue;
                }
                $this->uploadCompanyDocument($companyId, $docType, $file);
            }

            return response()->json([
                'message'    => 'Logistics company registration submitted! Please wait for administrator approval.',
                'user_id'    => $userId,
                'company_id' => $companyId,
            ], 201);

        } catch (\Throwable $e) {
            Log::error('registerLogistics failed', ['error' => $e->getMessage()]);

            if ($userId) {
                $this->deleteSupabaseAuthUser($userId);
            }

            $message = str_contains($e->getMessage(), 'already exists') || str_contains($e->getMessage(), 'already registered')
                ? 'This email is already registered. Please login instead.'
                : 'Registration failed. Please try again.';

            return response()->json(['message' => $message], 422);
        }
    }

    public function login(Request $request)
    {
        $request->validate([
            'email'    => 'required|email',
            'password' => 'required|string',
        ]);

        $response = Http::withHeaders([
            'apikey'       => config('services.supabase.anon_key'),
            'Content-Type' => 'application/json',
        ])->post(config('services.supabase.url') . '/auth/v1/token?grant_type=password', [
            'email'    => strtolower(trim($request->email)),
            'password' => $request->password,
        ]);

        if (!$response->successful()) {
            $error = $response->json();
            return response()->json([
                'message' => $error['error_description'] ?? $error['message'] ?? 'Invalid credentials.',
            ], 401);
        }

        return response()->json($response->json());
    }

    public function user(Request $request)
    {
        $token = $request->bearerToken();

        $response = Http::withHeaders([
            'apikey'        => config('services.supabase.anon_key'),
            'Authorization' => 'Bearer ' . $token,
        ])->get(config('services.supabase.url') . '/auth/v1/user');

        if (!$response->successful()) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        return response()->json($response->json());
    }

    public function logout(Request $request)
    {
        $token = $request->bearerToken();

        Http::withHeaders([
            'apikey'        => config('services.supabase.anon_key'),
            'Authorization' => 'Bearer ' . $token,
            'Content-Type'  => 'application/json',
        ])->post(config('services.supabase.url') . '/auth/v1/logout');

        return response()->json(['message' => 'Logged out successfully.']);
    }

    /**
     * Create a Supabase Auth user via the admin API (service role key,
     * server-side only). Throws on failure.
     */
    private function createSupabaseAuthUser(string $email, string $password, array $metadata): array
    {
        $response = Http::withHeaders([
            'apikey'        => config('services.supabase.service_role_key'),
            'Authorization' => 'Bearer ' . config('services.supabase.service_role_key'),
            'Content-Type'  => 'application/json',
        ])->post(config('services.supabase.url') . '/auth/v1/admin/users', [
            'email'         => $email,
            'password'      => $password,
            'email_confirm' => true,
            'user_metadata' => $metadata,
        ]);

        if (!$response->successful()) {
            Log::error('Supabase admin createUser failed', ['body' => $response->body()]);
            $error = $response->json();
            throw new \RuntimeException($error['msg'] ?? $error['message'] ?? 'Failed to create user.');
        }

        $user = $response->json();

        if (!isset($user['id'])) {
            throw new \RuntimeException('Supabase did not return a user id.');
        }

        return $user;
    }

    /**
     * Delete a Supabase Auth user (used to roll back a partially-failed
     * registration so the email isn't stuck as "already exists").
     */
    private function deleteSupabaseAuthUser(string $userId): void
    {
        try {
            Http::withHeaders([
                'apikey'        => config('services.supabase.service_role_key'),
                'Authorization' => 'Bearer ' . config('services.supabase.service_role_key'),
            ])->delete(config('services.supabase.url') . '/auth/v1/admin/users/' . $userId);
        } catch (\Throwable $e) {
            Log::error('Failed to roll back Supabase auth user', [
                'user_id' => $userId,
                'error'   => $e->getMessage(),
            ]);
        }
    }

    /**
     * Insert a row into a Supabase/Postgres table via PostgREST, using the
     * service role key to bypass RLS (equivalent to the old supabaseAdmin
     * `.from(table).insert(...)` calls). Returns the inserted row(s).
     */
    private function supabaseInsert(string $table, array $payload): array
    {
        $response = Http::withHeaders([
            'apikey'        => config('services.supabase.service_role_key'),
            'Authorization' => 'Bearer ' . config('services.supabase.service_role_key'),
            'Content-Type'  => 'application/json',
            'Prefer'        => 'return=representation',
        ])->post(config('services.supabase.url') . '/rest/v1/' . $table, $payload);

        if (!$response->successful()) {
            Log::error("Supabase insert into {$table} failed", ['body' => $response->body()]);
            throw new \RuntimeException("Failed to save {$table} record.");
        }

        return $response->json();
    }

    /**
     * Upload a file to the Supabase "documents" storage bucket and record it
     * in the documents table, scoped to a profile.
     */
    private function uploadProfileDocument(string $profileId, string $docType, $file): void
    {
        $path = "profile/{$profileId}/{$docType}_" . now()->timestamp . '.' . $file->getClientOriginalExtension();

        $this->uploadToSupabaseStorage('documents', $path, $file);

        $this->supabaseInsert('documents', [
            'owner_kind'    => 'profile',
            'profile_id'    => $profileId,
            'doc_type'      => $docType,
            'storage_path'  => $path,
            'mime_type'     => $file->getClientMimeType(),
            'status'        => 'pending',
        ]);
    }

    /**
     * Same as uploadProfileDocument but scoped to a logistics company.
     */
    private function uploadCompanyDocument(string $companyId, string $docType, $file): void
    {
        $path = "logistics_company/{$companyId}/{$docType}_" . now()->timestamp . '.' . $file->getClientOriginalExtension();

        $this->uploadToSupabaseStorage('documents', $path, $file);

        $this->supabaseInsert('documents', [
            'owner_kind'            => 'logistics_company',
            'logistics_company_id'  => $companyId,
            'doc_type'              => $docType,
            'storage_path'          => $path,
            'mime_type'             => $file->getClientMimeType(),
            'status'                => 'pending',
        ]);
    }

    /**
     * Raw upload to Supabase Storage via the service role key.
     */
    private function uploadToSupabaseStorage(string $bucket, string $path, $file): void
    {
        $response = Http::withHeaders([
            'apikey'        => config('services.supabase.service_role_key'),
            'Authorization' => 'Bearer ' . config('services.supabase.service_role_key'),
            'Content-Type'  => $file->getClientMimeType(),
        ])->withBody(
            file_get_contents($file->getRealPath()),
            $file->getClientMimeType()
        )->post(config('services.supabase.url') . "/storage/v1/object/{$bucket}/{$path}");

        if (!$response->successful()) {
            Log::error('Supabase storage upload failed', ['path' => $path, 'body' => $response->body()]);
            throw new \RuntimeException('Failed to upload document.');
        }
    }

    /**
     * Map role -> [doc_type => UploadedFile|null] from the request, mirroring
     * the old frontend's uploadDocuments() switch.
     */
    private function fileMapForRole(string $role, Request $request): array
    {
        return match ($role) {
            'buyer' => [
                'valid_id' => $request->file('id_file'),
            ],
            'seller' => [
                'valid_id'         => $request->file('id_file'),
                'business_permit'  => $request->file('business_permit'),
            ],
            'courier' => [
                'orcr'             => $request->file('orcr_file'),
                'drivers_license'  => $request->file('license_file'),
            ],
            'driver' => [
                'valid_id'         => $request->file('id_file'),
                'drivers_license'  => $request->file('license_file'),
                'orcr'             => $request->file('orcr_file'),
            ],
            default => [],
        };
    }
}