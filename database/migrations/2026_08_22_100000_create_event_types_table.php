<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * أنواع المناسبات (زواج، ملكة، تخرّج، اجتماع…) وربطها بالحجز مع الباقة.
 *
 * نوع المناسبة جدول يديره المشغّل لا قائمة ثابتة في الكود: كل قاعة تضيف
 * أنواعها وتسمّيها بلغتها، وقد يحمل النوع رسمًا إضافيًا (تجهيز كوشة مثلًا)
 * يدخل تلقائيًا في التسعيرة.
 *
 * package_amount و event_fee_amount عمودان مستقلان لا يُدمجان في
 * addons_amount: كلاهما بند تجاري يظهر في العرض والعقد ويُقرأ في التقارير،
 * فدمجه يضيّع تفصيله عند المراجعة.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_types', function (Blueprint $table) {
            $table->id();
            $table->string('name')->comment('زواج، ملكة، تخرّج، اجتماع…');
            $table->text('description')->nullable();

            $table->string('color', 20)->default('emerald')->comment('لون الشارة في التقويم والجداول');
            $table->decimal('extra_amount', 12, 2)->default(0)->comment('رسم إضافي يُضاف للتسعيرة — صفر يعني بلا رسم');

            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['is_active', 'sort_order']);
        });

        Schema::table('bookings', function (Blueprint $table) {
            $table->foreignId('event_type_id')->nullable()->after('client_id')
                ->constrained('event_types')->nullOnDelete();

            $table->foreignId('package_id')->nullable()->after('event_type_id')
                ->constrained('packages')->nullOnDelete();

            $table->decimal('package_amount', 12, 2)->default(0)->after('base_amount');
            $table->decimal('event_fee_amount', 12, 2)->default(0)->after('package_amount');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('event_type_id');
            $table->dropConstrainedForeignId('package_id');
            $table->dropColumn(['package_amount', 'event_fee_amount']);
        });

        Schema::dropIfExists('event_types');
    }
};
