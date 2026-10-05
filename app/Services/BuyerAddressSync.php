<?php

namespace App\Services;

use App\Models\Address;
use App\Models\BuyerAddress;
use App\Models\Profile;
use App\Support\StreetCleaner;

/**
 * Keeps the buyer's DEFAULT saved address (buyer_addresses) and their
 * account address (public.addresses, owner_kind='profile' — the one the
 * Account page edits) as the same address:
 *
 *  - seed():         first visit with an empty book → the account address
 *                    becomes the first (default) saved address.
 *  - pushDefault():  default changed / edited in the book → account updated.
 *  - pullAccount():  account address edited → default saved address updated.
 */
class BuyerAddressSync
{
    public function seed(Profile $buyer): void
    {
        if (BuyerAddress::where('buyer_profile_id', $buyer->id)->exists()) {
            return;
        }

        $account = $this->accountAddress($buyer);

        if (! $account || ! $account->province_code || ! $account->municipality_code || ! $account->barangay) {
            return;
        }

        BuyerAddress::create([
            'buyer_profile_id' => $buyer->id,
            'recipient_name' => $buyer->full_name,
            'contact_no' => (string) $buyer->contact_no,
            ...$this->fromAccount($account),
            ...$this->seedPin($account),
            'label' => 'Home',
            'is_default' => true,
        ]);
    }

    public function pushDefault(Profile $buyer): void
    {
        $default = BuyerAddress::where('buyer_profile_id', $buyer->id)->where('is_default', true)->first();

        if (! $default || ! $default->isRoutable()) {
            return;
        }

        Address::updateOrCreate(
            ['owner_kind' => 'profile', 'profile_id' => $buyer->id],
            [
                'region_code' => $default->region_name,
                'region_name' => $default->region_name,
                'province_code' => $default->province_code,
                'province_name' => $default->province,
                'municipality_code' => $default->municipality_code,
                'municipality_name' => $default->city,
                'barangay' => $default->barangay,
                'street' => $default->line1,
                'house_no' => $default->house_no,
                'latitude' => $default->latitude,
                'longitude' => $default->longitude,
            ],
        );
    }

    public function pullAccount(Profile $buyer, Address $account): void
    {
        $default = BuyerAddress::where('buyer_profile_id', $buyer->id)->where('is_default', true)->first();

        if (! $default) {
            $this->seed($buyer);

            return;
        }

        $default->update($this->fromAccount($account));
    }

    private function accountAddress(Profile $buyer): ?Address
    {
        return Address::where('owner_kind', 'profile')->where('profile_id', $buyer->id)->first();
    }

    /** @return array<string, mixed> */
    private function fromAccount(Address $account): array
    {
        return [
            'house_no' => $account->house_no,
            'line1' => StreetCleaner::clean($account->street, [
                $account->barangay, $account->municipality_name, $account->province_name, $account->region_name,
            ]) ?: (string) $account->barangay,
            'region_name' => $account->region_name,
            'province_code' => $account->province_code,
            'province' => (string) $account->province_name,
            'municipality_code' => $account->municipality_code,
            'city' => (string) $account->municipality_name,
            'barangay' => $account->barangay,
        ];
    }

    /**
     * The account form has no pin picker, so a pin is only carried over
     * when seeding — an account edit otherwise leaves the saved address's
     * pin alone (HasMapPin clears it if the area changed).
     *
     * @return array<string, mixed>
     */
    private function seedPin(Address $account): array
    {
        return ['latitude' => $account->latitude, 'longitude' => $account->longitude];
    }
}
