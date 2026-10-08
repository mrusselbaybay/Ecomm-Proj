<?php

namespace App\Http\Controllers\Compliance;

use App\Http\Controllers\Controller;
use App\Models\ConsentRecord;
use App\Models\Profile;
use App\Services\AuthSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ConsentController extends Controller
{
    public function __construct(private readonly AuthSession $sessions) {}

    public function cookies(Request $request): JsonResponse
    {
        $data = $request->validate([
            'guest_id' => ['nullable', 'uuid', 'required_without:user_id'],
            'categories' => ['required', 'array'],
            'categories.strictly_necessary' => ['required', 'accepted'],
            'categories.functional' => ['required', 'boolean'],
            'categories.analytics' => ['required', 'boolean'],
            'categories.marketing' => ['required', 'boolean'],
            'action' => ['nullable', Rule::in(['granted', 'withdrawn', 'updated'])],
        ]);

        $record = $this->record(
            $request,
            'cookies',
            $data['action'] ?? 'updated',
            $data['categories'],
            $data['guest_id'] ?? null,
        );

        return response()->json(['data' => $this->payload($record)], 201);
    }

    public function terms(Request $request): JsonResponse
    {
        $data = $request->validate([
            'accepted' => ['required', 'accepted'],
            'guest_id' => ['nullable', 'uuid'],
        ]);

        $record = $this->record($request, 'terms', 'granted', null, $data['guest_id'] ?? null);

        return response()->json(['data' => $this->payload($record)], 201);
    }

    public function marketing(Request $request): JsonResponse
    {
        $data = $request->validate([
            'accepted' => ['required', 'boolean'],
            'guest_id' => ['nullable', 'uuid'],
        ]);

        $record = $this->record(
            $request,
            'marketing',
            $data['accepted'] ? 'granted' : 'withdrawn',
            null,
            $data['guest_id'] ?? null,
        );

        return response()->json(['data' => $this->payload($record)], 201);
    }

    public function idScan(Request $request): JsonResponse
    {
        $data = $request->validate([
            'accepted' => ['required', 'boolean'],
            'guest_id' => ['nullable', 'uuid'],
            'image' => ['required_if:accepted,true', 'nullable', 'image', 'max:10240'],
        ]);

        $path = null;

        try {
            if ($request->hasFile('image')) {
                $storedPath = $request->file('image')->store('temporary-id-scans');
                $path = is_string($storedPath) ? $storedPath : null;
            }

            $record = $this->record(
                $request,
                'id_scan',
                $data['accepted'] ? 'granted' : 'withdrawn',
                null,
                $data['guest_id'] ?? null,
            );

            return response()->json([
                'data' => $this->payload($record),
                'extracted' => [],
                'message' => $data['accepted']
                    ? 'Consent recorded. No ID fields were extracted because an OCR provider is not configured.'
                    : 'ID scan consent was declined.',
            ], 201);
        } finally {
            if ($path !== null) {
                app('filesystem')->disk()->delete($path);
            }
        }
    }

    /**
     * @param  array{strictly_necessary: bool, functional: bool, analytics: bool, marketing: bool}|null  $categories
     */
    private function record(
        Request $request,
        string $type,
        string $action,
        ?array $categories,
        ?string $guestId,
    ): ConsentRecord {
        $user = $this->resolveUser($request);

        abort_if($user === null && $guestId === null, 422, 'A signed-in user or guest_id is required.');

        return ConsentRecord::query()->create([
            'user_id' => $user?->id,
            'guest_id' => $guestId,
            'consent_type' => $type,
            'policy_version' => config('legal.policy_version'),
            'categories_json' => $categories,
            'action' => $action,
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'created_at' => now(),
        ]);
    }

    private function resolveUser(Request $request): ?Profile
    {
        return $request->bearerToken()
            ? $this->sessions->resolve($request->bearerToken())
            : null;
    }

    /** @return array<string, mixed> */
    private function payload(ConsentRecord $record): array
    {
        return [
            'id' => $record->id,
            'consentType' => $record->consent_type,
            'policyVersion' => $record->policy_version,
            'categories' => $record->categories_json,
            'action' => $record->action,
            'createdAt' => $record->created_at->toIso8601String(),
        ];
    }
}
