# Third-party notices

The original application code in this repository is licensed under the MIT License
(see [LICENSE](LICENSE)). Dependencies retain their own licenses. "MIT application" does
not mean "every bundled dependency is MIT".

This file is generated from the production dependency inventory and must be regenerated
whenever `composer.lock` or `pnpm-lock.yaml` changes. The `licenses` job in
`.github/workflows/ci.yml` produces the same inventory on every run that touches a lock
file and fails the build on a license that is not allowed.

- **Generated from:** `composer licenses --format=json` after `composer install --no-dev`,
  and `pnpm licenses list --json --prod`.
- **Checked by:** `scripts/check-licenses.php` (allowlist + per-package exceptions).
- **Inventory date:** 2026-09-08.
- **Counts:** 117 Composer production packages, 70 pnpm production packages.

Development-only dependencies (test runners, linters, build tooling) are excluded: they are
not distributed in the Docker image or the cPanel bundle and carry no distribution
obligations. They are still subject to the repository's dependency policy.

## SBOM

The `licenses` CI job uploads a `license-inventory` artifact (30-day retention) containing
`composer-licenses.json`, `pnpm-licenses.json`, and `sbom.cdx.json` — a CycloneDX 1.6 JSON BOM
with one component per production package, each carrying a `purl` and its license.

The BOM is assembled by `scripts/check-licenses.php` from the two license inventories rather
than by the CycloneDX tooling. `cyclonedx/cyclonedx-php-composer` was evaluated and rejected:
it is a Composer *plugin*, so it needs an `allow-plugins` entry, it pulls six further packages
(`cyclonedx/cyclonedx-library`, `opis/json-schema`, `opis/uri`, `opis/string`,
`package-url/packageurl-php`, `composer/spdx-licenses`), and being a dev dependency it is
absent from the `--no-dev` install the job needs in order to see the production tree at all.
`@cyclonedx/cyclonedx-npm` would add an npm-side toolchain to a pnpm project.

The trade-off: the generated BOM lists components and licenses but has no `dependencies` graph,
so it answers "what is in the distribution and under what terms" and not "what depends on what".
That is the question the notices and the license gate exist to answer. If a dependency graph is
ever needed, revisit the plugin.

## License summary

| License | Composer | pnpm | Notes |
|---|---|---|---|
| MIT | 92 | 54 | |
| LGPL-3.0-or-later | 14 | 0 | The `tecnickcom/tc-lib-*` PDF engine family — see below. |
| ISC | 0 | 12 | |
| BSD-3-Clause | 5 | 1 | |
| Apache-2.0 | 4 | 2 | The three AWS packages, plus `pdfjs-dist` alongside `class-variance-authority` — see below. |
| BSD-3-Clause OR GPL-2.0-only OR GPL-3.0-only | 2 | 0 | `nette/schema`, `nette/utils`. Disjunctive: **we take BSD-3-Clause** and no GPL obligation attaches. |
| MIT AND ISC | 0 | 1 | `victory-vendor`. Conjunctive: both apply, both permissive. |

## No AGPL, and no GPL obligation

**Confirmed: no AGPL-licensed code is vendored, in either ecosystem, at any depth of the
production tree.** DocuSeal, Documenso, and OpenSign are AGPL-family products; none of them
is a dependency here, and none of their code is copied into this repository
(`docs/adr/0001-independent-mit-application.md`).

The only GPL identifiers anywhere in the production inventory are the alternatives offered by
`nette/schema` and `nette/utils`, which are triple-licensed `BSD-3-Clause OR GPL-2.0-only OR
GPL-3.0-only`. A disjunctive set is the recipient's choice; we choose BSD-3-Clause. No
copyleft obligation follows from those two packages.

`scripts/check-licenses.php` fails the build on AGPL, on GPL (non-Lesser), on a missing or
unknown license, and on anything not explicitly allowed. LGPL is accepted for
`tecnickcom/*` and nowhere else, so a future LGPL dependency in any other package is a
build failure until someone makes the licensing decision deliberately.

## LGPL handling for the PDF engine

`tecnickcom/tc-lib-pdf` and its 13 `tc-lib-*` companions are LGPL-3.0-or-later and are the
Stage 0 PDF engine candidate (`docs/adr/0004-pdf-engine-candidate.md`); the sealing half of
that evaluation has since passed, see [`docs/stage0/sealing.md`](docs/stage0/sealing.md). The position and the
obligations are stated in full in
[`docs/adr/0005-lgpl-dependency-handling.md`](docs/adr/0005-lgpl-dependency-handling.md).
In summary:

- **Unmodified Composer dependency.** The library is installed by Composer from Packagist at
  the version pinned in `composer.lock`. No `tc-lib-*` source is copied into this repository,
  patched, forked, or vendored. Any change we ever need goes upstream or into a separately
  licensed fork — never into `vendor/` and never into `app/`.
- **Source availability.** Upstream source: <https://github.com/tecnickcom/tc-lib-pdf> (and
  the sibling `tc-lib-*` repositories under the same organisation). The exact versions we
  distribute are recorded in `composer.lock`, which is committed, so any recipient can obtain
  byte-identical source for the version they received.
- **Replaceability.** The engine sits behind our own domain interfaces, and it is a plain
  Composer requirement. A recipient can replace it with a modified version by editing
  `composer.json`/`composer.lock` and re-running `composer install`, without rebuilding
  anything of ours. The Docker image and the cPanel bundle both keep `vendor/` as an ordinary
  directory tree — no bytecode bundling, no phar packing, no stripping — so relinking is a
  file replacement.
- **Notices ship with the artifacts.** The production Docker image copies the repository root
  into the image (`.docker/Dockerfile`, `production` stage), so this file and `LICENSE` are
  present at `/var/www/html`. The cPanel bundle names them explicitly in the rsync payload
  (`.github/workflows/deploy.yml`). Both artifacts also carry `vendor/tecnickcom/*` with the
  upstream `LICENSE` file in each package directory.
- **Honest labelling.** The library is LGPL. Do not describe it, or the application as a
  whole, as MIT-licensed.

## Serving PDF.js locally

`pdfjs-dist` (Apache-2.0) renders the document in the visual field editor and, later, on the
signing pages. AGENTS.md forbids a third-party CDN on either surface, and PDF.js falls back to
its published CDN for several resources unless it is told otherwise, so "served locally" is a
set of explicit settings rather than a default:

| Part | How it reaches the browser from this origin |
|---|---|
| The library | A normal ES module import, bundled by Vite into the editor's lazy chunk. |
| The worker | `import workerUrl from 'pdfjs-dist/build/pdf.worker.min.mjs?url'`, emitted into `public/build` as an asset and assigned to `GlobalWorkerOptions.workerSrc`. |
| `cmaps/`, `standard_fonts/`, `wasm/`, `iccs/` | Fetched by path at runtime, one file at a time, so no import exists for a bundler to follow. The plugin in `vite.config.ts` copies them to `public/vendor/pdfjs/` on every dev start and every build, and `pdfjs.ts` points `cMapUrl`, `standardFontDataUrl`, `wasmUrl` and `iccUrl` at that path. |

The copies are generated, not committed (`/public/vendor` is in `.gitignore`), and
`node_modules/pdfjs-dist/LICENSE` is copied alongside them so the licence travels with the
distributed files. `wasm/` and `iccs/` carry their own upstream `LICENSE_*` files for JBIG2,
OpenJPEG and QCMS, and `standard_fonts/` carries `LICENSE_FOXIT` and `LICENSE_LIBERATION` for
the substituted fonts; all are copied verbatim.

`pdfjs-dist` declares one optional dependency, `@napi-rs/canvas`, which lets PDF.js rasterise a
page in Node. It is listed in `ignoredOptionalDependencies` in `pnpm-workspace.yaml` and is
therefore **not** installed or distributed: rendering happens in the browser, the production
runtime is PHP-only, and the image ships `public/build` rather than `node_modules`. Installing
it would add a platform-specific native binary, and its per-architecture siblings to the lock
file, for a code path nothing here reaches.

## Other notable terms

| Package | License | Notes |
|---|---|---|
| `bherila/auth-laravel` | MIT | Shared BWH authentication package (`docs/HANDOFF.md` §2). |
| `laravel/framework` and the Laravel ecosystem | MIT | |
| `aws/aws-sdk-php`, `aws/aws-crt-php` | Apache-2.0 | S3-compatible blob storage client. Apache-2.0 requires the NOTICE file to travel with the distribution; it ships inside `vendor/aws/`. |
| `aws/aws-php-sns-message-validator` | Apache-2.0 | SNS message signature verification for the SES mail-feedback endpoint (issue #35). Three files, no transitive dependency of its own beyond `ext-openssl` and `psr/http-message`, both already present. AWS publishes the canonical string-to-sign per SNS message type; `AGENTS.md` forbids re-deriving that locally when a maintained library exists. Same NOTICE obligation, satisfied the same way. |
| `nette/schema`, `nette/utils` | BSD-3-Clause OR GPL-2.0-only OR GPL-3.0-only | We take BSD-3-Clause. |
| `victory-vendor` | MIT AND ISC | Transitive dependency of `recharts`. |
| `pdfjs-dist` | Apache-2.0 | The PDF renderer for the preparation and signing pages (issue #22). Served entirely from this origin — see [Serving PDF.js locally](#serving-pdfjs-locally). Apache-2.0 requires its `LICENSE` and any `NOTICE` to travel with the distribution; they ship inside the copied `public/vendor/pdfjs/` directories and in `node_modules/pdfjs-dist/LICENSE` in the source distribution. |

Fonts used in signature rendering are not yet chosen. When they are, each font's license and
attribution must be added to this file before the font is committed or bundled; a font does
not inherit the application license.

Fonts, logos, templates, sample agreements, and documentation reproduced from third parties do
not inherit the application license. Public fixtures use synthetic agreements and synthetic
identities only.

The pinned Firma OpenAPI document is deliberately **not** vendored, because no redistribution
license is published for it. See `tests/Fixtures/firma/SCHEMA.md`.

## Composer production dependencies (117)

| Package | Version | License |
|---|---|---|
| `aws/aws-crt-php` | 1.2.7 | Apache-2.0 |
| `aws/aws-php-sns-message-validator` | 1.10.2 | Apache-2.0 |
| `aws/aws-sdk-php` | 3.394.9 | Apache-2.0 |
| `bherila/auth-laravel` | 0.12.2 | MIT |
| `brick/math` | 0.18.0 | MIT |
| `carbonphp/carbon-doctrine-types` | 3.2.1 | MIT |
| `dflydev/dot-access-data` | 3.0.3 | MIT |
| `doctrine/deprecations` | 1.1.6 | MIT |
| `doctrine/inflector` | 2.1.0 | MIT |
| `doctrine/lexer` | 3.0.1 | MIT |
| `dragonmantank/cron-expression` | 3.6.0 | MIT |
| `egulias/email-validator` | 4.0.4 | MIT |
| `fruitcake/php-cors` | 1.4.0 | MIT |
| `graham-campbell/result-type` | 1.2.0 | MIT |
| `guzzlehttp/guzzle` | 8.2.0 | MIT |
| `guzzlehttp/promises` | 3.0.2 | MIT |
| `guzzlehttp/psr7` | 3.1.0 | MIT |
| `guzzlehttp/uri-template` | 2.0.1 | MIT |
| `laravel/framework` | 13.30.1 | MIT |
| `laravel/prompts` | 0.3.24 | MIT |
| `laravel/serializable-closure` | 2.0.16 | MIT |
| `laravel/tinker` | 3.0.2 | MIT |
| `league/commonmark` | 2.10.1 | BSD-3-Clause |
| `league/config` | 1.2.0 | BSD-3-Clause |
| `league/flysystem` | 3.36.0 | MIT |
| `league/flysystem-aws-s3-v3` | 3.35.3 | MIT |
| `league/flysystem-local` | 3.35.3 | MIT |
| `league/mime-type-detection` | 1.17.0 | MIT |
| `league/uri` | 7.8.1 | MIT |
| `league/uri-interfaces` | 7.8.1 | MIT |
| `monolog/monolog` | 3.11.0 | MIT |
| `mtdowling/jmespath.php` | 2.9.2 | MIT |
| `nesbot/carbon` | 3.13.2 | MIT |
| `nette/schema` | 1.3.6 | BSD-3-Clause OR GPL-2.0-only OR GPL-3.0-only |
| `nette/utils` | 4.1.5 | BSD-3-Clause OR GPL-2.0-only OR GPL-3.0-only |
| `nikic/php-parser` | 5.8.0 | BSD-3-Clause |
| `nunomaduro/termwind` | 2.4.0 | MIT |
| `paragonie/constant_time_encoding` | 3.1.3 | MIT |
| `phpdocumentor/reflection-common` | 2.2.0 | MIT |
| `phpdocumentor/reflection-docblock` | 6.0.3 | MIT |
| `phpdocumentor/type-resolver` | 2.0.0 | MIT |
| `phpoption/phpoption` | 1.10.0 | Apache-2.0 |
| `phpstan/phpdoc-parser` | 2.3.5 | MIT |
| `psr/clock` | 1.0.0 | MIT |
| `psr/container` | 2.0.2 | MIT |
| `psr/event-dispatcher` | 1.0.0 | MIT |
| `psr/http-client` | 1.0.3 | MIT |
| `psr/http-factory` | 1.1.0 | MIT |
| `psr/http-message` | 2.0 | MIT |
| `psr/log` | 3.0.2 | MIT |
| `psr/simple-cache` | 3.0.0 | MIT |
| `psy/psysh` | 0.12.24 | MIT |
| `ramsey/collection` | 2.1.1 | MIT |
| `ramsey/uuid` | 4.9.3 | MIT |
| `spatie/laravel-csp` | 3.28.3 | MIT |
| `spatie/laravel-package-tools` | 1.93.2 | MIT |
| `spomky-labs/cbor-php` | 3.3.4 | MIT |
| `spomky-labs/pki-framework` | 1.6.1 | MIT |
| `symfony/brevo-mailer` | 8.0.13 | MIT |
| `symfony/clock` | 8.0.8 | MIT |
| `symfony/console` | 8.0.15 | MIT |
| `symfony/css-selector` | 8.0.9 | MIT |
| `symfony/deprecation-contracts` | 3.7.1 | MIT |
| `symfony/error-handler` | 8.0.15 | MIT |
| `symfony/event-dispatcher` | 8.0.15 | MIT |
| `symfony/event-dispatcher-contracts` | 3.7.1 | MIT |
| `symfony/filesystem` | 8.0.15 | MIT |
| `symfony/finder` | 8.0.14 | MIT |
| `symfony/http-client` | 8.0.16 | MIT |
| `symfony/http-client-contracts` | 3.7.3 | MIT |
| `symfony/http-foundation` | 8.0.15 | MIT |
| `symfony/http-kernel` | 8.0.15 | MIT |
| `symfony/mailer` | 8.0.15 | MIT |
| `symfony/mime` | 8.0.15 | MIT |
| `symfony/polyfill-ctype` | 1.37.0 | MIT |
| `symfony/polyfill-intl-grapheme` | 1.41.0 | MIT |
| `symfony/polyfill-intl-idn` | 1.42.0 | MIT |
| `symfony/polyfill-intl-normalizer` | 1.42.0 | MIT |
| `symfony/polyfill-mbstring` | 1.38.2 | MIT |
| `symfony/polyfill-php80` | 1.37.0 | MIT |
| `symfony/polyfill-php82` | 1.38.1 | MIT |
| `symfony/polyfill-php84` | 1.38.1 | MIT |
| `symfony/polyfill-php85` | 1.41.0 | MIT |
| `symfony/polyfill-php86` | 1.41.0 | MIT |
| `symfony/polyfill-uuid` | 1.37.0 | MIT |
| `symfony/process` | 8.0.13 | MIT |
| `symfony/property-access` | 8.0.8 | MIT |
| `symfony/property-info` | 8.0.15 | MIT |
| `symfony/routing` | 8.0.15 | MIT |
| `symfony/serializer` | 8.0.15 | MIT |
| `symfony/service-contracts` | 3.7.3 | MIT |
| `symfony/string` | 8.0.15 | MIT |
| `symfony/translation` | 8.0.14 | MIT |
| `symfony/translation-contracts` | 3.7.1 | MIT |
| `symfony/type-info` | 8.0.9 | MIT |
| `symfony/uid` | 8.0.9 | MIT |
| `symfony/var-dumper` | 8.0.15 | MIT |
| `tecnickcom/tc-lib-barcode` | 2.16.2 | LGPL-3.0-or-later |
| `tecnickcom/tc-lib-color` | 3.0.5 | LGPL-3.0-or-later |
| `tecnickcom/tc-lib-file` | 3.9.2 | LGPL-3.0-or-later |
| `tecnickcom/tc-lib-pdf` | 8.73.6 | LGPL-3.0-or-later |
| `tecnickcom/tc-lib-pdf-encrypt` | 2.11.2 | LGPL-3.0-or-later |
| `tecnickcom/tc-lib-pdf-filter` | 2.11.2 | LGPL-3.0-or-later |
| `tecnickcom/tc-lib-pdf-font` | 4.3.3 | LGPL-3.0-or-later |
| `tecnickcom/tc-lib-pdf-graph` | 2.17.2 | LGPL-3.0-or-later |
| `tecnickcom/tc-lib-pdf-image` | 3.14.2 | LGPL-3.0-or-later |
| `tecnickcom/tc-lib-pdf-page` | 4.16.3 | LGPL-3.0-or-later |
| `tecnickcom/tc-lib-pdf-parser` | 3.15.2 | LGPL-3.0-or-later |
| `tecnickcom/tc-lib-pdf-sign` | 2.0.3 | LGPL-3.0-or-later |
| `tecnickcom/tc-lib-unicode` | 3.0.7 | LGPL-3.0-or-later |
| `tecnickcom/tc-lib-unicode-data` | 3.0.7 | LGPL-3.0-or-later |
| `tijsverkoyen/css-to-inline-styles` | 2.4.0 | BSD-3-Clause |
| `vlucas/phpdotenv` | 5.7.0 | BSD-3-Clause |
| `voku/portable-ascii` | 2.1.1 | MIT |
| `web-auth/cose-lib` | 4.7.1 | MIT |
| `web-auth/webauthn-lib` | 5.3.8 | MIT |
| `webmozart/assert` | 2.4.1 | MIT |

## pnpm production dependencies (70)

| Package | Version | License |
|---|---|---|
| `@babel/runtime` | 7.29.2 | MIT |
| `@base-ui/react` | 1.4.1 | MIT |
| `@base-ui/utils` | 0.2.8 | MIT |
| `@date-fns/tz` | 1.4.1 | MIT |
| `@floating-ui/core` | 1.7.5 | MIT |
| `@floating-ui/dom` | 1.7.6 | MIT |
| `@floating-ui/react-dom` | 2.1.8 | MIT |
| `@floating-ui/utils` | 0.2.11 | MIT |
| `@hookform/resolvers` | 5.2.2 | MIT |
| `@reduxjs/toolkit` | 2.11.2 | MIT |
| `@standard-schema/spec` | 1.1.0 | MIT |
| `@standard-schema/utils` | 0.3.0 | MIT |
| `@tabby_ai/hijri-converter` | 1.0.5 | MIT |
| `@types/d3-array` | 3.2.2 | MIT |
| `@types/d3-color` | 3.1.3 | MIT |
| `@types/d3-ease` | 3.0.2 | MIT |
| `@types/d3-interpolate` | 3.0.4 | MIT |
| `@types/d3-path` | 3.1.1 | MIT |
| `@types/d3-scale` | 4.0.9 | MIT |
| `@types/d3-shape` | 3.1.8 | MIT |
| `@types/d3-time` | 3.0.4 | MIT |
| `@types/d3-timer` | 3.0.2 | MIT |
| `@types/react` | 19.2.14 | MIT |
| `@types/use-sync-external-store` | 0.0.6 | MIT |
| `class-variance-authority` | 0.7.1 | Apache-2.0 |
| `classnames` | 2.5.1 | MIT |
| `clsx` | 2.1.1 | MIT |
| `csstype` | 3.2.3 | MIT |
| `currency.js` | 2.0.4 | MIT |
| `d3-array` | 3.2.4 | ISC |
| `d3-color` | 3.1.0 | ISC |
| `d3-ease` | 3.0.1 | BSD-3-Clause |
| `d3-format` | 3.1.2 | ISC |
| `d3-interpolate` | 3.0.1 | ISC |
| `d3-path` | 3.1.0 | ISC |
| `d3-scale` | 4.0.2 | ISC |
| `d3-shape` | 3.2.0 | ISC |
| `d3-time` | 3.1.0 | ISC |
| `d3-time-format` | 4.1.0 | ISC |
| `d3-timer` | 3.0.1 | ISC |
| `date-fns` | 4.1.0 | MIT |
| `date-fns-jalali` | 4.1.0-0 | MIT |
| `decimal.js-light` | 2.5.1 | MIT |
| `es-toolkit` | 1.46.1 | MIT |
| `eventemitter3` | 5.0.4 | MIT |
| `immer` | 10.2.0 | MIT |
| `immer` | 11.1.8 | MIT |
| `internmap` | 2.0.3 | ISC |
| `js-tokens` | 4.0.0 | MIT |
| `lodash` | 4.18.1 | MIT |
| `loose-envify` | 1.4.0 | MIT |
| `lucide-react` | 0.577.0 | ISC |
| `object-assign` | 4.1.1 | MIT |
| `pdfjs-dist` | 6.3.289 | Apache-2.0 |
| `prop-types` | 15.8.1 | MIT |
| `react` | 19.2.6 | MIT |
| `react-day-picker` | 9.14.0 | MIT |
| `react-dom` | 19.2.6 | MIT |
| `react-hook-form` | 7.75.0 | MIT |
| `react-is` | 16.13.1 | MIT |
| `react-is` | 19.2.6 | MIT |
| `react-redux` | 9.2.0 | MIT |
| `recharts` | 3.8.1 | MIT |
| `redux` | 5.0.1 | MIT |
| `redux-thunk` | 3.1.0 | MIT |
| `reselect` | 5.1.1 | MIT |
| `scheduler` | 0.27.0 | MIT |
| `sonner` | 2.0.7 | MIT |
| `tailwind-merge` | 3.6.0 | MIT |
| `tiny-invariant` | 1.3.3 | MIT |
| `use-sync-external-store` | 1.6.0 | MIT |
| `victory-vendor` | 37.3.6 | MIT AND ISC |
