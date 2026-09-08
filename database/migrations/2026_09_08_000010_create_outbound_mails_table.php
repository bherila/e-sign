<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per transactional message the application means to send.
 *
 * The row is written before anything is handed to a transport, which is what makes the
 * outbox honest: `state` starts at `queued` and only moves forward when something
 * actually happened. `sent_to_provider` means a transport accepted the bytes; `delivered`
 * can only be written by provider feedback (see `outbound_mail_events`). Nothing writes
 * `delivered` on its own, and a `log` mailer can never produce it. See
 * docs/delivery/mail.md.
 *
 * `workspace_id` is nullable because operator mail (`admin_failure`) belongs to the
 * deployment rather than a tenant, and RESTRICT for the reason every other reference to
 * `workspaces.id` is: the record of what was sent outlives the workspace that caused it.
 *
 * `context` is the Mailable's view data and nothing else — names, a title, one absolute
 * URL, an expiry. It is never a place for a credential, a token on its own, an API key, or
 * document bytes. `last_error` is passed through
 * App\Domain\Delivery\Mail\MailErrorRedactor before it is stored, so a transport exception
 * that quotes the envelope recipient or a URL does not leave an address or a token in the
 * database.
 *
 * `state` and `kind` are plain strings rather than native ENUMs so the column reads
 * identically on SQLite, MySQL 8, and MariaDB (docs/adr/0002-supported-databases.md); the
 * application enums App\Domain\Delivery\Mail\MailState and MailKind are the authority.
 *
 * `related_type`/`related_id` are a nullable morph for whatever caused the message. Stage 4
 * builds envelopes in parallel with this table, so they are deliberately unconstrained: the
 * outbox does not need to know what an envelope is to mail about one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('outbound_mails', function (Blueprint $table): void {
            $table->id();
            // Public identifier used in URLs, CLI arguments, and the queue job's unique
            // lock, so the autoincrement id is never enumerable from outside.
            $table->ulid('public_id')->unique();
            $table->foreignId('workspace_id')->nullable()->constrained('workspaces')->restrictOnDelete();

            $table->string('kind', 32);
            $table->string('to_email', 191);
            $table->string('to_name')->nullable();
            $table->string('subject');
            $table->json('context');

            // The provider's Message-ID, normalized (angle brackets stripped, lowercased),
            // learned from the Symfony SentMessage once a transport has accepted the
            // message. It is how provider feedback finds this row.
            $table->string('message_id', 191)->nullable();

            $table->string('state', 24);
            // `useCurrent()` is not about wanting a default — the application always writes
            // this column. It is there because this is the first NOT NULL TIMESTAMP in the
            // table, and MySQL/MariaDB implicitly give such a column both
            // DEFAULT CURRENT_TIMESTAMP *and* ON UPDATE CURRENT_TIMESTAMP when
            // `explicit_defaults_for_timestamp` is off. That would silently bump this
            // timestamp on every unrelated UPDATE (an attempt counter, a last_error), and a
            // state whose timestamp does not match it cannot answer "how long has this been
            // stuck" — the one question the backlog probe asks. Declaring a DEFAULT
            // suppresses the implicit ON UPDATE on both engines.
            $table->timestamp('state_changed_at')->useCurrent();
            $table->unsignedInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            // A mailer *name* from config/mail.php, never a host, DSN, or credential.
            $table->string('mailer', 32)->nullable();

            $table->string('related_type', 191)->nullable();
            $table->string('related_id', 191)->nullable();

            // Set on a message created by `esign:mail:resend`, pointing at the row it was
            // copied from. RESTRICT: the original is the reason the copy exists.
            $table->foreignId('resent_from_id')->nullable()->constrained('outbound_mails')->restrictOnDelete();

            $table->timestamps();

            // The backlog probe reads (state, created_at); feedback reads message_id.
            $table->index(['state', 'created_at']);
            $table->index('message_id');
            $table->index(['related_type', 'related_id']);
            $table->index(['workspace_id', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outbound_mails');
    }
};
