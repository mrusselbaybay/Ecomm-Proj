<?php

namespace App\Http\Controllers\Compliance;

use App\Http\Controllers\Controller;
use App\Models\DataSubjectRequest;
use App\Models\Profile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DataSubjectRequestController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $data = $request->validate([
            'request_type' => ['required', Rule::in(['access', 'correction', 'deletion', 'portability', 'objection'])],
            'details' => ['nullable', 'string', 'max:5000'],
        ]);

        $subjectRequest = DataSubjectRequest::query()->create([
            'user_id' => $user->id,
            ...$data,
            'status' => 'submitted',
        ]);

        return response()->json(['data' => $this->payload($subjectRequest)], 201);
    }

    public function show(Request $request, string $requestId): JsonResponse
    {
        $user = $this->user($request);
        $requestId = DataSubjectRequest::query()->findOrFail($requestId);
        abort_unless($requestId->user_id === $user->id, 404);

        return response()->json(['data' => $this->payload($requestId)]);
    }

    /** @return array<string, mixed> */
    private function payload(DataSubjectRequest $request): array
    {
        return [
            'id' => $request->id,
            'requestType' => $request->request_type,
            'status' => $request->status,
            'details' => $request->details,
            'createdAt' => $request->created_at?->toIso8601String(),
            'resolvedAt' => $request->resolved_at?->toIso8601String(),
        ];
    }

    private function user(Request $request): Profile
    {
        $resolver = $request->getUserResolver();
        $user = $resolver();
        abort_unless($user instanceof Profile, 401);

        return $user;
    }
}
