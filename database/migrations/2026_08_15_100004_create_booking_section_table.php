<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * الأقسام المشمولة في حجز نطاقه sections، وسعر كل قسم وقت الحجز.
 * السعر يُجمَّد هنا حتى لا يتغيّر الحجز القديم عند تعديل التسعيرة لاحقًا.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('booking_section', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained('bookings')->cascadeOnDelete();
            $table->foreignId('unit_section_id')->constrained('unit_sections')->cascadeOnDelete();
            $table->decimal('price', 12, 2)->default(0);
            $table->timestamps();

            $table->unique(['booking_id', 'unit_section_id']);
            $table->index('unit_section_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_section');
    }
};
