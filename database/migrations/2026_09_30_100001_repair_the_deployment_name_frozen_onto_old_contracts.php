<?php

use App\Support\PoolInstallationContractTemplate;
use App\Support\PoolMaintenanceContractTemplate;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Contracts drawn before the letterhead was read from the settings froze
     * the deployment's name — «Laravel», or whatever APP_NAME happened to be —
     * as the first party. No client ever contracted with that name, so it is
     * repaired here rather than kept for fidelity's sake.
     */
    public function up(): void
    {
        $settings = DB::table('settings')->find(1);

        if (! $settings) {
            return;
        }

        // The two ways the bug wrote a name: the framework's default, and this
        // install's own APP_NAME. Neither is a business anyone traded under.
        $placeholders = array_unique(array_filter(['Laravel', (string) config('app.name')]));

        $business = $settings->business_name ?: null;
        $pools = $settings->pools_name ?: $business;

        if (! $business) {
            return;
        }

        foreach (DB::table('contracts')->select('id', 'data', 'body', 'terms')->get() as $row) {
            $data = json_decode((string) $row->data, true) ?: [];
            $frozen = $data['org_name'] ?? null;

            if (! in_array($frozen, $placeholders, true)) {
                continue;
            }

            $name = in_array($data['form'] ?? null, [
                PoolInstallationContractTemplate::FORM,
                PoolMaintenanceContractTemplate::FORM,
            ], true) ? $pools : $business;

            $data['org_name'] = $name;

            DB::table('contracts')->where('id', $row->id)->update([
                'data' => json_encode($data),
                'body' => str_replace($frozen, $name, (string) $row->body),
                'terms' => $row->terms === null ? null : str_replace($frozen, $name, (string) $row->terms),
            ]);
        }
    }

    public function down(): void
    {
        // A repaired name has no old value worth putting back.
    }
};
