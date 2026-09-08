<?php

declare(strict_types=1);

namespace App\Domain\Identity\Credentials;

use App\Domain\Identity\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * An API caller, as a principal in its own right.
 *
 * A service credential is not a person and does not stand in for one. It has no membership
 * row, no role, and no identity binding: it belongs to exactly one workspace and holds
 * exactly the scopes it was issued with. That is the whole of its authority, and it is
 * decided from this row before any resource is looked up.
 *
 * Only App\Domain\Identity\Credentials\ServiceCredentialIssuer creates, rotates, or revokes
 * these rows, and only it ever holds a plaintext secret. `secret_hash` and `secret_salt` are
 * hidden from serialisation so a credential cannot be leaked by being handed to a JSON
 * response; the plaintext is not here to leak at all.
 *
 * @property int $id
 * @property int $workspace_id
 * @property string $label
 * @property string $prefix
 * @property string $secret_salt
 * @property string $secret_hash
 * @property array<int, string> $scopes
 * @property CarbonImmutable|null $last_used_at
 * @property CarbonImmutable|null $expires_at
 * @property int|null $rotated_from_id
 * @property CarbonImmutable|null $revoked_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read Workspace $workspace
 * @property-read ServiceCredential|null $rotatedFrom
 */
class ServiceCredential extends Model
{
    /**
     * Everything about a credential except its secret material, which the issuer force-fills
     * so that no request payload can ever reach it through mass assignment.
     */
    protected $fillable = [
        'workspace_id',
        'label',
        'scopes',
        'expires_at',
    ];

    protected $hidden = [
        'secret_salt',
        'secret_hash',
    ];

    protected function casts(): array
    {
        return [
            'scopes' => 'array',
            'last_used_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /**
     * Bind route parameters on the public prefix. The autoincrement id is never in a URL,
     * and the prefix is safe to print.
     */
    public function getRouteKeyName(): string
    {
        return 'prefix';
    }

    /**
     * @return BelongsTo<Workspace, $this>
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /**
     * The credential this one replaced, if it was created by a rotation.
     *
     * @return BelongsTo<ServiceCredential, $this>
     */
    public function rotatedFrom(): BelongsTo
    {
        return $this->belongsTo(self::class, 'rotated_from_id');
    }

    /**
     * Credentials created by rotating this one. More than one is possible if an operator
     * rotated the same credential twice during an overlap window.
     *
     * @return HasMany<ServiceCredential, $this>
     */
    public function rotations(): HasMany
    {
        return $this->hasMany(self::class, 'rotated_from_id');
    }

    /**
     * The typed scopes on this credential.
     *
     * Values that are no longer Scope cases are dropped rather than throwing: a scope
     * removed from the enum in a later release must stop granting anything, not break
     * authentication for every credential that still lists it.
     *
     * @return list<Scope>
     */
    public function grantedScopes(): array
    {
        $granted = [];

        foreach ($this->scopes ?? [] as $value) {
            $scope = is_string($value) ? Scope::tryFrom($value) : null;

            if ($scope !== null) {
                $granted[] = $scope;
            }
        }

        return $granted;
    }

    public function hasScope(Scope $scope): bool
    {
        return $scope->satisfiedBy($this->grantedScopes());
    }

    /**
     * @throws MissingScope
     */
    public function requireScope(Scope $scope): void
    {
        $scope->requiresScope($this->grantedScopes());
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    public function isExpired(?Carbon $at = null): bool
    {
        return $this->expires_at !== null
            && $this->expires_at->lessThanOrEqualTo($at ?? Carbon::now());
    }

    /**
     * Whether this credential may authenticate right now. Fail closed: anything other than
     * a live, unexpired, unrevoked credential in a live workspace is unusable.
     */
    public function isUsable(?Carbon $at = null): bool
    {
        return ! $this->isRevoked() && ! $this->isExpired($at);
    }

    /**
     * One word for an operator reading `esign:credential:list`.
     */
    public function status(): string
    {
        return match (true) {
            $this->isRevoked() => 'revoked',
            $this->isExpired() => 'expired',
            default => 'active',
        };
    }

    /**
     * Constant-time comparison of a presented plaintext secret against this row's digest.
     *
     * The comparison is `hash_equals` so a timing signal cannot be used to recover the
     * digest a byte at a time. See CredentialSecret for why the digest is a salted sha256
     * rather than a password hash.
     */
    public function matches(string $presented): bool
    {
        return hash_equals(
            $this->secret_hash,
            CredentialSecret::digest($this->secret_salt, $presented),
        );
    }

    /**
     * @param  Builder<ServiceCredential>  $query
     * @return Builder<ServiceCredential>
     */
    public function scopeForPrefix(Builder $query, string $prefix): Builder
    {
        return $query->where('prefix', $prefix);
    }

    /**
     * @param  Builder<ServiceCredential>  $query
     * @return Builder<ServiceCredential>
     */
    public function scopeUsable(Builder $query, ?Carbon $at = null): Builder
    {
        $at ??= Carbon::now();

        return $query
            ->whereNull('revoked_at')
            ->where(fn (Builder $inner): Builder => $inner
                ->whereNull('expires_at')
                ->orWhere('expires_at', '>', $at));
    }
}
