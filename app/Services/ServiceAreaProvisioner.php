<?php

namespace App\Services;

use App\Models\LogisticsCompany;
use App\Models\LogisticsProvincialAssignment;
use App\Models\LogisticsRegionalAssignment;

/**
 * Auto-provisions a company's (singular) provincial and regional fallback
 * pools off its own registered address — staff never create these by hand,
 * see the migration docblock and App\Services\ParcelAutoAssignService.
 *
 * Idempotent and cheap (two firstOrCreate calls keyed on the company's own
 * province/region), so it's safe to call on every barangay-assignment save
 * and whenever the Provincial/Regional tab is opened.
 */
class ServiceAreaProvisioner
{
    /**
     * @return array{provincial: ?LogisticsProvincialAssignment, regional: ?LogisticsRegionalAssignment}
     */
    public function ensureForCompany(LogisticsCompany $company): array
    {
        $address = $company->address;
        // The company's island-group region lives on logistics_companies
        // (the Luzon/Visayas/Mindanao picker in Account Settings) — the
        // address row's own region_name is a free-text PSGC field nobody
        // actually fills in for a company's own address, so it can't be
        // relied on here.
        $region = $company->region;

        if (! $address || ! filled($address->province_name) || ! filled($region)) {
            return ['provincial' => null, 'regional' => null];
        }

        $provincial = LogisticsProvincialAssignment::query()->firstOrCreate(
            [
                'logistics_company_id' => $company->id,
                'province_name' => $address->province_name,
            ],
            ['region_name' => $region],
        );

        $regional = LogisticsRegionalAssignment::query()->firstOrCreate(
            [
                'logistics_company_id' => $company->id,
                'region_name' => $region,
            ],
        );

        return ['provincial' => $provincial, 'regional' => $regional];
    }
}
