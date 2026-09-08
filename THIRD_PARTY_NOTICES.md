# Third-party notices

The original application code in this repository is licensed under the MIT License
(see [LICENSE](LICENSE)). Dependencies retain their own licenses. "MIT application" does
not mean "every bundled dependency is MIT".

This file is the human-readable index. A machine-readable SBOM and a per-package license
inventory are a Stage 0 deliverable (see the tracking issue for the license inventory) and
must be regenerated whenever `composer.lock` or `pnpm-lock.yaml` changes.

## Dependencies with notable license terms

| Package | License | Notes |
|---|---|---|
| `tecnickcom/tc-lib-pdf` and its `tc-lib-*` companions (candidate PDF engine under Stage 0 evaluation) | LGPL-3.0-or-later | If adopted: ship notices and the library source or a link to it, keep the library replaceable (Composer dependency, no vendored modifications), and license any modifications to the library itself under the LGPL. Do not describe it as MIT. |
| `bherila/auth-laravel` | see package | Shared BWH authentication package. |
| `laravel/framework` and the Laravel ecosystem | MIT | |
| `pdfjs-dist` (planned, locally served) | Apache-2.0 | Must be served from this application, never a third-party CDN, on signing pages. |

Fonts, logos, templates, sample agreements, and documentation reproduced from third
parties do not inherit the application license. Public fixtures use synthetic agreements
and synthetic identities only.
