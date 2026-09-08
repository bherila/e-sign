<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Foreign names for a native template.
 *
 * docs/HANDOFF.md section 6 requires native ids and imported-provider aliases to be
 * *separate fields*, and section 2 records why: the consumer hardcodes Firma template ids
 * and patches prefills by name, so a migration that silently reassigned those ids would
 * either drop overrides or send the wrong agreement. An alias is therefore a row that maps
 * somebody else's identifier onto a template of ours; it never becomes the template's own
 * id, and `templates.public_id` is never derived from one.
 *
 * `source` says whose namespace the alias belongs to
 * (App\Domain\Preparation\Templates\TemplateAliasSource). A string rather than a native
 * ENUM, so the column reads identically on SQLite, MySQL 8, and MariaDB
 * (docs/adr/0002-supported-databases.md).
 *
 * ## Why workspace_id is here as well as on the template
 *
 * The uniqueness the facade needs is "one alias resolves to one template *within a
 * workspace*": two tenants that both migrated from the same provider will legitimately
 * present the same provider template id, and a globally unique alias would make the second
 * tenant's import fail. Enforcing that as a database constraint needs the workspace on this
 * row, because a unique index cannot reach through `template_id`. The column is written from
 * `templates.workspace_id` and never independently, and
 * App\Domain\Preparation\Templates\TemplateService is the only writer.
 *
 * There is a `created_at` and no `updated_at`: an alias is added or removed, never edited,
 * so a rename cannot quietly repoint a provider id at a different agreement.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('template_aliases', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('template_id')->constrained('templates')->restrictOnDelete();
            $table->foreignId('workspace_id')->constrained('workspaces')->restrictOnDelete();
            $table->string('alias', 191);
            $table->string('source', 32);
            $table->timestamp('created_at')->nullable();

            $table->unique(['workspace_id', 'alias']);
            $table->index(['template_id', 'source']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('template_aliases');
    }
};
