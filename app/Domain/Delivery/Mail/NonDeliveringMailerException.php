<?php

declare(strict_types=1);

namespace App\Domain\Delivery\Mail;

use RuntimeException;

/**
 * Thrown when a production deployment asks the outbox to send through a transport that
 * cannot deliver.
 *
 * This is a refusal, not a warning. The failure mode it prevents is the quiet one: with
 * `MAIL_MAILER=log` in production every invitation is written to a file, every row reaches
 * `sent_to_provider`, the dashboard is green, and nobody hears about it until a
 * counterparty asks why they never got the agreement. Refusing to enqueue turns that into
 * an error at the moment the first message is created.
 */
class NonDeliveringMailerException extends RuntimeException {}
