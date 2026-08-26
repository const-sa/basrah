<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * مركز تكلفة — لكل قاعة وشاليه واحد، وواحد للمحل (§الطبقة أ - بند 4).
 * هو ما يجعل ربحية كل وحدة على حدة قابلة للقياس.
 */
class CostCenter extends Model
{
    protected $fillable = ['code', 'name', 'unit_id', 'unit_section_id', 'department_id', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(UnitSection::class, 'unit_section_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(JournalLine::class);
    }

    /**
     * إيراد ومصروف وربح المركز خلال فترة، من القيود المرحَّلة فقط.
     *
     * @return array{revenue: float, expense: float, profit: float}
     */
    public function profitability(?string $from = null, ?string $to = null): array
    {
        $lines = JournalLine::query()
            ->where('cost_center_id', $this->id)
            ->whereHas('entry', fn ($q) => $q->whereIn('status', JournalEntry::EFFECTIVE_STATUSES)
                ->when($from, fn ($sub) => $sub->whereDate('entry_date', '>=', $from))
                ->when($to, fn ($sub) => $sub->whereDate('entry_date', '<=', $to)))
            ->join('accounts', 'accounts.id', '=', 'journal_lines.account_id')
            ->selectRaw('accounts.type, SUM(journal_lines.debit) AS d, SUM(journal_lines.credit) AS c')
            ->groupBy('accounts.type')
            ->get();

        // الإيراد يزيد بالدائن، والمصروف يزيد بالمدين.
        $revenue = (float) ($lines->firstWhere('type', 'revenue')?->c ?? 0)
            - (float) ($lines->firstWhere('type', 'revenue')?->d ?? 0);
        $expense = (float) ($lines->firstWhere('type', 'expense')?->d ?? 0)
            - (float) ($lines->firstWhere('type', 'expense')?->c ?? 0);

        return [
            'revenue' => round($revenue, 2),
            'expense' => round($expense, 2),
            'profit' => round($revenue - $expense, 2),
        ];
    }

    /**
     * مركز تكلفة الوحدة، ويُنشأ تلقائيًا إن لم يوجد حتى لا يضيع قيد.
     */
    public static function forUnit(Unit $unit): self
    {
        return static::firstOrCreate(
            ['unit_id' => $unit->id],
            ['code' => 'CC-'.$unit->code, 'name' => $unit->name, 'is_active' => true],
        );
    }

    /**
     * The centre of one section of a unit — a room of a chalet, a side of a
     * hall. Without it a unit let by the section reports one figure for all
     * of them, and which room earned the money is unanswerable.
     */
    public static function forSection(UnitSection $section): self
    {
        $section->loadMissing('unit');

        return static::firstOrCreate(
            ['unit_section_id' => $section->id],
            [
                'code' => 'CC-'.($section->unit?->code ?: 'U'.$section->unit_id).'-S'.$section->id,
                // The unit's name travels with it: «شاليه ١» alone names nothing
                // on a screen listing every unit's sections together.
                'name' => $section->unit?->name ? $section->unit->name.' — '.$section->name : $section->name,
                'unit_id' => null,
                'is_active' => true,
            ],
        );
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /**
     * مركز تكلفة قسم — به تُقاس ربحية النشاط منفصلة عن بقية الأقسام.
     */
    public static function forDepartment(Department $department): self
    {
        return static::firstOrCreate(
            ['department_id' => $department->id],
            [
                'code' => 'CC-'.($department->code ?: $department->id),
                'name' => $department->name,
                'is_active' => true,
            ],
        );
    }

    /**
     * مركز التكلفة العام لما لا يتبع وحدة ولا قسمًا.
     */
    public static function general(): self
    {
        return static::firstOrCreate(
            ['code' => 'CC-GEN'],
            ['name' => 'عام', 'unit_id' => null, 'department_id' => null, 'is_active' => true],
        );
    }
}
