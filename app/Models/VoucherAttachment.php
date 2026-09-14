<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A document filed against a receipt or payment voucher. */
class VoucherAttachment extends Model
{
    protected $fillable = [
        'voucher_id', 'path', 'original_name', 'mime_type', 'size', 'uploaded_by',
    ];

    protected function casts(): array
    {
        return ['size' => 'integer'];
    }

    public function voucher(): BelongsTo
    {
        return $this->belongsTo(Voucher::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function url(): string
    {
        // asset() not Storage::url(): the default disk is private, and the
        // latter would also pin the host to APP_URL.
        return asset('storage/'.ltrim($this->path, '/'));
    }

    /** Images preview inline; everything else opens as a file. */
    public function isImage(): bool
    {
        return str_starts_with($this->mime_type, 'image/');
    }
}
