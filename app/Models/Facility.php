<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * مرفق (مسبح، مطبخ، موقف…) يُسند لأقسام الوحدات.
 *
 * المرفق المشترك بين قسمين يجعل خصوصيتهما مسألة تشغيلية لا شكلية،
 * لذلك يُعلَّم بـ is_shared على مستوى الإسناد لا على المرفق نفسه:
 * المسبح قد يكون مشتركًا في وحدة ومنفصلًا في أخرى.
 */
class Facility extends Model
{
    protected $fillable = ['name', 'icon', 'description', 'sort_order', 'is_active'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function sections(): BelongsToMany
    {
        return $this->belongsToMany(UnitSection::class, 'facility_unit_section')
            ->withPivot('is_shared')
            ->withTimestamps();
    }

    /**
     * عدد الوحدات التي يظهر فيها هذا المرفق.
     */
    public function unitsCount(): int
    {
        return $this->sections()
            ->distinct()
            ->count('unit_sections.unit_id');
    }
}
