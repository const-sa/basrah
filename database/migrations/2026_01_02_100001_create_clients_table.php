<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clients', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('mobile')->nullable();
            $table->string('email')->nullable();
            $table->string('city')->nullable();
            // عميل ضريبي
            $table->boolean('is_taxable')->default(false);
            $table->string('tax_number')->nullable();
            $table->string('tax_address')->nullable();
            $table->boolean('is_active')->default(true);
            // العميل النقدي الافتراضي: تُنسب إليه كل فاتورة بلا عميل محدد،
            // فلا تبقى فاتورة يتيمة ولا يُفقد أثر البيع النقدي العابر.
            $table->boolean('is_walk_in')->default(false)->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clients');
    }
};
