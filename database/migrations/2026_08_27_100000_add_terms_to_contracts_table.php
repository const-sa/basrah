<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * فصل الشروط عن نص العقد.
 *
 * الشروط كانت تُلحق بالنص في عمود واحد، فيتعذّر عرض العقد مستندًا مرتّبًا:
 * البيانات في صناديقها والشروط في بابها. فصلها يبقي كلًّا منهما مجمَّدًا وقت
 * التوليد، ويحرّر العرض من تفكيك النص بحثًا عن بدايتها.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            $table->text('terms')->nullable()->after('body');
        });
    }

    public function down(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            $table->dropColumn('terms');
        });
    }
};
