<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every attempt and every piece of provider feedback, kept separately from the logical
 * message (docs/HANDOFF.md section 11: "Store delivery attempts separately from logical
 * events").
 *
 * `source` says who is claiming something: `app` for what this application did, `brevo` and
 * `ses` for what a provider reported. That distinction is the point — `app` can only ever
 * say "a transport accepted this", while only a provider can say a mailbox took it.
 *
 * `outbound_mail_id` is nullable so an orphan can be recorded. Providers report on messages
 * this deployment may never have sent (a restored backup, a rotated database, another
 * application on the same sending domain), and dropping those reports silently would make a
 * misrouted webhook indistinguishable from a quiet one. An orphan is stored with the
 * Message-ID it referenced and no parent.
 *
 * `payload` is the provider body after App\Domain\Delivery\Mail\MailErrorRedactor has run
 * over it: addresses, tokens, and query strings are removed. Raw provider bodies are not
 * retained here; the redacted copy is enough to explain a state change, and this table is
 * read by operators far more often than by an incident.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('outbound_mail_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('outbound_mail_id')->nullable()->constrained('outbound_mails')->restrictOnDelete();

            $table->string('source', 16);
            $table->string('event', 64);
            // The Message-ID the event referenced, normalized the same way
            // `outbound_mails.message_id` is. Present on an orphan, where it is the only
            // clue about what the provider was talking about.
            $table->string('message_id', 191)->nullable();
            $table->json('payload')->nullable();
            $table->timestamp('occurred_at')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['outbound_mail_id', 'occurred_at']);
            $table->index(['source', 'event']);
            $table->index('message_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outbound_mail_events');
    }
};
