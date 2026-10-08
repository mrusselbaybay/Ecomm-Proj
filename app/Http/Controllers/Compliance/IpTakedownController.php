<?php

namespace App\Http\Controllers\Compliance;

use App\Http\Controllers\Controller;
use App\Models\IpTakedownRequest;
use App\Services\AuthSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class IpTakedownController extends Controller
{
    public function __construct(private readonly AuthSession $sessions) {}

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'claimant_name' => ['required', 'string', 'max:255'],
            'claimant_email' => ['required', 'email', 'max:255'],
            'listing_id' => ['nullable', 'string', 'max:255'],
            'work_description' => ['required', 'string', 'max:10000'],
            'evidence_url' => ['nullable', 'url', 'max:2000'],
            'statement' => ['required', 'string', 'max:5000'],
        ]);

        $claimant = $request->bearerToken()
            ? $this->sessions->resolve($request->bearerToken())
            : null;

        $takedown = IpTakedownRequest::query()->create([
            ...$data,
            'claimant_id' => $claimant?->id,
            'status' => 'submitted',
        ]);

        return response()->json(['data' => [
            'id' => $takedown->id,
            'status' => $takedown->status,
            'createdAt' => $takedown->created_at?->toIso8601String(),
        ]], 201);
    }
}
