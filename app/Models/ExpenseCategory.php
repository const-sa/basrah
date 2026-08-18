<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * نوع المصروف — كهرباء، مياه، صيانة، نظافة، مشتريات…
 *
 * النوع هو الجسر بين لغتين: الموظف المالي يقول «فاتورة كهرباء»، والدفتر
 * يريد حسابًا في الشجرة ومركز تكلفة. فالنوع يحمل حسابه، ويُدار من شاشة
 * الإدارة — فإضافة «غاز» لا تستدعي فتح شجرة الحسابات.
 */
class ExpenseCategory extends Model
{
    use SoftDeletes;

    /**
     * الأنواع التي نصّ عليها البند التاسع: [الرمز, الاسم, كود الحساب].
     *
     * موضعها في النموذج لا في البذرة لأن الهجرة تحتاجها أيضًا — القاعدة
     * القائمة تُملأ بالهجرة، والقاعدة الجديدة بالبذرة، والقائمة واحدة.
     *
     * @var list<array{0: string, 1: string, 2: string}>
     */
    public const DEFAULTS = [
        ['electricity', 'كهرباء', '5320'],
        ['water', 'مياه', '5325'],
        ['maintenance', 'صيانة', '5330'],
        ['cleaning', 'نظافة', '5340'],
        ['purchases', 'مشتريات', '5370'],
        ['salaries', 'رواتب', '5210'],
        ['rent', 'إيجارات', '5360'],
        ['services', 'خدمات', '5380'],
        ['marketing', 'تسويق ودعاية', '5350'],
        ['internet', 'إنترنت واتصالات', '5390'],
        ['spare_parts', 'قطع غيار', '5395'],
        ['operational', 'مصروفات تشغيلية أخرى', '5310'],
    ];

    protected $fillable = [
        'code', 'name', 'description', 'account_id', 'cost_center_id',
        'is_active', 'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'is_system' => 'boolean',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /**
     * مركز التكلفة الافتراضي — يُقترح عند التسجيل ويبقى قابلًا للتغيير.
     */
    public function costCenter(): BelongsTo
    {
        return $this->belongsTo(CostCenter::class);
    }

    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('name');
    }

    /**
     * إنشاء الأنواع المنصوص عليها — ما وُجد منها بالرمز لا يُنشأ ثانيةً،
     * وما أعادت الإدارة تسميته أو ربطه يبقى على ما عدّلته.
     *
     * تُستدعى من بذرة الحسابات ومن الهجرة معًا، فتصلح للقاعدتين.
     */
    public static function seedDefaults(): void
    {
        $accounts = Account::pluck('id', 'code');
        $fallback = $accounts['5310'] ?? $accounts->first();

        // لا شجرة حسابات بعد — لا موضع تُعلَّق عليه الأنواع.
        if (! $fallback) {
            return;
        }

        foreach (self::DEFAULTS as $index => [$code, $name, $accountCode]) {
            if (self::withTrashed()->where('code', $code)->exists()) {
                continue;
            }

            self::create([
                'code' => $code,
                'name' => $name,
                'account_id' => $accounts[$accountCode] ?? $fallback,
                'is_active' => true,
                'sort_order' => $index + 1,
            ])->forceFill(['is_system' => true])->save();
        }
    }
}
