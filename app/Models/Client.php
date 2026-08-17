<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Client extends Model
{
    /** اسم العميل النقدي الافتراضي عند إنشائه أول مرة. */
    public const WALK_IN_NAME = 'عميل نقدي';

    protected $fillable = [
        'name',
        'mobile',
        'email',
        'city',
        'national_id',
        'is_taxable',
        'tax_number',
        'tax_address',
        'is_active',
        'is_walk_in',
    ];

    protected function casts(): array
    {
        return [
            'is_taxable' => 'boolean',
            'is_active' => 'boolean',
            'is_walk_in' => 'boolean',
        ];
    }

    /**
     * العميل النقدي الافتراضي — واحد لا غير.
     *
     * يُنشأ عند أول طلب إن لم يكن مزروعًا، فلا تتعطّل شاشة البيع على سيدر.
     */
    public static function walkIn(): self
    {
        return static::firstOrCreate(
            ['is_walk_in' => true],
            ['name' => self::WALK_IN_NAME, 'is_active' => true, 'is_taxable' => false],
        );
    }

    public function isWalkIn(): bool
    {
        return (bool) $this->is_walk_in;
    }
}
