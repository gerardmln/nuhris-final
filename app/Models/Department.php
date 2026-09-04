<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Builder;

class Department extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
    ];

    public function scopeSchools(Builder $query): Builder
    {
        $schools = config('hris.department_schools', []);

        if (empty($schools)) {
            return $query;
        }

        return $query->whereIn('name', $schools);
    }

    /**
     * Faculty-assignable schools only — excludes the "ASP" catch-all
     * department that is reserved for Admin Support Personnel positions.
     */
    public function scopeFacultySchools(Builder $query): Builder
    {
        return $query->schools()->where('name', '!=', 'ASP');
    }

    /**
     * Make sure every school listed in config/hris.php exists in the DB.
     * Hostinger deploys often skip seeders, which breaks SHS auto-assign.
     */
    public static function ensureConfiguredSchoolsExist(): void
    {
        foreach (config('hris.department_schools', []) as $name) {
            static::firstOrCreate(['name' => $name]);
        }
    }

    /**
     * Resolve (or create) the Senior High School department id.
     */
    public static function shsId(): ?int
    {
        $id = static::query()->where('name', 'like', 'SHS%')->value('id');

        if (filled($id)) {
            return (int) $id;
        }

        return (int) static::firstOrCreate([
            'name' => 'SHS - Senior High School',
        ])->id;
    }

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class);
    }
}
