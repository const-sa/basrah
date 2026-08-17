<?php

namespace App\Models;

use App\Services\Accounting\Ledger;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * طريقة دفع — نقدًا، حوالة، شبكة، أو ما يضيفه المستخدم من شاشة الإعدادات.
 *
 * الصف يحمل ما كان مبثوثًا في الكود: الحساب الذي يهبط عليه المقبوض
 * (`deposits_to`)، وهل الطريقة آجلة (`is_credit`). فإضافة طريقة صارت صفًّا
 * لا تعديلَ خمسة ملفات.
 *
 * والطرق مشتركة في النظام كله: المفعّلة تظهر في دفعات الحجوزات وفواتير
 * الكاشير وسندات القبض والصرف سواء.
 */
class PaymentMethod extends Model
{
    use SoftDeletes;

    /**
     * الحساب الذي يستقبل المقبوض — الوصف بالعربية للشاشة، والكود للدفاتر.
     */
    public const DESTINATIONS = [
        'cash' => 'الصندوق النقدي',
        'bank' => 'الحساب البنكي',
    ];

    /**
     * كود الطريقة النقدية — افتراض كل خدمة عند غياب اختيار المستخدم.
     */
    public const CASH = 'cash';

    protected $fillable = [
        'code',
        'name',
        'deposits_to',
        'is_credit',
        'is_active',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'is_credit' => 'boolean',
            'is_active' => 'boolean',
            'is_system' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /**
     * الطرق التي يبدأ بها النظام — نفس ما كان في الثوابت الثلاثة القديمة
     * مجموعًا بلا تكرار.
     *
     * تقرؤها الهجرة وبذرة قاعدة البيانات معًا، فتبقى في موضع واحد.
     *
     * @return list<array<string, mixed>>
     */
    public static function defaults(): array
    {
        $now = now();

        return [
            [
                'code' => 'cash', 'name' => 'نقدًا', 'deposits_to' => 'cash', 'is_credit' => false,
                'is_active' => true, 'is_system' => true, 'sort_order' => 1,
                'created_at' => $now, 'updated_at' => $now,
            ],
            [
                'code' => 'transfer', 'name' => 'تحويل بنكي', 'deposits_to' => 'bank', 'is_credit' => false,
                'is_active' => true, 'is_system' => false, 'sort_order' => 2,
                'created_at' => $now, 'updated_at' => $now,
            ],
            [
                'code' => 'card', 'name' => 'شبكة', 'deposits_to' => 'bank', 'is_credit' => false,
                'is_active' => true, 'is_system' => false, 'sort_order' => 3,
                'created_at' => $now, 'updated_at' => $now,
            ],
            [
                'code' => 'online', 'name' => 'دفع إلكتروني', 'deposits_to' => 'bank', 'is_credit' => false,
                'is_active' => true, 'is_system' => false, 'sort_order' => 4,
                'created_at' => $now, 'updated_at' => $now,
            ],
            [
                'code' => 'account', 'name' => 'على الحساب', 'deposits_to' => 'cash', 'is_credit' => true,
                'is_active' => true, 'is_system' => true, 'sort_order' => 5,
                'created_at' => $now, 'updated_at' => $now,
            ],
        ];
    }

    // ── العلاقات ──────────────────────────────────────────────

    public function bookingPayments(): HasMany
    {
        return $this->hasMany(BookingPayment::class);
    }

    public function sales(): HasMany
    {
        return $this->hasMany(Sale::class);
    }

    public function vouchers(): HasMany
    {
        return $this->hasMany(Voucher::class);
    }

    /**
     * هل الطريقة مستعملة في أي مستند؟ الحذف ممنوع عندها حتى لا تصير
     * الدفعات القديمة بلا طريقة.
     */
    public function isInUse(): bool
    {
        return $this->bookingPayments()->exists()
            || $this->sales()->exists()
            || $this->vouchers()->exists();
    }

    /**
     * عدد المستندات المرتبطة — يُعرض في الشاشة قبل الحذف.
     */
    public function usageCount(): int
    {
        return $this->bookingPayments()->count()
            + $this->sales()->count()
            + $this->vouchers()->count();
    }

    // ── الدفاتر ──────────────────────────────────────────────

    /**
     * كود الحساب الذي يستقبل المقبوض بهذه الطريقة.
     *
     * الموضع الوحيد الذي يترجم طريقة الدفع إلى حساب. كانت الترجمة مكرّرة في
     * ثلاث `match` متباعدة تختلف فيما بينها.
     */
    public function ledgerAccount(): string
    {
        return $this->deposits_to === 'bank' ? Ledger::BANK : Ledger::CASH;
    }

    /**
     * الحساب الذي يُقيَّد عليه المرتجع — الآجل يعود على ذمة العميل لا على
     * الخزينة، فالمال لم يُقبض منه أصلًا.
     */
    public function refundAccount(): string
    {
        return $this->is_credit ? Ledger::RECEIVABLES : $this->ledgerAccount();
    }

    public function destinationLabel(): string
    {
        return self::DESTINATIONS[$this->deposits_to] ?? $this->deposits_to;
    }

    // ── النطاقات ─────────────────────────────────────────────

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * ترتيب العرض كما رتّبه المستخدم، والاسم فاصلٌ عند التساوي.
     */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('name');
    }

    /**
     * خيارات الطرق كما تحتاجها كل شاشة — مفتاحٌ ونصٌّ وسِمة الآجل.
     *
     * قائمة واحدة للنظام كله: الطريقة المفعّلة تظهر في الحجوزات والكاشير
     * والسندات سواء.
     *
     * @return list<array{id: int, code: string, label: string, is_credit: bool}>
     */
    public static function options(): array
    {
        return self::query()
            ->active()
            ->ordered()
            ->get()
            ->map(fn (self $m) => [
                'id' => $m->id,
                'code' => $m->code,
                'label' => $m->name,
                'is_credit' => $m->is_credit,
            ])
            ->all();
    }

    /**
     * الطريقة الافتراضية — النقد إن كان مفعّلًا، وإلا أول المفعّل.
     *
     * الخدمات تستدعيها حين لا يمرّر المستخدم طريقة، فلا يبقى في الكود كود
     * مكتوب نصًّا يفترض وجود «cash» مفعّلًا.
     */
    public static function default(): self
    {
        return self::query()->active()->where('code', self::CASH)->first()
            ?? self::query()->active()->ordered()->firstOrFail();
    }
}
