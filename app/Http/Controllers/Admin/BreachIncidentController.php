<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\BreachIncident;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class BreachIncidentController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'detected_at' => ['required', 'date', 'before_or_equal:now'],
            'reported_to_npc_at' => ['nullable', 'date', 'after_or_equal:detected_at'],
            'affected_count' => ['required', 'integer', 'min:0'],
            'description' => ['required', 'string', 'max:20000'],
            'status' => ['required', Rule::in(['investigating', 'contained', 'reported', 'resolved'])],
        ]);

        $incident = BreachIncident::query()->create([
            ...$data,
            'reported_by' => $request->user()->id,
        ]);

        return response()->json(['data' => [
            'id' => $incident->id,
            'status' => $incident->status,
            'detectedAt' => $incident->detected_at->toIso8601String(),
            'npcReportDueAt' => $incident->detected_at->copy()->addHours(72)->toIso8601String(),
            'reportedToNpcAt' => $incident->reported_to_npc_at?->toIso8601String(),
        ]], 201);
    }
}
