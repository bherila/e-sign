<?php

declare(strict_types=1);

namespace App\Domain\Evidence\Retention;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Config\Repository;

/**
 * The three cleanup windows, read once and answered in cutoff dates rather than in day
 * counts.
 *
 * They are three values and not one because they protect different things
 * (docs/HANDOFF.md section 12). Authentication rows are operational history; an abandoned
 * draft is an upload nobody ever sent; an executed agreement is the instrument itself. A
 * single knob would force one of the three to be wrong.
 *
 * `executedDocumentsCutoff()` returns null when the policy is unset, and every caller must
 * treat null as "do not touch executed evidence" rather than as "delete everything" or as a
 * zero-day window. That asymmetry is the whole point: the two operational policies have
 * defaults because a wrong answer there costs disk, and the evidence policy does not
 * because a wrong answer there destroys the agreement.
 */
final readonly class RetentionPolicy
{
    private function __construct(
        public int $authLogsDays,
        public int $abandonedDraftsDays,
        public ?int $executedDocumentsDays,
        public int $purgeGraceDays,
    ) {}

    public static function fromConfig(Repository $config): self
    {
        /** @var array<string, mixed> $retention */
        $retention = $config->get('esign.retention', []);

        return self::fromArray($retention);
    }

    /**
     * @param  array<string, mixed>  $retention
     */
    public static function fromArray(array $retention): self
    {
        return new self(
            authLogsDays: self::positiveDays($retention['auth_logs_days'] ?? null) ?? 400,
            abandonedDraftsDays: self::positiveDays($retention['abandoned_drafts_days'] ?? null) ?? 90,
            executedDocumentsDays: self::positiveDays($retention['executed_documents_days'] ?? null),
            purgeGraceDays: self::positiveDays($retention['purge_grace_days'] ?? null) ?? 30,
        );
    }

    public function authLogsCutoff(CarbonImmutable $now): CarbonImmutable
    {
        return $now->subDays($this->authLogsDays);
    }

    public function abandonedDraftsCutoff(CarbonImmutable $now): CarbonImmutable
    {
        return $now->subDays($this->abandonedDraftsDays);
    }

    /** Null when no operator has configured a reviewed policy. Never a date in that case. */
    public function executedDocumentsCutoff(CarbonImmutable $now): ?CarbonImmutable
    {
        return $this->executedDocumentsDays === null
            ? null
            : $now->subDays($this->executedDocumentsDays);
    }

    public function purgeGraceCutoff(CarbonImmutable $now): CarbonImmutable
    {
        return $now->subDays($this->purgeGraceDays);
    }

    public function deletesExecutedDocuments(): bool
    {
        return $this->executedDocumentsDays !== null;
    }

    /**
     * A day count, or null for anything that is not a usable one.
     *
     * `null`, `''`, `'0'`, `0`, and negatives all collapse to null on purpose. The value
     * that matters is `executed_documents_days`, where a half-finished `.env` edit
     * (`ESIGN_RETENTION_EXECUTED_DOCUMENTS_DAYS=`) must not read as "delete everything
     * executed today". Making it null for all four keeps one rule instead of two.
     */
    private static function positiveDays(mixed $value): ?int
    {
        if ($value === null || $value === '' || is_bool($value)) {
            return null;
        }

        if (! is_numeric($value)) {
            return null;
        }

        $days = (int) $value;

        return $days > 0 ? $days : null;
    }
}
