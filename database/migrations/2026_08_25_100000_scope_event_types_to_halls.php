<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * نوع المناسبة يصير خاصًا بقاعة واحدة، ويحمل سعرها لا رسمًا فوقها.
 *
 * لماذا؟ لأن «زواج» ليس تصنيفًا مجرّدًا بل منتَجٌ تبيعه القاعة بسعر: زواج في
 * قاعة المشام 15000 وفي روكانا 12000. النوع العام برسم إضافي كان يفترض أن
 * القاعات تتفق على السعر وتختلف في الرسم، وهو عكس ما يجري فعلًا.
 *
 * price يحل محل تسعيرة القاعة لا يُضاف إليها: اختيار النوع يملأ السعر
 * الأساسي للحجز. وسعر صفر يعني «لا سعر خاص» فيرجع الحجز إلى جدول تسعيرة
 * القاعة (يوم عادي / نهاية أسبوع / موسم) كما كان.
 *
 * extra_amount يسقط: لم يعد للرسم فوق السعر معنى بعد أن صار النوع يحمل السعر.
 * أما event_fee_amount في جدول الحجوزات فيبقى — الحجوزات القديمة سجّلت فيه
 * رسومها فعلًا، ومسحه يغيّر إجماليات محاسبية مُرحَّلة.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('event_types', function (Blueprint $table) {
            $table->foreignId('unit_id')->nullable()->after('id')
                ->constrained('units')->cascadeOnDelete()
                ->comment('القاعة التي يخصّها النوع');

            $table->decimal('price', 12, 2)->default(0)->after('color')
                ->comment('سعر الحجز بهذا النوع — صفر يعني اتّباع تسعيرة القاعة');
        });

        $this->scopeExistingTypesToHalls();

        Schema::table('event_types', function (Blueprint $table) {
            $table->dropColumn('extra_amount');
        });

        // بعد إسناد كل نوع إلى قاعته لم يبقَ للنوع بلا قاعة معنى، فيُمنع.
        Schema::table('event_types', function (Blueprint $table) {
            $table->foreignId('unit_id')->nullable(false)->change();
        });

        // الاسم يتكرر بين القاعات ولا يتكرر داخل القاعة الواحدة: «زواج» في
        // كل قاعة، ولا «زواج» مرتين في قاعة واحدة.
        Schema::table('event_types', function (Blueprint $table) {
            $table->unique(['unit_id', 'name']);
        });
    }

    /**
     * نسخ كل نوع عام إلى كل قاعة، وإعادة توجيه الحجوزات إلى نسخة قاعتها.
     *
     * النسخ لا النقل: النوع العام كان يخدم القاعات جميعًا، وإسناده لقاعة
     * واحدة يحرم الباقي من أنواعها. والحجز يُعاد ربطه بنسخة قاعته حتى يبقى
     * تصنيفه مقروءًا في السجل والتقارير.
     */
    private function scopeExistingTypesToHalls(): void
    {
        $globals = DB::table('event_types')->whereNull('unit_id')->get();

        if ($globals->isEmpty()) {
            return;
        }

        $halls = DB::table('units')->where('type', 'hall')->pluck('id');

        // معرّف النسخة الجديدة لكل (قاعة، نوع أصلي) — يُستعمل في نقل الحجوزات.
        $copies = [];

        foreach ($halls as $hallId) {
            foreach ($globals as $type) {
                $copies[$hallId][$type->id] = DB::table('event_types')->insertGetId([
                    'unit_id' => $hallId,
                    'name' => $type->name,
                    'description' => $type->description,
                    'color' => $type->color,
                    // الرسم الإضافي القديم لا يصلح سعرًا أساسيًا: رسم تجهيز
                    // كوشة ليس سعر زواج. يبدأ بصفر ليتّبع تسعيرة القاعة حتى
                    // يُدخل المشغّل سعره.
                    'price' => 0,
                    'sort_order' => $type->sort_order,
                    'is_active' => $type->is_active,
                    'created_at' => $type->created_at,
                    'updated_at' => now(),
                ]);
            }
        }

        DB::table('bookings')
            ->whereNotNull('event_type_id')
            ->select('id', 'unit_id', 'event_type_id')
            ->orderBy('id')
            ->chunk(500, function ($rows) use ($copies) {
                foreach ($rows as $row) {
                    // حجز على وحدة ليست قاعة (من قبل فصل النظامين) يفقد نوعه:
                    // الشاليه لا مناسبة فيه تُصنَّف.
                    DB::table('bookings')->where('id', $row->id)->update([
                        'event_type_id' => $copies[$row->unit_id][$row->event_type_id] ?? null,
                    ]);
                }
            });

        DB::table('event_types')->whereNull('unit_id')->delete();
    }

    public function down(): void
    {
        Schema::table('event_types', function (Blueprint $table) {
            $table->dropUnique(['unit_id', 'name']);
            $table->decimal('extra_amount', 12, 2)->default(0)->after('color');
        });

        Schema::table('event_types', function (Blueprint $table) {
            $table->dropConstrainedForeignId('unit_id');
            $table->dropColumn('price');
        });
    }
};
