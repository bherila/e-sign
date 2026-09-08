<?php

declare(strict_types=1);

/*
 * Production dependency license gate.
 *
 * Reads the two license inventories the package managers already produce and checks every
 * production dependency against an allowlist. Fails on AGPL, on GPL (non-Lesser), on an
 * unknown or missing license, and on anything simply not on the list. Optionally writes a
 * CycloneDX 1.6 SBOM built from the same two inventories.
 *
 * The MIT application license does not travel to dependencies, and one candidate PDF engine
 * family is LGPL, so distribution obligations are real. See THIRD_PARTY_NOTICES.md and
 * docs/adr/0005-lgpl-dependency-handling.md.
 *
 * Usage:
 *   composer install --no-dev && composer licenses --format=json > composer-licenses.json
 *   pnpm licenses list --json --prod > pnpm-licenses.json
 *   php scripts/check-licenses.php \
 *       --composer=composer-licenses.json \
 *       --pnpm=pnpm-licenses.json \
 *       --sbom=sbom.cdx.json
 *
 * Exit codes: 0 every dependency allowed, 1 usage or input error, 2 at least one violation.
 *
 * Deliberately dependency-free: it runs under `--no-dev`, before the autoloader is useful,
 * and must not itself widen the dependency surface it exists to police.
 */

const EXIT_OK = 0;
const EXIT_USAGE = 1;
const EXIT_VIOLATION = 2;

/**
 * SPDX identifiers allowed for any production dependency.
 *
 * Permissive or public-domain-equivalent only. Adding to this list is a licensing decision,
 * not a build fix.
 */
const ALLOWED_LICENSES = [
    '0BSD',
    'Apache-2.0',
    'BSD-2-Clause',
    'BSD-3-Clause',
    'CC0-1.0',
    'ISC',
    'MIT',
    'PostgreSQL',
    'Python-2.0',
    'Unlicense',
];

/**
 * Per-package exceptions, keyed by a package-name glob.
 *
 * The tc-lib-* family is the Stage 0 PDF engine candidate (docs/adr/0004-pdf-engine-candidate.md).
 * LGPL is accepted there, and only there, as an unmodified Composer dependency. Any other
 * package arriving under LGPL is a new licensing decision and must fail until it is made.
 */
const PACKAGE_EXCEPTIONS = [
    'tecnickcom/*' => [
        'LGPL-2.1-only',
        'LGPL-2.1-or-later',
        'LGPL-3.0-only',
        'LGPL-3.0-or-later',
    ],
];

/**
 * Deprecated or shorthand identifiers mapped onto their current SPDX form.
 */
const LICENSE_ALIASES = [
    'APACHE-2' => 'Apache-2.0',
    'APACHE2' => 'Apache-2.0',
    'BSD' => 'BSD-3-Clause',
    'CC0' => 'CC0-1.0',
    'GPL-2.0' => 'GPL-2.0-only',
    'GPL-2.0+' => 'GPL-2.0-or-later',
    'GPL-3.0' => 'GPL-3.0-only',
    'GPL-3.0+' => 'GPL-3.0-or-later',
    'LGPL-2.1' => 'LGPL-2.1-only',
    'LGPL-2.1+' => 'LGPL-2.1-or-later',
    'LGPL-3.0' => 'LGPL-3.0-only',
    'LGPL-3.0+' => 'LGPL-3.0-or-later',
    'MIT-LICENSE' => 'MIT',
    'PUBLIC-DOMAIN' => 'Unlicense',
];

exit(main($argv));

/**
 * @param  array<int, string>  $argv
 */
function main(array $argv): int
{
    $options = parseArguments($argv);

    if ($options === null) {
        fwrite(STDERR, "usage: php scripts/check-licenses.php --composer=<file> --pnpm=<file> [--sbom=<file>]\n");

        return EXIT_USAGE;
    }

    try {
        $components = array_merge(
            readComposerInventory($options['composer']),
            readPnpmInventory($options['pnpm']),
        );
    } catch (RuntimeException $e) {
        fwrite(STDERR, 'check-licenses: '.$e->getMessage()."\n");

        return EXIT_USAGE;
    }

    if ($components === []) {
        fwrite(STDERR, "check-licenses: both inventories were empty; refusing to report a pass\n");

        return EXIT_USAGE;
    }

    usort($components, static fn (array $a, array $b): int => [$a['ecosystem'], $a['name']] <=> [$b['ecosystem'], $b['name']]);

    $violations = [];
    $exceptions = [];

    foreach ($components as $component) {
        $verdict = evaluate($component['name'], $component['license']);

        if ($verdict === 'violation') {
            $violations[] = $component;
        } elseif ($verdict === 'exception') {
            $exceptions[] = $component;
        }
    }

    report($components, $exceptions, $violations);

    if ($options['sbom'] !== null) {
        writeSbom($options['sbom'], $components);
        fwrite(STDOUT, 'check-licenses: wrote CycloneDX 1.6 SBOM to '.$options['sbom']."\n");
    }

    return $violations === [] ? EXIT_OK : EXIT_VIOLATION;
}

/**
 * @param  array<int, string>  $argv
 * @return array{composer: string, pnpm: string, sbom: string|null}|null
 */
function parseArguments(array $argv): ?array
{
    $composer = null;
    $pnpm = null;
    $sbom = null;

    foreach (array_slice($argv, 1) as $argument) {
        if (str_starts_with($argument, '--composer=')) {
            $composer = substr($argument, 11);
        } elseif (str_starts_with($argument, '--pnpm=')) {
            $pnpm = substr($argument, 7);
        } elseif (str_starts_with($argument, '--sbom=')) {
            $sbom = substr($argument, 7);
        } else {
            return null;
        }
    }

    if ($composer === null || $composer === '' || $pnpm === null || $pnpm === '') {
        return null;
    }

    return ['composer' => $composer, 'pnpm' => $pnpm, 'sbom' => $sbom === '' ? null : $sbom];
}

/**
 * `composer licenses --format=json` emits {name, version, license, dependencies:{pkg:{version, license[]}}}.
 * A `license` array is disjunctive: the consumer may pick any one of the listed licenses.
 *
 * @return array<int, array{ecosystem: string, name: string, version: string, license: string}>
 */
function readComposerInventory(string $path): array
{
    $payload = readJson($path);

    if (! isset($payload['dependencies']) || ! is_array($payload['dependencies'])) {
        throw new RuntimeException($path.': no "dependencies" object; is this `composer licenses --format=json` output?');
    }

    $components = [];

    foreach ($payload['dependencies'] as $name => $details) {
        $licenses = is_array($details['license'] ?? null) ? $details['license'] : [];
        $licenses = array_values(array_filter(array_map('strval', $licenses), static fn (string $l): bool => trim($l) !== ''));

        $components[] = [
            'ecosystem' => 'composer',
            'name' => (string) $name,
            'version' => ltrim((string) ($details['version'] ?? ''), 'v'),
            'license' => $licenses === [] ? 'UNKNOWN' : implode(' OR ', $licenses),
        ];
    }

    return $components;
}

/**
 * `pnpm licenses list --json --prod` emits {"<spdx expression>": [{name, versions[], ...}, ...]}.
 *
 * @return array<int, array{ecosystem: string, name: string, version: string, license: string}>
 */
function readPnpmInventory(string $path): array
{
    $payload = readJson($path);
    $components = [];

    foreach ($payload as $license => $packages) {
        if (! is_array($packages)) {
            throw new RuntimeException($path.': expected an array of packages under "'.$license.'"');
        }

        foreach ($packages as $package) {
            $versions = is_array($package['versions'] ?? null) ? $package['versions'] : [];

            foreach ($versions === [] ? [''] : $versions as $version) {
                $components[] = [
                    'ecosystem' => 'npm',
                    'name' => (string) ($package['name'] ?? '(unnamed)'),
                    'version' => (string) $version,
                    'license' => trim((string) $license) === '' ? 'UNKNOWN' : (string) $license,
                ];
            }
        }
    }

    return $components;
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
 * @return 'allowed'|'exception'|'violation'
 */
function evaluate(string $package, string $expression): string
{
    $extra = exceptionsFor($package);

    if (! evaluateExpression($expression, array_merge(ALLOWED_LICENSES, $extra))) {
        return 'violation';
    }

    if ($extra !== [] && ! evaluateExpression($expression, ALLOWED_LICENSES)) {
        return 'exception';
    }

    return 'allowed';
}

/**
 * @return array<int, string>
 */
function exceptionsFor(string $package): array
{
    $allowed = [];

    foreach (PACKAGE_EXCEPTIONS as $glob => $licenses) {
        if (fnmatch($glob, $package, FNM_CASEFOLD)) {
            $allowed = array_merge($allowed, $licenses);
        }
    }

    return $allowed;
}

/**
 * Evaluate an SPDX license expression against an allowlist.
 *
 * OR passes when either side passes, AND only when both do. Anything unparseable, unknown, or
 * absent evaluates to false, so "we could not tell" fails exactly like "not allowed".
 *
 * @param  array<int, string>  $allowed
 */
function evaluateExpression(string $expression, array $allowed): bool
{
    $tokens = tokenizeExpression($expression);

    if ($tokens === []) {
        return false;
    }

    $position = 0;
    $result = parseOr($tokens, $position, $allowed);

    return $position === count($tokens) && $result;
}

/**
 * @return array<int, string>
 */
function tokenizeExpression(string $expression): array
{
    $spaced = str_replace(['(', ')'], [' ( ', ' ) '], $expression);
    $parts = preg_split('/\s+/', trim($spaced), -1, PREG_SPLIT_NO_EMPTY);

    return $parts === false ? [] : $parts;
}

/**
 * @param  array<int, string>  $tokens
 * @param  array<int, string>  $allowed
 */
function parseOr(array $tokens, int &$position, array $allowed): bool
{
    $result = parseAnd($tokens, $position, $allowed);

    while (isset($tokens[$position]) && strcasecmp($tokens[$position], 'OR') === 0) {
        $position++;
        $right = parseAnd($tokens, $position, $allowed);
        $result = $result || $right;
    }

    return $result;
}

/**
 * @param  array<int, string>  $tokens
 * @param  array<int, string>  $allowed
 */
function parseAnd(array $tokens, int &$position, array $allowed): bool
{
    $result = parseTerm($tokens, $position, $allowed);

    while (isset($tokens[$position]) && strcasecmp($tokens[$position], 'AND') === 0) {
        $position++;
        $right = parseTerm($tokens, $position, $allowed);
        $result = $result && $right;
    }

    return $result;
}

/**
 * @param  array<int, string>  $tokens
 * @param  array<int, string>  $allowed
 */
function parseTerm(array $tokens, int &$position, array $allowed): bool
{
    if (! isset($tokens[$position])) {
        return false;
    }

    if ($tokens[$position] === '(') {
        $position++;
        $result = parseOr($tokens, $position, $allowed);

        if (($tokens[$position] ?? null) !== ')') {
            return false;
        }

        $position++;

        return $result;
    }

    $identifier = normalizeLicense($tokens[$position]);
    $position++;

    // "<id> WITH <exception>" is a distinct license; we do not allowlist any, so consume and fail.
    if (isset($tokens[$position]) && strcasecmp($tokens[$position], 'WITH') === 0) {
        $position += isset($tokens[$position + 1]) ? 2 : 1;

        return false;
    }

    foreach ($allowed as $candidate) {
        if (strcasecmp($identifier, $candidate) === 0) {
            return true;
        }
    }

    return false;
}

function normalizeLicense(string $identifier): string
{
    $trimmed = trim($identifier);
    $key = strtoupper($trimmed);

    return LICENSE_ALIASES[$key] ?? $trimmed;
}

/**
 * @param  array<int, array{ecosystem: string, name: string, version: string, license: string}>  $components
 * @param  array<int, array{ecosystem: string, name: string, version: string, license: string}>  $exceptions
 * @param  array<int, array{ecosystem: string, name: string, version: string, license: string}>  $violations
 */
function report(array $components, array $exceptions, array $violations): void
{
    $counts = [];

    foreach ($components as $component) {
        $counts[$component['license']] = ($counts[$component['license']] ?? 0) + 1;
    }

    arsort($counts);

    fwrite(STDOUT, 'check-licenses: '.count($components)." production dependencies\n\n");

    foreach ($counts as $license => $count) {
        fwrite(STDOUT, sprintf("  %5d  %s\n", $count, $license));
    }

    if ($exceptions !== []) {
        fwrite(STDOUT, "\nallowed by a per-package exception:\n");

        foreach ($exceptions as $component) {
            fwrite(STDOUT, sprintf("  %-42s %-12s %s\n", $component['name'], $component['version'], $component['license']));
        }
    }

    if ($violations === []) {
        fwrite(STDOUT, "\ncheck-licenses: OK — every production dependency is allowed.\n");

        return;
    }

    fwrite(STDERR, "\ncheck-licenses: ".count($violations)." DISALLOWED production dependencies:\n");

    foreach ($violations as $component) {
        fwrite(STDERR, sprintf("  %-9s %-42s %-12s %s\n", $component['ecosystem'], $component['name'], $component['version'], $component['license']));
    }

    fwrite(STDERR, "\nEach line is a licensing decision, not a build failure to work around.\n");
    fwrite(STDERR, "Remove the dependency, or record the decision in THIRD_PARTY_NOTICES.md and add it here.\n");
}

/**
 * Emit a CycloneDX 1.6 JSON SBOM.
 *
 * Built from the two license inventories rather than by the CycloneDX tooling, so it carries
 * components and licenses but no dependency graph. See THIRD_PARTY_NOTICES.md for why.
 *
 * @param  array<int, array{ecosystem: string, name: string, version: string, license: string}>  $components
 */
function writeSbom(string $path, array $components): void
{
    $entries = [];

    foreach ($components as $component) {
        $purl = $component['ecosystem'] === 'composer'
            ? 'pkg:composer/'.$component['name'].'@'.$component['version']
            : 'pkg:npm/'.str_replace('%2F', '/', rawurlencode($component['name'])).'@'.$component['version'];

        $entries[] = [
            'type' => 'library',
            'bom-ref' => $purl,
            'name' => $component['name'],
            'version' => $component['version'],
            'purl' => $purl,
            'licenses' => [licenseEntry($component['license'])],
        ];
    }

    $bom = [
        'bomFormat' => 'CycloneDX',
        'specVersion' => '1.6',
        'version' => 1,
        'metadata' => [
            'timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
            'tools' => [
                'components' => [[
                    'type' => 'application',
                    'name' => 'scripts/check-licenses.php',
                    'version' => '1',
                ]],
            ],
            'component' => [
                'type' => 'application',
                'bom-ref' => 'pkg:composer/bherila/e-sign',
                'name' => 'bherila/e-sign',
                'licenses' => [['license' => ['id' => 'MIT']]],
            ],
        ],
        'components' => $entries,
    ];

    $json = json_encode($bom, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

    if (file_put_contents($path, $json."\n") === false) {
        throw new RuntimeException($path.': could not be written');
    }
}

/**
 * @return array{license?: array{id?: string, name?: string}, expression?: string}
 */
function licenseEntry(string $expression): array
{
    $tokens = tokenizeExpression($expression);

    if (count($tokens) === 1) {
        $identifier = normalizeLicense($tokens[0]);

        foreach (array_merge(ALLOWED_LICENSES, ...array_values(PACKAGE_EXCEPTIONS)) as $known) {
            if (strcasecmp($identifier, $known) === 0) {
                return ['license' => ['id' => $known]];
            }
        }

        return ['license' => ['name' => $identifier]];
    }

    return ['expression' => $expression];
}
