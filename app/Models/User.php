<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Support\ActivitySegment;
use App\Support\SystemRegistry;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, SoftDeletes;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'role_id',
        'employee_id',
        'is_active',
        'is_demo',
        'has_all_units',
    ];

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    /**
     * ملف الموارد البشرية المرتبط بحساب الدخول (اختياري).
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /**
     * الوحدات المصرّح لهذا المستخدم بالعمل عليها (نطاق الوصول).
     */
    public function units(): BelongsToMany
    {
        return $this->belongsToMany(Unit::class)->withTimestamps();
    }

    /**
     * The inbox. Overridden to return our own notification rather than the
     * framework's, so the added columns reach everything that reads it.
     */
    public function notifications(): MorphMany
    {
        return $this->morphMany(Notification::class, 'notifiable')->latest();
    }

    public function isSuperAdmin(): bool
    {
        return (bool) $this->role?->isSuperAdmin();
    }

    /**
     * هل يملك المستخدم الصلاحية المحددة (مثل: clients.edit)؟
     * المستخدم غير المفعّل لا يملك أي صلاحية.
     */
    public function hasPermission(string $permission): bool
    {
        if (! $this->is_active) {
            return false;
        }

        return (bool) $this->role?->hasPermission($permission);
    }

    /**
     * هل يملك أيًّا من الصلاحيات المعطاة؟
     *
     * لازمٌ بعد فصل القاعات عن الشاليهات: شاشةٌ تخدم النشاطين تظهر لمن
     * يملك أحدهما، فسؤالها عن مفتاح واحد يُخفيها عن نصف من يستحقها.
     */
    public function hasAnyPermission(string ...$permissions): bool
    {
        foreach ($permissions as $permission) {
            if ($this->hasPermission($permission)) {
                return true;
            }
        }

        return false;
    }

    /**
     * هل يصل المستخدم إلى النظام المحدد (مثل: pools أو accounting)؟
     */
    public function hasSystemAccess(string $system): bool
    {
        if (! $this->is_active) {
            return false;
        }

        return (bool) $this->role?->hasSystemAccess($system);
    }

    /**
     * الأنظمة التي يصل إليها المستخدم — تُمرَّر للواجهة لبناء الشريط الجانبي.
     *
     * @return list<string>
     */
    public function accessibleSystems(): array
    {
        if (! $this->is_active) {
            return [];
        }

        return $this->role?->accessibleSystems() ?? [];
    }

    /**
     * هل يرى المستخدم كل الوحدات دون تقييد؟ (المالك والمحاسب)
     */
    public function seesAllUnits(): bool
    {
        return $this->isSuperAdmin() || (bool) $this->has_all_units;
    }

    /**
     * معرّفات الوحدات التي يعمل عليها المستخدم.
     * تُرجع null إذا كان يرى كل الوحدات — أي «بلا تقييد» لا «لا شيء».
     *
     * @return list<int>|null
     */
    public function accessibleUnitIds(): ?array
    {
        if ($this->seesAllUnits()) {
            return null;
        }

        return $this->units()->pluck('units.id')->all();
    }

    /**
     * The cost centres this user may spend on — null when unrestricted.
     * The pools have no unit, so the employee file's department names theirs.
     *
     * @return list<int>|null
     */
    public function accessibleCostCenterIds(): ?array
    {
        $unitIds = $this->accessibleUnitIds();

        if ($unitIds === null || $this->isAccountingDepartment()) {
            return null;
        }

        $unitIds = array_values(array_unique([...$unitIds, ...$this->activityUnitIds($unitIds)]));
        $this->ensureUnitCenters($unitIds);

        $departmentIds = array_values(array_unique(array_filter([
            $this->employee?->department_id,
            ...$this->systemDepartmentIds(),
        ])));

        return CostCenter::query()
            ->where(fn ($q) => $q
                ->whereIn('unit_id', $unitIds)
                ->orWhereHas('section', fn ($s) => $s->whereIn('unit_id', $unitIds))
                ->when($departmentIds, fn ($sub, $ids) => $sub->orWhereIn('department_id', $ids)))
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * The units of each activity — halls, chalets — whose system this user's
     * role opens while no unit of that activity is ticked on the user's card.
     *
     * Like the pools below: an employee of a department with no unit ticked
     * had no centre at all, so the department's expenses were hidden from them
     * and the form had nothing to charge to. A ticked unit still narrows the
     * activity to that unit — a supervisor of one hall keeps seeing one hall.
     *
     * @param  list<int>  $tickedUnitIds
     * @return list<int>
     */
    private function activityUnitIds(array $tickedUnitIds): array
    {
        $systems = $this->accessibleSystems();
        $tickedTypes = Unit::whereIn('id', $tickedUnitIds)->pluck('type')->unique()->all();

        $openTypes = collect([ActivitySegment::HALLS => 'hall', ActivitySegment::CHALETS => 'chalet'])
            ->filter(fn (string $type, string $system) => in_array($system, $systems, true) && ! in_array($type, $tickedTypes, true))
            ->values()
            ->all();

        if (! $openTypes) {
            return [];
        }

        return Unit::whereIn('type', $openTypes)->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    /**
     * A unit's centre is made by its first movement, so a unit ticked on the
     * card before any booking or expense had none: its employee was told the
     * account is tied to nothing and could not record a thing. Each unit in
     * scope gets its centre here — as the pools centre is made below.
     *
     * @param  list<int>  $unitIds
     */
    private function ensureUnitCenters(array $unitIds): void
    {
        $missing = array_diff($unitIds, CostCenter::whereIn('unit_id', $unitIds)->pluck('unit_id')->all());

        if ($missing) {
            Unit::whereIn('id', $missing)->get()->each(fn (Unit $unit) => CostCenter::forUnit($unit));
        }
    }

    /**
     * The accounting department follows the spend of every department and
     * charges any of them — everyone in it, not one account ticked by hand.
     * It is the role that opens the accounting and no activity: a supervisor
     * who records his unit's spend in the ledger stays on his unit.
     */
    private function isAccountingDepartment(): bool
    {
        $systems = $this->accessibleSystems();

        return in_array('accounting', $systems, true)
            && ! array_intersect(ActivitySegment::activities(), $systems);
    }

    /**
     * The department each system's work belongs to — the pools have no unit,
     * and the office systems (HR, administration) have none either. The
     * accounting is absent: it sees every centre (accessibleCostCenterIds).
     */
    private const SYSTEM_DEPARTMENTS = [
        ActivitySegment::POOLS => ActivitySegment::POOLS_DEPARTMENT,
        'hr' => 'ADMIN',
        'system' => 'ADMIN',
    ];

    /**
     * The departments whose systems this user's role opens.
     *
     * Such a department has no unit to tick on the user's card, so an employee
     * of it with no employee file behind the account had no centre at all: the
     * department's expenses were hidden from them and the form had nothing to
     * charge to. Access to the system is access to its department's centre —
     * which is made here if no movement has made it yet, so the form has it.
     *
     * @return list<int>
     */
    private function systemDepartmentIds(): array
    {
        $codes = array_values(array_unique(array_intersect_key(
            self::SYSTEM_DEPARTMENTS,
            array_flip($this->accessibleSystems()),
        )));

        if (! $codes) {
            return [];
        }

        return Department::whereIn('code', $codes)->get()
            ->each(fn (Department $department) => CostCenter::forDepartment($department))
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * May this user spend on that centre? A scoped one may not spend on none:
     * an expense carrying no centre belongs to no activity and no one's scope.
     */
    public function canSpendOn(?int $costCenterId): bool
    {
        $allowed = $this->accessibleCostCenterIds();

        if ($allowed === null) {
            return true;
        }

        return $costCenterId !== null && in_array($costCenterId, $allowed, true);
    }

    /**
     * هل يملك المستخدم حق العمل على هذه الوحدة تحديدًا؟
     */
    public function canAccessUnit(Unit|int $unit): bool
    {
        if ($this->seesAllUnits()) {
            return true;
        }

        $unitId = $unit instanceof Unit ? $unit->id : $unit;

        return in_array($unitId, $this->accessibleUnitIds() ?? [], true);
    }

    /**
     * كل مفاتيح الصلاحيات الفعلية للمستخدم — تُمرَّر للواجهة لإخفاء الأزرار.
     *
     * @return list<string>
     */
    public function permissionKeys(): array
    {
        if (! $this->is_active) {
            return [];
        }

        if ($this->isSuperAdmin()) {
            return SystemRegistry::permissionKeys();
        }

        return array_values($this->role?->permissions ?? []);
    }

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'is_demo' => 'boolean',
            'has_all_units' => 'boolean',
        ];
    }
}
