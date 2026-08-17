<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * الخدمات الإضافية المضافة على حجز معيّن، بسعرها وكميتها وقت الحجز.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('booking_addon', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained('bookings')->cascadeOnDelete();
            $table->foreignId('addon_id')->constrained('addons')->cascadeOnDelete();
            $table->unsignedInteger('quantity')->default(1);
            $table->decimal('unit_price', 12, 2)->default(0);
            $table->decimal('total', 12, 2)->default(0);
            $table->timestamps();

            $table->unique(['booking_id', 'addon_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_addon');
    }
};
