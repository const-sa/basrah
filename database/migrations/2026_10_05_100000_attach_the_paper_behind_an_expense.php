<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// One paper per expense — the supplier invoice or the meter reading.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->string('attachment_path')->nullable()->after('reference')
                ->comment('فاتورة المورّد أو صورة العدّاد');
        });
    }

    public function down(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->dropColumn('attachment_path');
        });
    }
};
