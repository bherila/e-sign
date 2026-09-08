<?php

declare(strict_types=1);

namespace App\Domain\Identity\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A local user's binding to an identity-provider subject.
 *
 * The tuple is (issuer, subject) and is unique in the database. Email never participates:
 * `forIssuerSubject()` is the only supported lookup, so no code path can drift into linking
 * an account by address. `issuer` corresponds to the `provider` field of
 * BWH\Auth\OAuth\OAuthIdentity, which the SSO login flow (issue #12) will hand to
 * `forIssuerSubject()` to resolve the local user.
 *
 * @property int $id
 * @property int $user_id
 * @property string $issuer
 * @property string $subject
 * @property Carbon|null $last_seen_at
 */
class IdentityBinding extends Model
{
    protected $fillable = [
        'user_id',
        'issuer',
        'subject',
        'last_seen_at',
    ];

    protected function casts(): array
    {
        return [
            'last_seen_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @param  Builder<IdentityBinding>  $query
     * @return Builder<IdentityBinding>
     */
    public function scopeForIssuerSubject(Builder $query, string $issuer, string $subject): Builder
    {
        return $query->where('issuer', $issuer)->where('subject', $subject);
    }
}
