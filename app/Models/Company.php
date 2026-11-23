<?php

namespace App\Models;

use App\Support\TableCache;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

// Companies the people ordering work for. Same shape as PaymentMethod: query builder reads and
// writes below, with the soft delete filter written in every method. A user belongs to at most
// one (`users.company_id`); the register / profile / Users forms pick it from a dropdown.
class Company extends Model
{
    use HasFactory, SoftDeletes;

    // The company for people who do not belong to any of the others.
    public const GUEST = 'Guest';

    // Locked companies: they cannot be edited or deleted from the app, only chosen. Add a name
    // here to lock another one, and remove it to unlock it again. Nothing in the UI can change
    // this list; the lock follows the name (like PaymentMethod::PROTECTED).
    public const PROTECTED = [
        self::GUEST,
    ];

    // Table name, used by the query builder methods and by Eloquent.
    protected const TABLE = 'companies';

    protected $table = self::TABLE;

    // Primary key of the table.
    protected $primaryKey = 'company_id';

    // Insertable and updatable columns.
    protected const COLUMNS = [
        'company_name',
        'is_active',
    ];

    protected $fillable = self::COLUMNS;

    // Casts apply to Eloquent reads; query builder rows come back as raw values.
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /* -------- Reads -------- */

    // Get all live companies with how many live users belong to each, for the table feed.
    public static function getAll(): Collection
    {
        return TableCache::remember(['companies', 'users'], 'company.all', fn () => self::loadAll());
    }

    // The query behind the cached method above.
    protected static function loadAll(): Collection
    {
        $users = DB::table('users')
            ->selectRaw('count(*)')
            ->whereColumn('users.company_id', self::TABLE . '.company_id')
            ->whereNull('users.deleted_at');

        return DB::table(self::TABLE)
            ->whereNull(self::TABLE . '.deleted_at')
            ->select([self::TABLE . '.company_id', self::TABLE . '.company_name', self::TABLE . '.is_active'])
            ->selectSub($users, 'users_count')
            ->orderBy(self::TABLE . '.company_name')
            ->get()
            ->map(function (object $company) {
                // The feed marks a locked company, so the table can show it and the buttons stay away.
                $company->is_protected = self::isProtectedName($company->company_name);

                return $company;
            });
    }

    // Get one company's row, for the edit and view drawers.
    public static function findById(int $companyId): ?object
    {
        $company = DB::table(self::TABLE)
            ->where('company_id', $companyId)
            ->whereNull('deleted_at')
            ->first();

        if ($company) {
            $company->users_count = self::countUsers($companyId);
            $company->is_protected = self::isProtectedName($company->company_name);
        }

        return $company;
    }

    // Is this company locked from editing and deleting? Read from the table by name.
    public static function isProtected(int $companyId): bool
    {
        $name = DB::table(self::TABLE)->where('company_id', $companyId)->value('company_name');

        return $name !== null && self::isProtectedName($name);
    }

    // Locked by name, so the lock does not depend on which row it is.
    public static function isProtectedName(string $name): bool
    {
        return in_array($name, self::PROTECTED, true);
    }

    // Check whether a live company exists (before updating or deleting it).
    public static function existsById(int $companyId): bool
    {
        return DB::table(self::TABLE)
            ->where('company_id', $companyId)
            ->whereNull('deleted_at')
            ->exists();
    }

    // How many live users belong to the company. A company with people in it is not deleted.
    public static function countUsers(int $companyId): int
    {
        return DB::table('users')
            ->where('company_id', $companyId)
            ->whereNull('deleted_at')
            ->count();
    }

    // The companies a form can offer, as id + name. Only the active ones, plus (when given) the
    // one a person already belongs to even if it was switched off since: an edit form has to be
    // able to show it, or saving the person would silently move them.
    public static function options(?int $alsoId = null): array
    {
        return TableCache::remember(['companies'], 'company.options.' . (int) $alsoId, fn () => self::loadOptions($alsoId));
    }

    // The query behind the cached method above.
    protected static function loadOptions(?int $alsoId = null): array
    {
        return DB::table(self::TABLE)
            ->whereNull('deleted_at')
            ->where(function ($query) use ($alsoId) {
                $query->where('is_active', true);

                if ($alsoId) {
                    $query->orWhere('company_id', $alsoId);
                }
            })
            ->orderBy('company_name')
            ->get(['company_id', 'company_name', 'is_active'])
            ->map(fn (object $company) => [
                'id' => (int) $company->company_id,
                'name' => $company->company_name,
                'is_active' => (bool) $company->is_active,
            ])
            ->all();
    }

    // Every live company, active or not, for the Users page's edit drawer (any user may belong
    // to an inactive one). The create drawer only offers options().
    public static function allOptions(): array
    {
        return TableCache::remember(['companies'], 'company.all_options', fn () => self::loadAllOptions());
    }

    // The query behind allOptions().
    protected static function loadAllOptions(): array
    {
        return DB::table(self::TABLE)
            ->whereNull('deleted_at')
            ->orderBy('company_name')
            ->get(['company_id', 'company_name', 'is_active'])
            ->map(fn (object $company) => [
                'id' => (int) $company->company_id,
                'name' => $company->company_name,
                'is_active' => (bool) $company->is_active,
            ])
            ->all();
    }

    // The validation rules of a `company_id` field, shared by every form that asks for it. It is
    // required, and must be a live and active company. $currentId is the company the person
    // already has: it stays allowed when it was switched off, so saving an unrelated change does
    // not fail on it.
    public static function rules(?int $currentId = null): array
    {
        return [
            'required',
            'integer',
            Rule::exists(self::TABLE, 'company_id')->where(function ($query) use ($currentId) {
                $query->whereNull('deleted_at')->where(function ($query) use ($currentId) {
                    $query->where('is_active', true);

                    if ($currentId) {
                        $query->orWhere('company_id', $currentId);
                    }
                });
            }),
        ];
    }

    /* -------- Writes -------- */

    // Create a company and return its id.
    public static function createCompany(array $attributes): int
    {
        $now = now();

        return (int) DB::table(self::TABLE)->insertGetId(
            self::values($attributes) + ['created_at' => $now, 'updated_at' => $now]
        );
    }

    // Update a company; returns the number of changed rows (0 when it is gone).
    public static function updateCompany(int $companyId, array $attributes): int
    {
        return DB::table(self::TABLE)
            ->where('company_id', $companyId)
            ->whereNull('deleted_at')
            ->update(self::values($attributes) + ['updated_at' => now()]);
    }

    // Soft delete a company; false when it was already deleted.
    public static function deleteCompany(int $companyId): bool
    {
        $now = now();

        return DB::table(self::TABLE)
            ->where('company_id', $companyId)
            ->whereNull('deleted_at')
            ->update(['deleted_at' => $now, 'updated_at' => $now]) === 1;
    }

    /* -------- Internals -------- */

    // Keep only the writable columns, and store is_active as 0 or 1.
    protected static function values(array $attributes): array
    {
        $values = Arr::only($attributes, self::COLUMNS);

        if (array_key_exists('is_active', $values)) {
            $values['is_active'] = (int) (bool) $values['is_active'];
        }

        return $values;
    }
}
