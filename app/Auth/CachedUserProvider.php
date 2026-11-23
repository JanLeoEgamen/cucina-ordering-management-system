<?php

namespace App\Auth;

use App\Support\AuthCache;
use Illuminate\Auth\EloquentUserProvider;
use Illuminate\Support\Facades\Cache;

// Eloquent's provider, except that the user a session points to comes from the cache (see
// AuthCache) with the roles and permissions the permission middleware is about to ask for.
class CachedUserProvider extends EloquentUserProvider
{
    public function retrieveById($identifier)
    {
        $ttl = AuthCache::ttl();

        if ($ttl === 0) {
            return parent::retrieveById($identifier);
        }

        return Cache::remember(AuthCache::key($identifier), $ttl, function () use ($identifier) {
            $user = parent::retrieveById($identifier);

            // Preloaded here so the permission checks of the request cost no query either.
            return $user?->load(['roles', 'permissions']);
        });
    }
}
