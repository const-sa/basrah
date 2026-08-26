<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The purchases table carries a payment_method_id from the day it was created,
 * but payment_methods itself only becomes a table later in the order. The
 * column therefore waited here for the constraint that gives it meaning.
 */
return new class extends Migration
{
    public function up(): void
    {
        // القيد يُنشأ مرةً واحدة. إعادة التشغيل بعد توقّفٍ في منتصف الترحيل
        // كانت تصطدم به موجودًا وتسقط الأمر كله.
        if ($this->hasForeignKey()) {
            return;
        }

        // صفوفٌ تشير إلى طريقة دفعٍ لا وجود لها — بيانات سبقت جدول الطرق،
        // أو طريقةٌ حُذفت يدويًا. MySQL يرفض القيد بسببها (errno 1452).
        // تُفرَّغ إلى null لا تُحذف: الشراء نفسه سليم، المفقود مرجعه فقط،
        // وهو ما كان القيد نفسه ليفعله عند الحذف (nullOnDelete).
        DB::table('purchases')
            ->whereNotNull('payment_method_id')
            ->whereNotIn('payment_method_id', DB::table('payment_methods')->select('id'))
            ->update(['payment_method_id' => null]);

        Schema::table('purchases', function (Blueprint $table) {
            $table->foreign('payment_method_id')
                ->references('id')->on('payment_methods')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (! $this->hasForeignKey()) {
            return;
        }

        Schema::table('purchases', function (Blueprint $table) {
            $table->dropForeign(['payment_method_id']);
        });
    }

    private function hasForeignKey(): bool
    {
        foreach (Schema::getForeignKeys('purchases') as $foreignKey) {
            if (in_array('payment_method_id', $foreignKey['columns'], true)) {
                return true;
            }
        }

        return false;
    }
};
