<?php

namespace App\Console\Commands;

use App\Models\Address;
use App\Models\BuyerAddress;
use App\Support\StreetCleaner;
use Illuminate\Console\Command;

/**
 * One-off cleanup for street values that hold the whole address (see
 * StreetCleaner). Covers account addresses (public.addresses) and saved
 * buyer addresses (buyer_addresses.line1).
 */
class CleanAddressStreets extends Command
{
    protected $signature = 'addresses:clean-streets {--dry-run : Show the changes without saving them}';

    protected $description = 'Strip duplicated barangay/city/province/region text out of street fields';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $rows = [];

        Address::query()->whereNotNull('street')->chunkById(500, function ($chunk) use ($dry, &$rows) {
            foreach ($chunk as $a) {
                $clean = StreetCleaner::clean($a->street, [$a->barangay, $a->municipality_name, $a->province_name, $a->region_name]);

                if ($clean !== $a->street) {
                    $rows[] = ['account', $a->id, $a->street, $clean];
                    $dry || $a->forceFill(['street' => $clean])->save();
                }
            }
        });

        BuyerAddress::query()->chunkById(500, function ($chunk) use ($dry, &$rows) {
            foreach ($chunk as $a) {
                $clean = StreetCleaner::clean($a->line1, [$a->barangay, $a->city, $a->province, $a->region_name]);

                if ($clean !== $a->line1) {
                    $rows[] = ['saved', $a->id, $a->line1, $clean];
                    $dry || $a->forceFill(['line1' => $clean])->save();
                }
            }
        });

        if ($rows) {
            $this->table(['Type', 'ID', 'Before', 'After'], $rows);
        }

        $this->info(sprintf('%d street(s) %s.', count($rows), $dry ? 'would be cleaned (dry run)' : 'cleaned'));

        return self::SUCCESS;
    }
}
