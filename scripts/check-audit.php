<?php

declare(strict_types=1);

/*
 * Dependency vulnerability audit gate.
 *
 * Reads `composer audit --format=json --locked` and `pnpm audit --prod --json` output and fails
 * on any reported advisory unless it is listed, by id, in the committed allowlist
 * (.audit-allowlist.json). Every allowlist entry carries an owner-visible reason and an expiry
 * date; an entry past its expiry date fails the build even if nothing currently matches it, so a
 * suppressed advisory can never be forgotten and silently outlive its justification.
 *
 * Usage:
 *   composer audit --format=json --locked --abandoned=report > composer-audit.json
 *   pnpm audit --prod --audit-level=high --json --ignore-registry-errors > pnpm-audit.json
 *   php scripts/check-audit.php \
 *       --composer=composer-audit.json \
 *       --pnpm=pnpm-audit.json \
 *       --allowlist=.audit-allowlist.json
 *
 * Exit codes: 0 nothing outstanding, 1 usage or input error, 2 at least one unallowed advisory
 * or an expired allowlist entry.
 *
 * Deliberately dependency-free, matching scripts/check-licenses.php: it must not itself widen the
 * dependency surface it exists to police.
 */

const EXIT_OK = 0;
const EXIT_USAGE = 1;
const EXIT_VIOLATION = 2;

const KNOWN_ECOSYSTEMS = ['composer', 'npm'];

exit(main($argv));

/**
 * @param  array<int, string>  $argv
 */
function main(array $argv): int
{
    $options = parseArguments($argv);

    if ($options === null) {
        fwrite(STDERR, "usage: php scripts/check-audit.php --composer=<file> --pnpm=<file> --allowlist=<file>\n");

        return EXIT_USAGE;
    }

    try {
        $allowlist = readAllowlist($options['allowlist']);
        $advisories = array_merge(
            readComposerAdvisories($options['composer']),
            readPnpmAdvisories($options['pnpm']),
        );
    } catch (RuntimeException $e) {
        fwrite(STDERR, 'check-audit: '.$e->getMessage()."\n");

        return EXIT_USAGE;
    }

    $today = new DateTimeImmutable('today');
    $expired = [];
    $allowed = [];

    foreach ($allowlist as $entry) {
        $expires = DateTimeImmutable::createFromFormat('!Y-m-d', $entry['expires']);

        if ($expires === false || $expires < $today) {
            $expired[] = $entry;

            continue;
        }

        $allowed[$entry['ecosystem'].'|'.strtoupper($entry['id'])] = $entry;
    }

    $violations = [];
    $exceptions = [];

    foreach ($advisories as $advisory) {
        $match = null;

        foreach ($advisory['candidateIds'] as $candidate) {
            $key = $advisory['ecosystem'].'|'.strtoupper($candidate);

            if (isset($allowed[$key])) {
                $match = $allowed[$key];

                break;
            }
        }

        if ($match !== null) {
            $exceptions[] = [$advisory, $match];
        } else {
            $violations[] = $advisory;
        }
    }

    report($advisories, $exceptions, $violations, $expired);

    return ($violations === [] && $expired === []) ? EXIT_OK : EXIT_VIOLATION;
}

/**
 * @param  array<int, string>  $argv
 * @return array{composer: string, pnpm: string, allowlist: string}|null
 */
function parseArguments(array $argv): ?array
{
    $composer = null;
    $pnpm = null;
    $allowlist = null;

    foreach (array_slice($argv, 1) as $argument) {
        if (str_starts_with($argument, '--composer=')) {
            $composer = substr($argument, 11);
        } elseif (str_starts_with($argument, '--pnpm=')) {
            $pnpm = substr($argument, 7);
        } elseif (str_starts_with($argument, '--allowlist=')) {
            $allowlist = substr($argument, 12);
        } else {
            return null;
        }
    }

    if ($composer === null || $composer === '' || $pnpm === null || $pnpm === '' || $allowlist === null || $allowlist === '') {
        return null;
    }

    return ['composer' => $composer, 'pnpm' => $pnpm, 'allowlist' => $allowlist];
}

/**
 * @return array<int, array{id: string, ecosystem: string, reason: string, expires: string}>
 */
function readAllowlist(string $path): array
{
    if (! is_file($path)) {
        throw new RuntimeException($path.': not found. Commit {"entries": []} if there is currently nothing to allow.');
    }

    $payload = readJson($path);

    if (! isset($payload['entries']) || ! is_array($payload['entries'])) {
        throw new RuntimeException($path.': expected an "entries" array');
    }

    $entries = [];

    foreach ($payload['entries'] as $index => $entry) {
        if (! is_array($entry)) {
            throw new RuntimeException($path.": entry {$index} is not an object");
        }

        foreach (['id', 'ecosystem', 'reason', 'expires'] as $field) {
            if (! isset($entry[$field]) || ! is_string($entry[$field]) || trim($entry[$field]) === '') {
                throw new RuntimeException($path.": entry {$index} is missing a non-empty \"{$field}\"");
            }
        }

        if (! in_array($entry['ecosystem'], KNOWN_ECOSYSTEMS, true)) {
            throw new RuntimeException($path.": entry {$index} has ecosystem \"{$entry['ecosystem']}\"; must be one of ".implode(', ', KNOWN_ECOSYSTEMS));
        }

        if (DateTimeImmutable::createFromFormat('!Y-m-d', $entry['expires']) === false) {
            throw new RuntimeException($path.": entry {$index} has an unparseable \"expires\" date \"{$entry['expires']}\"; use YYYY-MM-DD");
        }

        $entries[] = [
            'id' => $entry['id'],
            'ecosystem' => $entry['ecosystem'],
            'reason' => $entry['reason'],
            'expires' => $entry['expires'],
        ];
    }

    return $entries;
}

/**
 * `composer audit --format=json` emits {"advisories": {"vendor/pkg": [advisory, ...]}, "abandoned": {...}}.
 * Each advisory carries `advisoryId` (a Packagist "PKSA-..." id or the upstream GHSA id) and
 * optionally `cve`; either is accepted as the allowlist key.
 *
 * @return array<int, array{ecosystem: string, package: string, id: string, title: string, severity: string, link: string, candidateIds: array<int, string>}>
 */
function readComposerAdvisories(string $path): array
{
    $payload = readJson($path);

    if (! array_key_exists('advisories', $payload)) {
        throw new RuntimeException($path.': no "advisories" key; is this `composer audit --format=json` output?');
    }

    $advisories = [];
    $raw = is_array($payload['advisories']) ? $payload['advisories'] : [];

    foreach ($raw as $package => $items) {
        if (! is_array($items)) {
            continue;
        }

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $candidateIds = array_values(array_unique(array_filter(array_map(
                static fn ($v): string => (string) $v,
                array_filter([$item['advisoryId'] ?? null, $item['cve'] ?? null], static fn ($v) => $v !== null && $v !== ''),
            ))));

            $advisories[] = [
                'ecosystem' => 'composer',
                'package' => (string) $package,
                'id' => $candidateIds[0] ?? 'unknown',
                'title' => (string) ($item['title'] ?? ''),
                'severity' => (string) ($item['severity'] ?? 'unknown'),
                'link' => (string) ($item['link'] ?? ''),
                'candidateIds' => $candidateIds,
            ];
        }
    }

    return $advisories;
}

/**
 * `pnpm audit --json` emits the npm-audit-v1 shape: {"advisories": {"<id>": advisory, ...}, "metadata": {...}}.
 * Each advisory's GitHub Security Advisory id is recovered from its `url`
 * (https://github.com/advisories/GHSA-xxxx-xxxx-xxxx) when present, since that is the id the
 * `pnpm audit --ignore <id>` flag and GitHub itself both use.
 *
 * @return array<int, array{ecosystem: string, package: string, id: string, title: string, severity: string, link: string, candidateIds: array<int, string>}>
 */
function readPnpmAdvisories(string $path): array
{
    $payload = readJson($path);

    if (! array_key_exists('advisories', $payload)) {
        throw new RuntimeException($path.': no "advisories" key; is this `pnpm audit --json` output?');
    }

    $advisories = [];
    $raw = is_array($payload['advisories']) ? $payload['advisories'] : [];

    foreach ($raw as $key => $item) {
        if (! is_array($item)) {
            continue;
        }

        $url = (string) ($item['url'] ?? '');
        $ghsa = null;

        if (preg_match('/(GHSA-[a-z0-9]+-[a-z0-9]+-[a-z0-9]+)/i', $url, $m) === 1) {
            $ghsa = strtoupper($m[1]);
        }

        $cves = is_array($item['cves'] ?? null) ? $item['cves'] : [];

        $candidateIds = array_values(array_unique(array_filter(array_map(
            static fn ($v): string => (string) $v,
            array_merge(
                $ghsa !== null ? [$ghsa] : [],
                array_filter([$item['github_advisory_id'] ?? null]),
                $cves,
                [(string) ($item['id'] ?? $key)],
            ),
        ))));

        $advisories[] = [
            'ecosystem' => 'npm',
            'package' => (string) ($item['module_name'] ?? (string) $key),
            'id' => $candidateIds[0] ?? (string) $key,
            'title' => (string) ($item['title'] ?? ''),
            'severity' => (string) ($item['severity'] ?? 'unknown'),
            'link' => $url,
            'candidateIds' => $candidateIds,
        ];
    }

    return $advisories;
}

/**
 * @return array<string, mixed>
 */
function readJson(string $path): array
{
    if (! is_file($path) || ! is_readable($path)) {
        throw new RuntimeException($path.': not a readable file');
    }

    $raw = file_get_contents($path);

    if ($raw === false || trim($raw) === '') {
        throw new RuntimeException($path.': empty');
    }

    try {
        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $e) {
        throw new RuntimeException($path.': invalid JSON — '.$e->getMessage());
    }

    if (! is_array($decoded)) {
        throw new RuntimeException($path.': expected a JSON object');
    }

    return $decoded;
}

/**
 * @param  array<int, array{ecosystem: string, package: string, id: string, title: string, severity: string, link: string, candidateIds: array<int, string>}>  $advisories
 * @param  array<int, array{0: array{ecosystem: string, package: string, id: string, title: string, severity: string, link: string, candidateIds: array<int, string>}, 1: array{id: string, ecosystem: string, reason: string, expires: string}}>  $exceptions
 * @param  array<int, array{ecosystem: string, package: string, id: string, title: string, severity: string, link: string, candidateIds: array<int, string>}>  $violations
 * @param  array<int, array{id: string, ecosystem: string, reason: string, expires: string}>  $expired
 */
function report(array $advisories, array $exceptions, array $violations, array $expired): void
{
    fwrite(STDOUT, 'check-audit: '.count($advisories)." reported advisories across composer + pnpm\n");

    if ($exceptions !== []) {
        fwrite(STDOUT, "\nallowed by the allowlist:\n");

        foreach ($exceptions as [$advisory, $entry]) {
            fwrite(STDOUT, sprintf(
                "  %-9s %-28s %-24s expires %s — %s\n",
                $advisory['ecosystem'],
                $advisory['package'],
                $advisory['id'],
                $entry['expires'],
                $entry['reason'],
            ));
        }
    }

    if ($expired !== []) {
        fwrite(STDERR, "\ncheck-audit: ".count($expired)." EXPIRED allowlist entries (renew or remove them):\n");

        foreach ($expired as $entry) {
            fwrite(STDERR, sprintf(
                "  %-9s %-24s expired %s — %s\n",
                $entry['ecosystem'],
                $entry['id'],
                $entry['expires'],
                $entry['reason'],
            ));
        }
    }

    if ($violations === []) {
        if ($expired === []) {
            fwrite(STDOUT, "\ncheck-audit: OK — no outstanding advisories.\n");
        }

        return;
    }

    fwrite(STDERR, "\ncheck-audit: ".count($violations)." UNALLOWED advisories:\n");

    foreach ($violations as $advisory) {
        fwrite(STDERR, sprintf(
            "  %-9s %-28s %-24s %-8s %s\n                 %s\n",
            $advisory['ecosystem'],
            $advisory['package'],
            $advisory['id'],
            $advisory['severity'],
            $advisory['title'],
            $advisory['link'],
        ));
    }

    fwrite(STDERR, "\nEach advisory needs a fix (update the dependency) or a reviewed, time-boxed entry in\n");
    fwrite(STDERR, ".audit-allowlist.json with an owner-visible reason and an expiry date — never a silent skip.\n");
}
