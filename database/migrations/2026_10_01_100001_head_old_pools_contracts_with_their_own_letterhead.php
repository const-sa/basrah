<?php

use App\Support\PoolInstallationContractTemplate;
use App\Support\PoolMaintenanceContractTemplate;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Contracts drawn before the pools were given their own letterhead froze
     * the business's name; the activity signed none of them under it.
     */
    public function up(): void
    {
        $settings = DB::table('settings')->find(1);

        if (! $settings) {
            return;
        }

        $business = $settings->business_name ?: null;
        $pools = $settings->pools_name ?: null;

        // Nothing to repair while the activity trades under the business's name.
        if (! $pools || ! $business || $pools === $business) {
            return;
        }

        // What such a sheet may hold: the business's name, or — before the
        // earlier repair — the deployment's.
        $stale = array_unique(array_filter([$business, 'Laravel', (string) config('app.name')]));

        $poolsQuotations = DB::table('quotations')
            ->join('departments', 'departments.id', '=', 'quotations.department_id')
            ->whereRaw('UPPER(departments.code) = ?', ['POOLS'])
            ->pluck('quotations.id')->all();

        foreach (DB::table('contracts')->select('id', 'quotation_id', 'data', 'body', 'terms')->get() as $row) {
            $data = json_decode((string) $row->data, true) ?: [];
            $frozen = $data['org_name'] ?? null;

            // A name typed onto the paper by hand is left as it was written.
            if (! in_array($frozen, $stale, true)) {
                continue;
            }

            $theirs = in_array($data['form'] ?? null, [
                PoolInstallationContractTemplate::FORM,
                PoolMaintenanceContractTemplate::FORM,
            ], true) || in_array($row->quotation_id, $poolsQuotations, true);

            if (! $theirs) {
                continue;
            }

            $data['org_name'] = $pools;

            DB::table('contracts')->where('id', $row->id)->update([
                'data' => json_encode($data),
                'body' => str_replace($frozen, $pools, (string) $row->body),
                'terms' => $row->terms === null ? null : str_replace($frozen, $pools, (string) $row->terms),
            ]);
        }
    }

    public function down(): void
    {
        // A repaired letterhead has no old value worth putting back.
    }
};
