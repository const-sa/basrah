<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Support\SystemRegistry;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable;

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
     * هل يصل المستخدم إلى النظام المحدد (مثل: pos أو accounting)؟
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
