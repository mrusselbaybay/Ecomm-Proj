<?php

namespace App\Http\Controllers\Api\Courier;

use App\Http\Controllers\Controller;
use App\Http\Resources\Courier\LogisticsCompanyResource;
use App\Models\LogisticsCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class LogisticsCompanyController extends Controller
{
    /**
     * Return the logistics companies available to couriers.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'region' => ['nullable', 'string', 'max:100'],
            'search' => ['nullable', 'string', 'max:100'],
        ]);

        $companies = LogisticsCompany::query()
            // Only companies that have switched hiring on in their portal
            // Account Settings are offered to couriers looking for work.
            ->where('is_hiring', true)
            ->when($filters['region'] ?? null, function (Builder $query, string $region): void {
                $query->where('region', $region);
            })
            ->when($filters['search'] ?? null, function (Builder $query, string $search): void {
                $query->where(function (Builder $query) use ($search): void {
                    $query->whereLike('company_name', "%{$search}%")
                        ->orWhereLike('company_email', "%{$search}%");
                });
            })
            ->orderBy('company_name')
            ->get();

        $regions = LogisticsCompany::query()
            ->where('is_hiring', true)
            ->whereNotNull('region')
            ->where('region', '!=', '')
            ->distinct()
            ->orderBy('region')
            ->pluck('region')
            ->values();

        return LogisticsCompanyResource::collection($companies)->additional([
            'meta' => [
                'regions' => $regions,
            ],
        ]);
    }
}
