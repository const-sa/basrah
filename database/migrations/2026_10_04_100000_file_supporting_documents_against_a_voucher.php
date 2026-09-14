<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A voucher is backed by more than one paper — transfer slip, supplier
 * invoice, approval. So a table, not a column.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('voucher_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('voucher_id')->constrained()->cascadeOnDelete();
            $table->string('path');
            $table->string('original_name');
            $table->string('mime_type', 100);
            $table->unsignedInteger('size')->comment('حجم الملف بالبايت');
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('voucher_attachments');
    }
};
