<?php

namespace App\Http\Controllers;

use App\Models\Profile;
use App\Services\ProfileProvisioner;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Laravel\Socialite\Facades\Socialite;
use App\Services\AuthSession;
use Illuminate\Support\Facades\Hash;
use App\Services\AccountRegistrar;
use App\Services\FileStorage;

class AuthController extends Controller
{
    /**
     * Accepted values for the "ID Type" dropdown next to every valid-ID
     * upload in the signup wizard. Keep in sync with resources/js/app.js's
     * ID_TYPES and the `documents.id_type` check constraint in Supabase.
     */
    private const ID_TYPES = [
        'Passport',
        "Driver's License",
        'PRC ID',
        'UMID',
        'SSS ID',
        'National ID',
        'Student ID',
        'Philippine Postal ID',
    ];

    /**
     * Which uploaded doc_type (see fileMapForRole()) the "ID Type" dropdown
     * describes, per role — the one document that's actually a piece of
     * government/personal ID rather than a permit or vehicle document.
     */
    private const PRIMARY_ID_DOC_TYPE = [
        'buyer' => 'valid_id',
        'seller' => 'valid_id',
        'courier' => 'drivers_license',
        'driver' => 'valid_id',
    ];

    public function index()
    {
        return view('auth.app', [
            'config' => [
                'google_oauth_base' => config('services.google.oauth_base'),
            ],
        ]);
    }

    /**
     * The dedicated logistics sign in/up page — same Supabase-backed auth,
     * just a separate SPA (resources/js/app-logistics.js) that only offers
     * the logistics role and rejects buyer/seller/courier logins.
     */
    public function logisticsIndex()
    {
        return view('auth.logistics', [
            'config' => [
                'google_oauth_base' => config('services.google.oauth_base'),
            ],
        ]);
    }

    /**
     * Send the browser to Google. Socialite's job stops at getting a
     * verified Google identity back in handleGoogleCallback() below — it
     * never logs anyone into a Laravel session. This app's real auth stays
     * Supabase-only (see routes/web.php), the same as every other login
     * path.
     */
    public function redirectToGoogle()
    {
        return Socialite::driver('google')
            ->scopes(['openid', 'email', 'profile'])
            ->redirect();
    }

    /**
     * Exchange the Google identity Socialite just verified for a real
     * Supabase session, via Supabase's own `grant_type=id_token` endpoint.
     * Supabase validates the id_token's audience against the Google Client
     * ID(s) configured under Authentication > Providers > Google in the
     * Supabase dashboard, then creates the auth.users row on a first
     * sign-in (same as any OAuth signup) or matches the existing one — the
     * downstream Postgres trigger that populates `profiles` fires exactly
     * as it does for email/password registration.
     *
     * The resulting tokens are handed to the browser as a URL fragment on
     * the login page, in the same shape supabase-js's own OAuth redirect
     * produces. That means the existing client-side detectSessionInUrl +
     * onAuthStateChange('SIGNED_IN') listener in app.js picks it up with
     * no changes on that side.
     */
    public function handleGoogleCallback(Request $request)
    {
        try {
            $googleUser = Socialite::driver('google')->user();
        } catch (\Throwable $e) {
            Log::error('Google Socialite callback failed', ['error' => $e->getMessage()]);

            return $this->redirectToLogin(['google_error' => 1]);
        }

        $profile = $this->profileForGoogleIdentity($googleUser->getId(), $googleUser->getEmail());

        if (! $profile) {
            return $this->redirectToLogin(['google_error' => 1]);
        }

        // Same fragment shape an OAuth redirect produces; the login page's
        // auth client reads it and signs the user in.
        $session = app(AuthSession::class)->issue($profile);

        return $this->redirectToLogin([], http_build_query([
            'access_token' => $session['access_token'],
            'refresh_token' => $session['refresh_token'],
            'expires_in' => $session['expires_in'],
            'expires_at' => $session['expires_at'],
            'token_type' => 'bearer',
        ]));
    }

    /**
     * Find the profile for a verified Google identity (by Google id, then by
     * email — linking it), or create a stub one for a first-time sign-in.
     * A stub has no names yet, which the login page treats as "needs to
     * finish onboarding" (completeGoogleSignup / completeGoogleLogistics).
     */
    private function profileForGoogleIdentity(?string $googleId, ?string $email): ?Profile
    {
        $email = strtolower(trim((string) $email));

        if (! $googleId || $email === '') {
            return null;
        }

        $profile = Profile::where('google_id', $googleId)->first()
            ?? Profile::where('email', $email)->first();

        if ($profile) {
            if (! $profile->google_id) {
                DB::table('profiles')->where('id', $profile->id)->update(['google_id' => $googleId]);
            }

            return $profile;
        }

        $id = (string) Str::uuid();
        app(ProfileProvisioner::class)->provision($id, $email, [], [
            'auth_provider' => 'google',
            'google_id' => $googleId,
            'email_verified_at' => now(),
        ]);

        return Profile::find($id);
    }

    /**
     * Builds the final redirect back to the login page off `app.url`
     * rather than route()/redirect()->route(), which resolve against the
     * *current* request's host. That distinction matters because of a
     * workaround for Google's OAuth policy rejecting Herd's local `.test`
     * domain: the Google handshake (redirectToGoogle + this callback) runs
     * against `http://localhost:8000` (`php artisan serve`, with
     * GOOGLE_REDIRECT_URI and the Google Console authorized redirect URI
     * both pointed at it), while `app.url` stays the Herd domain — so the
     * browser always lands back on Herd once Google's part is done,
     * regardless of which host handled the callback.
     */
    private function redirectToLogin(array $query = [], ?string $fragment = null)
    {
        $url = rtrim(config('app.url'), '/').'/login';

        if ($query) {
            $url .= '?'.http_build_query($query);
        }

        if ($fragment) {
            $url .= '#'.$fragment;
        }

        return redirect()->to($url);
    }

    /**
     * Finishes onboarding a Google sign-in that had no `profiles` row yet.
     *
     * The Supabase auth account already exists — created during the
     * id_token exchange above — with no password and its email already
     * verified by Google, so unlike registerUser() this never creates an
     * auth user or touches email/password. It just attaches the role and
     * profile details the wizard collected to the account the caller is
     * already signed into as (verified via their Supabase bearer token),
     * then lets the same admin approval queue as any other signup pick it
     * up from there.
     *
     * SECURITY: only usable by a session Supabase itself reports as
     * Google-authenticated — anyone else must use registerUser()/
     * registerLogistics() instead. `role` is restricted to the roles this
     * wizard actually collects fields for (no 'admin', no 'driver', no
     * 'logistics' — the latter two are self-registration surfaces this
     * endpoint intentionally doesn't mirror).
     */
    public function completeGoogleSignup(Request $request)
    {
        $authProfile = app(AuthSession::class)->resolve($request->bearerToken());
        $userId = $authProfile?->id;
        $email = $authProfile?->email;
        $provider = $authProfile?->auth_provider;

        if (! $userId || ! $email) {
            return response()->json(['message' => 'Not authenticated.'], 401);
        }

        if ($provider !== 'google') {
            return response()->json(['message' => 'Invalid signup method.'], 403);
        }

        $data = $request->validate([
            'role' => 'required|string|in:buyer,seller,courier',

            'first_name' => 'required|string',
            'last_name' => 'required|string',
            'middle_initial' => 'nullable|string',
            'sex' => 'nullable|string',
            'contact_no' => 'nullable|string',
            'birthday' => 'nullable|date',

            'region' => 'nullable|string|in:Luzon,Visayas,Mindanao',
            'province_code' => 'nullable|string',
            'province_name' => 'nullable|string',
            'municipality_code' => 'nullable|string',
            'municipality_name' => 'nullable|string',
            'barangay' => 'nullable|string',
            'street' => 'nullable|string',
            'house_no' => 'nullable|string',

            // seller
            'business_name' => 'required_if:role,seller|string',
            'line_of_business' => 'required_if:role,seller|string',

            // courier
            'vehicle' => 'required_if:role,courier|string',
            'plate_number' => 'required_if:role,courier|string',

            // files
            'id_file' => 'nullable|file|max:10240',
            'business_permit' => 'nullable|file|max:10240',
            'orcr_file' => 'nullable|file|max:10240',
            'license_file' => 'nullable|file|max:10240',
            'id_type' => ['nullable', 'string', Rule::in(self::ID_TYPES)],
        ]);

        try {
            // Create (or update, if the auth.users trigger already stubbed
            // one out from Google's own metadata) the profiles row
            // directly — safer than assuming that trigger re-fires on an
            // UPDATE the way it does on the original INSERT.
            $this->supabaseUpsertProfile([
                'id' => $userId,
                'email' => $email,
                'role' => $data['role'],
                'first_name' => $data['first_name'],
                'last_name' => $data['last_name'],
                'middle_initial' => $data['middle_initial'] ?? '',
                'sex' => $data['sex'] ?? null,
                'contact_no' => $data['contact_no'] ?? null,
                'birthday' => $data['birthday'] ?? null,
                'status' => 'pending',
            ]);

            if (! empty($data['province_code']) && ! empty($data['municipality_code']) && ! empty($data['barangay'])) {
                $this->supabaseInsert('addresses', [
                    'owner_kind' => 'profile',
                    'profile_id' => $userId,
                    'region_name' => $data['region'] ?? null,
                    'province_code' => $data['province_code'],
                    'province_name' => $data['province_name'] ?? '',
                    'municipality_code' => $data['municipality_code'],
                    'municipality_name' => $data['municipality_name'] ?? '',
                    'barangay' => $data['barangay'],
                    'street' => $data['street'] ?? '',
                    'house_no' => $data['house_no'] ?? null,
                ]);
            }

            if ($data['role'] === 'seller') {
                $this->supabaseInsert('seller_details', [
                    'profile_id' => $userId,
                    'business_name' => $data['business_name'],
                    'line_of_business' => $data['line_of_business'],
                ]);
            } elseif ($data['role'] === 'courier') {
                $this->supabaseInsert('courier_details', [
                    'profile_id' => $userId,
                    'vehicle' => $data['vehicle'],
                    'plate_number' => $data['plate_number'],
                ]);
            }

            $fileMap = $this->fileMapForRole($data['role'], $request);
            $primaryIdDocType = self::PRIMARY_ID_DOC_TYPE[$data['role']] ?? null;
            foreach ($fileMap as $docType => $file) {
                if (! $file) {
                    continue;
                }
                $idType = $docType === $primaryIdDocType ? ($data['id_type'] ?? null) : null;
                $this->uploadProfileDocument($userId, $docType, $file, $idType);
            }

            return response()->json([
                'message' => 'Registration submitted! Please wait for administrator approval.',
                'user_id' => $userId,
            ], 201);

        } catch (\Throwable $e) {
            Log::error('completeGoogleSignup failed', ['user_id' => $userId, 'error' => $e->getMessage()]);

            return response()->json(['message' => 'Registration failed. Please try again.'], 422);
        }
    }

    /**
     * Generic self-registration endpoint (public, unauthenticated).
     *
     * SECURITY: `role` is validated against Profile::REGISTRABLE_ROLES,
     * which never includes 'admin'. Admin accounts are provisioned
     * out-of-band (console/seeder) and must never be creatable through
     * this or any other HTTP-facing endpoint.
     *
     * user_metadata is built from an explicit allow-list rather than
     * `$request->except(['email', 'password'])` — forwarding the raw
     * request body previously let a caller inject arbitrary metadata
     * (e.g. role=admin), which a downstream Postgres trigger reads to
     * populate profiles.role, effectively self-granting admin access.
     */
    public function register(Request $request)
    {
        $data = $request->validate([
            'email' => 'required|email',
            'password' => 'required|string|min:8',
            'role' => 'sometimes|string|in:'.implode(',', Profile::REGISTRABLE_ROLES),

            'first_name' => 'nullable|string',
            'last_name' => 'nullable|string',
            'middle_initial' => 'nullable|string',
            'sex' => 'nullable|string',
            'contact_no' => 'nullable|string',
            'birthday' => 'nullable|date',
        ]);

        $metadata = collect($data)
            ->except(['email', 'password', 'role'])
            ->merge([
                'role' => $data['role'] ?? 'buyer',
                'status' => 'pending',
            ])
            ->all();

        try {
            $user = $this->createAccount(strtolower(trim($data['email'])), $data['password'], $metadata);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $profile = Profile::find($user['id']);

        return response()->json([
            'message' => 'Registration successful.',
            'user' => app(AuthSession::class)->user($profile),
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
            'email' => 'required|email',
            'password' => 'required|string|min:8',
            'role' => 'required|string|in:buyer,seller,courier,driver',

            'first_name' => 'required|string',
            'last_name' => 'required|string',
            'middle_initial' => 'nullable|string',
            'sex' => 'nullable|string',
            'contact_no' => 'nullable|string',
            'birthday' => 'nullable|date',

            'region' => 'nullable|string|in:Luzon,Visayas,Mindanao',
            'province_code' => 'nullable|string',
            'province_name' => 'nullable|string',
            'municipality_code' => 'nullable|string',
            'municipality_name' => 'nullable|string',
            'barangay' => 'nullable|string',
            'street' => 'nullable|string',
            'house_no' => 'nullable|string',

            // seller
            'business_name' => 'required_if:role,seller|string',
            'line_of_business' => 'required_if:role,seller|string',

            // courier
            'vehicle' => 'required_if:role,courier|string',
            'plate_number' => 'required_if:role,courier|string',

            // driver
            'driver_vehicle' => 'required_if:role,driver|string',
            'driver_plate_number' => 'required_if:role,driver|string',
            'driver_license_number' => 'nullable|string',

            // files
            'id_file' => 'nullable|file|max:10240',
            'business_permit' => 'nullable|file|max:10240',
            'orcr_file' => 'nullable|file|max:10240',
            'license_file' => 'nullable|file|max:10240',
            'id_type' => ['nullable', 'string', Rule::in(self::ID_TYPES)],
        ]);

        $email = strtolower(trim($data['email']));
        $userId = null;

        try {
            // 1. Create the Supabase Auth user (service-role only, server-side)
            $authUser = $this->createAccount($email, $data['password'], [
                'role' => $data['role'],
                'first_name' => $data['first_name'],
                'last_name' => $data['last_name'],
                'middle_initial' => $data['middle_initial'] ?? '',
                'sex' => $data['sex'] ?? null,
                'contact_no' => $data['contact_no'] ?? null,
                'birthday' => $data['birthday'] ?? null,
                'status' => 'pending',
            ]);

            $userId = $authUser['id'];

            // The profiles row is created by createAccount() (it
            // replaces the old Postgres trigger on auth.users).

            // 2. Address (optional)
            if (! empty($data['province_code']) && ! empty($data['municipality_code']) && ! empty($data['barangay'])) {
                $this->supabaseInsert('addresses', [
                    'owner_kind' => 'profile',
                    'profile_id' => $userId,
                    'region_name' => $data['region'] ?? null,
                    'province_code' => $data['province_code'],
                    'province_name' => $data['province_name'] ?? '',
                    'municipality_code' => $data['municipality_code'],
                    'municipality_name' => $data['municipality_name'] ?? '',
                    'barangay' => $data['barangay'],
                    'street' => $data['street'] ?? '',
                    'house_no' => $data['house_no'] ?? null,
                ]);
            }

            // 3. Role-specific details
            if ($data['role'] === 'seller') {
                $this->supabaseInsert('seller_details', [
                    'profile_id' => $userId,
                    'business_name' => $data['business_name'],
                    'line_of_business' => $data['line_of_business'],
                ]);
            } elseif ($data['role'] === 'courier') {
                $this->supabaseInsert('courier_details', [
                    'profile_id' => $userId,
                    'vehicle' => $data['vehicle'],
                    'plate_number' => $data['plate_number'],
                ]);
            } elseif ($data['role'] === 'driver') {
                $this->supabaseInsert('driver_details', [
                    'profile_id' => $userId,
                    'logistics_company_id' => null,
                    'vehicle' => $data['driver_vehicle'],
                    'plate_number' => $data['driver_plate_number'],
                    'license_number' => $data['driver_license_number'] ?? null,
                ]);
            }

            // 4. Documents
            $fileMap = $this->fileMapForRole($data['role'], $request);
            $primaryIdDocType = self::PRIMARY_ID_DOC_TYPE[$data['role']] ?? null;
            foreach ($fileMap as $docType => $file) {
                if (! $file) {
                    continue;
                }
                $idType = $docType === $primaryIdDocType ? ($data['id_type'] ?? null) : null;
                $this->uploadProfileDocument($userId, $docType, $file, $idType);
            }

            return response()->json([
                'message' => 'Registration submitted! Please wait for administrator approval.',
                'user_id' => $userId,
            ], 201);

        } catch (\Throwable $e) {
            Log::error('registerUser failed', ['error' => $e->getMessage()]);

            // Roll back the auth user so retries don't hit "already exists"
            if ($userId) {
                $this->deleteAccount($userId);
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
            'company_email' => 'required|email',
            'password' => 'required|string|min:8',
            'company_name' => 'required|string',
            'company_contact_no' => 'required|string',
            'company_tin' => 'required|string',
            'company_sec_registration' => 'nullable|string',
            'company_region' => 'nullable|string',

            'owner_first_name' => 'required|string',
            'owner_last_name' => 'required|string',
            'owner_middle_initial' => 'nullable|string',
            'owner_sex' => 'nullable|string',
            'owner_birthday' => 'nullable|date',

            'company_province_code' => 'nullable|string',
            'company_province_name' => 'nullable|string',
            'company_municipality_code' => 'nullable|string',
            'company_municipality_name' => 'nullable|string',
            'company_barangay' => 'nullable|string',
            'company_street' => 'nullable|string',
            'company_house_no' => 'nullable|string',

            'owner_id_file' => 'nullable|file|max:10240',
            'business_permit_file' => 'nullable|file|max:10240',
            'mayor_permit_file' => 'nullable|file|max:10240',
            'dti_reg_file' => 'nullable|file|max:10240',
            'owner_id_type' => ['nullable', 'string', Rule::in(self::ID_TYPES)],
        ]);

        $email = strtolower(trim($data['company_email']));
        $userId = null;

        try {
            $authUser = $this->createAccount($email, $data['password'], [
                'role' => 'logistics',
                'company_name' => $data['company_name'],
                'first_name' => $data['owner_first_name'],
                'last_name' => $data['owner_last_name'],
                'middle_initial' => $data['owner_middle_initial'] ?? '',
                'sex' => $data['owner_sex'] ?? null,
                'birthday' => $data['owner_birthday'] ?? null,
                'status' => 'pending',
            ]);

            $userId = $authUser['id'];

            $company = $this->supabaseInsert('logistics_companies', [
                'owner_profile_id' => $userId,
                'company_name' => $data['company_name'],
                'company_email' => $email,
                'company_contact_no' => $data['company_contact_no'],
                'tin' => $data['company_tin'],
                'sec_registration' => $data['company_sec_registration'] ?? null,
                'region' => $data['company_region'] ?? null,
                'status' => 'pending',
            ]);

            $companyId = $company[0]['id'] ?? null;

            if (! $companyId) {
                throw new \RuntimeException('Failed to create logistics company record.');
            }

            if (! empty($data['company_province_code'])) {
                $this->supabaseInsert('addresses', [
                    'owner_kind' => 'logistics_company',
                    'logistics_company_id' => $companyId,
                    'region_name' => $data['company_region'] ?? null,
                    'province_code' => $data['company_province_code'],
                    'province_name' => $data['company_province_name'] ?? '',
                    'municipality_code' => $data['company_municipality_code'] ?? '',
                    'municipality_name' => $data['company_municipality_name'] ?? '',
                    'barangay' => $data['company_barangay'] ?? '',
                    'street' => $data['company_street'] ?? '',
                    'house_no' => $data['company_house_no'] ?? null,
                ]);
            }

            $fileMap = [
                'valid_id' => $request->file('owner_id_file'),
                'business_permit' => $request->file('business_permit_file'),
                'mayors_permit' => $request->file('mayor_permit_file'),
                'dti_sec_registration' => $request->file('dti_reg_file'),
            ];

            foreach ($fileMap as $docType => $file) {
                if (! $file) {
                    continue;
                }
                $idType = $docType === 'valid_id' ? ($data['owner_id_type'] ?? null) : null;
                $this->uploadCompanyDocument($companyId, $docType, $file, $idType);
            }

            return response()->json([
                'message' => 'Logistics company registration submitted! Please wait for administrator approval.',
                'user_id' => $userId,
                'company_id' => $companyId,
            ], 201);

        } catch (\Throwable $e) {
            Log::error('registerLogistics failed', ['error' => $e->getMessage()]);

            if ($userId) {
                $this->deleteAccount($userId);
            }

            $message = str_contains($e->getMessage(), 'already exists') || str_contains($e->getMessage(), 'already registered')
                ? 'This email is already registered. Please login instead.'
                : 'Registration failed. Please try again.';

            return response()->json(['message' => $message], 422);
        }
    }

    /**
     * Finish a logistics-company signup for a user who came in through
     * "Continue with Google". The Supabase auth user already exists (Google
     * created it during the OAuth handshake) and its email is already
     * verified, so unlike registerLogistics() this creates no auth user and
     * takes no password — it just attaches the profile, company, address and
     * documents to the existing account. The company email IS the Google
     * account email; the client never collects a separate one.
     */
    public function completeGoogleLogistics(Request $request)
    {
        $authProfile = app(AuthSession::class)->resolve($request->bearerToken());
        $userId = $authProfile?->id;
        $email = $authProfile?->email;
        $provider = $authProfile?->auth_provider;

        if (! $userId || ! $email) {
            return response()->json(['message' => 'Not authenticated.'], 401);
        }

        if ($provider !== 'google') {
            return response()->json(['message' => 'Invalid signup method.'], 403);
        }

        $data = $request->validate([
            'company_name' => 'required|string',
            'company_contact_no' => 'required|string',
            'company_tin' => 'required|string',
            'company_sec_registration' => 'nullable|string',
            'company_region' => 'nullable|string|in:Luzon,Visayas,Mindanao',

            'owner_first_name' => 'required|string',
            'owner_last_name' => 'required|string',
            'owner_middle_initial' => 'nullable|string',
            'owner_sex' => 'nullable|string',
            'owner_birthday' => 'nullable|date',

            'company_province_code' => 'nullable|string',
            'company_province_name' => 'nullable|string',
            'company_municipality_code' => 'nullable|string',
            'company_municipality_name' => 'nullable|string',
            'company_barangay' => 'nullable|string',
            'company_street' => 'nullable|string',
            'company_house_no' => 'nullable|string',

            'owner_id_file' => 'nullable|file|max:10240',
            'business_permit_file' => 'nullable|file|max:10240',
            'mayor_permit_file' => 'nullable|file|max:10240',
            'dti_reg_file' => 'nullable|file|max:10240',
            'owner_id_type' => ['nullable', 'string', Rule::in(self::ID_TYPES)],
        ]);

        $email = strtolower(trim($email));

        try {
            $this->supabaseUpsertProfile([
                'id' => $userId,
                'email' => $email,
                'role' => 'logistics',
                'first_name' => $data['owner_first_name'],
                'last_name' => $data['owner_last_name'],
                'middle_initial' => $data['owner_middle_initial'] ?? '',
                'sex' => $data['owner_sex'] ?? null,
                'birthday' => $data['owner_birthday'] ?? null,
                'status' => 'pending',
            ]);

            $company = $this->supabaseInsert('logistics_companies', [
                'owner_profile_id' => $userId,
                'company_name' => $data['company_name'],
                'company_email' => $email,
                'company_contact_no' => $data['company_contact_no'],
                'tin' => $data['company_tin'],
                'sec_registration' => $data['company_sec_registration'] ?? null,
                'region' => $data['company_region'] ?? null,
                'status' => 'pending',
            ]);

            $companyId = $company[0]['id'] ?? null;

            if (! $companyId) {
                throw new \RuntimeException('Failed to create logistics company record.');
            }

            if (! empty($data['company_province_code'])) {
                $this->supabaseInsert('addresses', [
                    'owner_kind' => 'logistics_company',
                    'logistics_company_id' => $companyId,
                    'region_name' => $data['company_region'] ?? null,
                    'province_code' => $data['company_province_code'],
                    'province_name' => $data['company_province_name'] ?? '',
                    'municipality_code' => $data['company_municipality_code'] ?? '',
                    'municipality_name' => $data['company_municipality_name'] ?? '',
                    'barangay' => $data['company_barangay'] ?? '',
                    'street' => $data['company_street'] ?? '',
                    'house_no' => $data['company_house_no'] ?? null,
                ]);
            }

            $fileMap = [
                'valid_id' => $request->file('owner_id_file'),
                'business_permit' => $request->file('business_permit_file'),
                'mayors_permit' => $request->file('mayor_permit_file'),
                'dti_sec_registration' => $request->file('dti_reg_file'),
            ];

            foreach ($fileMap as $docType => $file) {
                if (! $file) {
                    continue;
                }
                $idType = $docType === 'valid_id' ? ($data['owner_id_type'] ?? null) : null;
                $this->uploadCompanyDocument($companyId, $docType, $file, $idType);
            }

            return response()->json([
                'message' => 'Logistics company registration submitted! Please wait for administrator approval.',
                'user_id' => $userId,
                'company_id' => $companyId,
            ], 201);

        } catch (\Throwable $e) {
            Log::error('completeGoogleLogistics failed', ['user_id' => $userId, 'error' => $e->getMessage()]);

            return response()->json(['message' => 'Registration failed. Please try again.'], 422);
        }
    }

    public function login(Request $request)
    {
        $data = $request->validate([
            'email' => 'required|email',
            'password' => 'required|string',
        ]);

        $profile = Profile::where('email', strtolower(trim($data['email'])))->first();

        if (! $profile || ! $profile->password || ! Hash::check($data['password'], $profile->password)) {
            return response()->json(['message' => 'Invalid login credentials'], 401);
        }

        // Imported Supabase hashes use bcrypt cost 10; upgrade transparently.
        if (Hash::needsRehash($profile->password)) {
            DB::table('profiles')->where('id', $profile->id)->update(['password' => Hash::make($data['password'])]);
        }

        return response()->json(app(AuthSession::class)->issue($profile, $request->input('device', 'web')));
    }

    /**
     * Native mobile Google Sign-In: verifies the device's Google id_token
     * with Google, then signs into (or creates a stub for) that account.
     */
    public function loginWithGoogleIdToken(Request $request)
    {
        $data = $request->validate([
            'id_token' => 'required|string',
        ]);

        $response = Http::timeout(10)->get('https://oauth2.googleapis.com/tokeninfo', [
            'id_token' => $data['id_token'],
        ]);

        $audiences = array_filter([config('services.google.client_id'), ...(array) config('services.google.mobile_client_ids', [])]);

        if (! $response->successful()
            || ! in_array($response->json('aud'), $audiences, true)
            || $response->json('email_verified') !== 'true') {
            Log::warning('Google id_token rejected', ['status' => $response->status()]);

            return response()->json(['message' => 'Google sign-in failed.'], 401);
        }

        $profile = $this->profileForGoogleIdentity($response->json('sub'), $response->json('email'));

        if (! $profile) {
            return response()->json(['message' => 'Google sign-in failed.'], 401);
        }

        return response()->json(app(AuthSession::class)->issue($profile, 'mobile'));
    }

    public function user(Request $request)
    {
        $profile = app(AuthSession::class)->resolve($request->bearerToken());

        if (! $profile) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        return response()->json(app(AuthSession::class)->user($profile));
    }

    /**
     * Change the signed-in account's password and/or email (the auth
     * client's updateUser()).
     */
    public function updateUser(Request $request)
    {
        $profile = app(AuthSession::class)->resolve($request->bearerToken());

        if (! $profile) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        $data = $request->validate([
            'password' => 'sometimes|string|min:8',
            'email' => ['sometimes', 'email', Rule::unique('profiles', 'email')->ignore($profile->id)],
        ]);

        $changes = [];

        if (isset($data['password'])) {
            $changes['password'] = Hash::make($data['password']);
        }

        if (isset($data['email'])) {
            $changes['email'] = strtolower(trim($data['email']));
        }

        if ($changes) {
            DB::table('profiles')->where('id', $profile->id)->update([...$changes, 'updated_at' => now()]);
        }

        return response()->json(app(AuthSession::class)->user($profile->refresh()));
    }

    public function logout(Request $request)
    {
        app(AuthSession::class)->revoke($request->bearerToken());

        return response()->json(['message' => 'Logged out successfully.']);
    }

    /**
     * Create a login account (profiles row with a bcrypt password) for a
     * new registration. Throws if the email is already registered.
     *
     * @return array{id: string}
     */
    private function createAccount(string $email, string $password, array $metadata): array
    {
        return app(AccountRegistrar::class)->createUser($email, $password, $metadata);
    }

    /**
     * Remove a partially-registered account so a retry isn't blocked by
     * "already registered" (cascades to its addresses/details/documents).
     */
    private function deleteAccount(string $userId): void
    {
        app(AccountRegistrar::class)->deleteUser($userId);
    }

    /** Tables written during signup whose primary key is a uuid `id`. */
    private const UUID_ID_TABLES = ['addresses', 'logistics_companies', 'documents'];

    /**
     * Insert a signup row into the app database. Returns the inserted row
     * wrapped in a list — the shape PostgREST's `return=representation`
     * gave the callers (e.g. `$company[0]['id']`).
     */
    private function supabaseInsert(string $table, array $payload): array
    {
        if (in_array($table, self::UUID_ID_TABLES, true) && empty($payload['id'])) {
            $payload = ['id' => (string) Str::uuid(), ...$payload];
        }

        $columns = Schema::getColumnListing($table);
        foreach (['created_at', 'updated_at'] as $timestamp) {
            if (in_array($timestamp, $columns, true) && ! isset($payload[$timestamp])) {
                $payload[$timestamp] = now();
            }
        }

        try {
            DB::table($table)->insert($payload);
        } catch (\Throwable $e) {
            Log::error("Insert into {$table} failed", ['error' => $e->getMessage()]);
            throw new \RuntimeException("Failed to save {$table} record.");
        }

        return [$payload];
    }

    /**
     * Upsert a `profiles` row keyed on id — used for Google onboarding,
     * where a stub row may already exist from an earlier partial sign-in.
     */
    private function supabaseUpsertProfile(array $payload): void
    {
        try {
            app(ProfileProvisioner::class)->upsert($payload);
        } catch (\Throwable $e) {
            Log::error('Profiles upsert failed', ['error' => $e->getMessage()]);
            throw new \RuntimeException('Failed to save profile.');
        }
    }

    /**
     * Upload a file to the Supabase "documents" storage bucket and record it
     * in the documents table, scoped to a profile.
     */
    private function uploadProfileDocument(string $profileId, string $docType, $file, ?string $idType = null): void
    {
        $path = "profile/{$profileId}/{$docType}_".now()->timestamp.'.'.$file->getClientOriginalExtension();

        $this->storeUpload('documents', $path, $file);

        $this->supabaseInsert('documents', [
            'owner_kind' => 'profile',
            'profile_id' => $profileId,
            'doc_type' => $docType,
            'storage_path' => $path,
            'mime_type' => $file->getClientMimeType(),
            'status' => 'pending',
            'id_type' => $idType,
        ]);
    }

    /**
     * Same as uploadProfileDocument but scoped to a logistics company.
     */
    private function uploadCompanyDocument(string $companyId, string $docType, $file, ?string $idType = null): void
    {
        $path = "logistics_company/{$companyId}/{$docType}_".now()->timestamp.'.'.$file->getClientOriginalExtension();

        $this->storeUpload('documents', $path, $file);

        $this->supabaseInsert('documents', [
            'owner_kind' => 'logistics_company',
            'logistics_company_id' => $companyId,
            'doc_type' => $docType,
            'storage_path' => $path,
            'mime_type' => $file->getClientMimeType(),
            'status' => 'pending',
            'id_type' => $idType,
        ]);
    }

    /**
     * Raw upload to Supabase Storage via the service role key.
     */
    private function storeUpload(string $bucket, string $path, $file): void
    {
        app(FileStorage::class)->upload($bucket, $path, file_get_contents($file->getRealPath()), $file->getClientMimeType());
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
                'valid_id' => $request->file('id_file'),
                'business_permit' => $request->file('business_permit'),
            ],
            'courier' => [
                'orcr' => $request->file('orcr_file'),
                'drivers_license' => $request->file('license_file'),
            ],
            'driver' => [
                'valid_id' => $request->file('id_file'),
                'drivers_license' => $request->file('license_file'),
                'orcr' => $request->file('orcr_file'),
            ],
            default => [],
        };
    }
}
