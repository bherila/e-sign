<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two counters for a draft's document set (issue #182), as upstream reports them.
 *
 * `documents_version` advances on every change to the set, a rename included;
 * `documents_layout_version` only when pages move, so a caller that placed fields by absolute
 * page can tell whether its numbers are still right. Both start at 1, which is what every
 * existing envelope is: a set that has never been changed.
 *
 * Expand-only: defaulted columns the previous release never reads (AGENTS.md, Database safety
 * rule 6).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('envelopes', function (Blueprint $table): void {
            $table->unsignedInteger('documents_version')->default(1);
            $table->unsignedInteger('documents_layout_version')->default(1);
        });
    }

    public function down(): void
    {
        Schema::table('envelopes', function (Blueprint $table): void {
            $table->dropColumn(['documents_version', 'documents_layout_version']);
        });
    }
};
