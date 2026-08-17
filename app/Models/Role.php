<?php

namespace App\Models;

use App\Support\SystemRegistry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Role extends Model
{
    protected $fillable = [
        'name',
        'slug',
        'description',
        'permissions',
    ];

    protected function casts(): array
    {
        return [
            'permissions' => 'array',
        ];
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function isSuperAdmin(): bool
    {
        return $this->slug === 'super-admin';
    }

    /**
     * هل يملك هذا الدور الصلاحية المحددة (مثل: clients.create)؟
     */
    public function hasPermission(string $permission): bool
    {
        if ($this->isSuperAdmin()) {
            return true;
        }

        return in_array($permission, $this->permissions ?? [], true);
    }

    /**
     * هل يملك هذا الدور أي صلاحية داخل النظام المحدد (مثل: accounting)؟
     * تُستخدم لإظهار أو إخفاء النظام بالكامل من الشريط الجانبي.
     */
    public function hasSystemAccess(string $system): bool
    {
        if ($this->isSuperAdmin()) {
            return true;
        }

        $granted = $this->permissions ?? [];

        return (bool) array_intersect(SystemRegistry::systemPermissionKeys($system), $granted);
    }

    /**
     * قائمة الأنظمة التي يصل إليها هذا الدور.
     *
     * @return list<string>
     */
    public function accessibleSystems(): array
    {
        return array_values(array_filter(
            array_keys(SystemRegistry::SYSTEMS),
            fn (string $system) => $this->hasSystemAccess($system),
        ));
    }
}
