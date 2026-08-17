<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class EmployeeGroup extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'name',
    ];

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class, 'group_id');
    }
}
