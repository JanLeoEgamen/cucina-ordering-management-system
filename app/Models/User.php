<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\PermissionRegistrar;
use Spatie\Permission\Traits\HasRoles;

// Users and the roles they hold. The rows are read and written with the query
// builder; the model stays Eloquent for the auth, the factories and Spatie's
// roles relation.
class User extends Authenticatable
{
    use HasFactory, HasRoles, Notifiable, SoftDeletes;

    protected const TABLE = 'users';

    protected const PIVOT = 'model_has_roles';

    protected $table = self::TABLE;

    // Insertable and updatable columns.
    protected $fillable = [
        'first_name',
        'last_name',
        'company',
        'email',
        'password',
        'profile_completed_at',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    // Casts apply to Eloquent reads and writes; the query builder methods below
    // hash the password themselves.
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'profile_completed_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    // The person's full name, for display (the sidebar, "created by" ...). It is
    // computed from the two columns, so there is no `name` column to keep in step.
    protected function name(): Attribute
    {
        return Attribute::get(fn () => trim($this->first_name . ' ' . $this->last_name));
    }

    // The same full name as a SQL expression, for the query builder joins that show
    // who a customer / creator / verifier is: User::fullNameSql('verifiers') . ' as x'.
    // CONCAT is not in SQLite (the tests), where || joins strings.
    public static function fullNameSql(string $table = self::TABLE): string
    {
        $first = $table . '.first_name';
        $last = $table . '.last_name';

        return DB::connection()->getDriverName() === 'sqlite'
            ? "TRIM({$first} || ' ' || {$last})"
            : "TRIM(CONCAT({$first}, ' ', {$last}))";
    }

    /* -------- Reads -------- */

    // Get all live users with their role names, for the table feed.
    public static function getAll(): Collection
    {
        $roles = self::rolesByUser();

        return DB::table(self::TABLE)
            ->whereNull('deleted_at')
            ->select(['id', 'first_name', 'last_name', DB::raw(self::fullNameSql() . ' as name'), 'company', 'email'])
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->get()
            ->map(function (object $user) use ($roles) {
                $user->role_names = $roles[$user->id] ?? [];

                return $user;
            });
    }

    // Get one user with their roles, for the edit and view drawers.
    public static function findById(int $userId): ?object
    {
        $user = DB::table(self::TABLE)
            ->where('id', $userId)
            ->whereNull('deleted_at')
            ->first();

        if (! $user) {
            return null;
        }

        // The view drawer prints the full name; the edit drawer fills the two parts.
        $user->name = trim($user->first_name . ' ' . $user->last_name);

        // Ids tick the checkboxes in the edit drawer, names are printed by the
        // view drawer, so both travel with the record.
        $user->roles = self::roleIdsOfUser($userId);
        $user->role_names = self::roleNamesOfUser($userId);

        return $user;
    }

    // Check whether a live user exists (before updating or deleting one).
    public static function existsById(int $userId): bool
    {
        return DB::table(self::TABLE)
            ->where('id', $userId)
            ->whereNull('deleted_at')
            ->exists();
    }

    // Role names of every user, keyed by user id: the feed needs them in one query.
    public static function rolesByUser(): Collection
    {
        return DB::table(self::PIVOT)
            ->join('roles', 'roles.id', '=', self::PIVOT . '.role_id')
            ->where(self::PIVOT . '.model_type', self::class)
            ->orderBy('roles.name')
            ->get([self::PIVOT . '.model_id', 'roles.name'])
            ->groupBy('model_id')
            ->map(fn (Collection $rows) => $rows->pluck('name')->all());
    }

    // Ids of one user's roles, for the checkboxes in the edit drawer.
    public static function roleIdsOfUser(int $userId): array
    {
        return DB::table(self::PIVOT)
            ->where('model_type', self::class)
            ->where('model_id', $userId)
            ->pluck('role_id')
            ->map(fn ($roleId) => (int) $roleId)
            ->all();
    }

    // Names of one user's roles, for the view drawer.
    public static function roleNamesOfUser(int $userId): array
    {
        return DB::table(self::PIVOT)
            ->join('roles', 'roles.id', '=', self::PIVOT . '.role_id')
            ->where(self::PIVOT . '.model_type', self::class)
            ->where(self::PIVOT . '.model_id', $userId)
            ->orderBy('roles.name')
            ->pluck('roles.name')
            ->all();
    }

    /* -------- Writes -------- */

    // Create a user and return their id. The query builder does not go through
    // the model's casts, so the password is hashed here.
    public static function createUser(array $attributes, array $roleIds): int
    {
        $now = now();

        $userId = (int) DB::table(self::TABLE)->insertGetId([
            'first_name' => $attributes['first_name'],
            'last_name' => $attributes['last_name'],
            'company' => $attributes['company'] ?? null,
            'email' => $attributes['email'],
            'password' => Hash::make($attributes['password']),
            // An admin typed the details in: nothing left to ask this person (see EnsureProfileIsComplete).
            'profile_completed_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        self::syncUserRoles($userId, $roleIds);

        return $userId;
    }

    // Update a user and replace their roles. A blank password keeps the stored one.
    public static function updateUser(int $userId, array $attributes, array $roleIds): int
    {
        $values = [
            'first_name' => $attributes['first_name'],
            'last_name' => $attributes['last_name'],
            'company' => $attributes['company'] ?? null,
            'email' => $attributes['email'],
            'updated_at' => now(),
        ];

        if (! empty($attributes['password'])) {
            $values['password'] = Hash::make($attributes['password']);
        }

        $changed = DB::table(self::TABLE)
            ->where('id', $userId)
            ->whereNull('deleted_at')
            ->update($values);

        self::syncUserRoles($userId, $roleIds);

        return $changed;
    }

    // Soft delete a user and drop their role rows, so a deleted account keeps
    // no access. Nothing restores it: this app has no restore path.
    public static function deleteUser(int $userId): bool
    {
        $now = now();

        $deleted = DB::table(self::TABLE)
            ->where('id', $userId)
            ->whereNull('deleted_at')
            ->update(['deleted_at' => $now, 'updated_at' => $now]) === 1;

        if ($deleted) {
            self::syncUserRoles($userId, []);
        }

        return $deleted;
    }

    // Replace a user's roles: delete that user's rows, insert the posted ids.
    public static function syncUserRoles(int $userId, array $roleIds): void
    {
        DB::table(self::PIVOT)
            ->where('model_type', self::class)
            ->where('model_id', $userId)
            ->delete();

        $rows = collect($roleIds)
            ->map(fn ($roleId) => (int) $roleId)
            ->unique()
            ->map(fn (int $roleId) => [
                'role_id' => $roleId,
                'model_type' => self::class,
                'model_id' => $userId,
            ])
            ->values()
            ->all();

        if ($rows) {
            DB::table(self::PIVOT)->insert($rows);
        }

        // The permission map is cached, so every write has to drop it.
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}