<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Support\SystemRegistry;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
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

        if ($unitIds === null) {
            return null;
        }

        $departmentId = $this->employee?->department_id;

        return CostCenter::query()
            ->where(fn ($q) => $q
                ->whereIn('unit_id', $unitIds)
                ->orWhereHas('section', fn ($s) => $s->whereIn('unit_id', $unitIds))
                ->when($departmentId, fn ($sub, $id) => $sub->orWhere('department_id', $id)))
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
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
