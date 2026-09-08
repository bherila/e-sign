<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Account state for login, and the end of email as an identity key.
 *
 * Two changes, both about the same rule.
 *
 * `disabled_at` is the one column `canLogin()` reads. A disabled account is refused at every
 * entry point — SSO callback, password form, and the package's own middleware — rather than
 * relying on someone remembering to delete a session. Null means active; the timestamp
 * records when access was withdrawn, which a boolean would throw away.
 *
 * The unique index on `users.email` is dropped because email is contact data, not an
 * account-linking key (AGENTS.md, docs/HANDOFF.md §5). Identity binds on the
 * `(issuer, subject)` tuple, and that tuple is unique in `identity_bindings`. Keeping email
 * unique would mean an identity provider could stop a second person signing in — or worse,
 * force the application to choose between refusing them and adopting somebody else's row —
 * merely by reporting an address that is already present. Two provider subjects that report
 * the same address are two people until the provider says otherwise, so they get two rows.
 *
 * A plain index replaces it: standalone password login still looks accounts up by address,
 * it just no longer treats the address as proof of who someone is. `esign:create-user`
 * refuses a duplicate address, and the standalone login controller refuses to guess when it
 * finds more than one candidate, so local sign-in stays unambiguous without the database
 * having to enforce something that is not true of SSO rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->timestamp('disabled_at')->nullable()->after('email_verified_at');
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->dropUnique('users_email_unique');
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->index('email');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropIndex(['email']);
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->unique('email');
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('disabled_at');
        });
    }
};
