<?php

namespace App\Modules\Auth\Concerns;

use App\Modules\Auth\Models\SocialAccount;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

trait HasSocialAccounts
{
    public function socialAccounts(): HasMany
    {
        return $this->hasMany(SocialAccount::class);
    }

    public function googleAccount(): HasOne
    {
        return $this->hasOne(SocialAccount::class)
            ->where('provider', 'google');
    }

    public function hasSocialAccount(string $provider): bool
    {
        return $this->socialAccounts()
            ->where('provider', $provider)
            ->exists();
    }

    public function socialAccount(string $provider): ?SocialAccount
    {
        return $this->socialAccounts()
            ->where('provider', $provider)
            ->first();
    }
}
