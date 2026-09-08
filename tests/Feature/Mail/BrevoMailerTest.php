<?php

namespace Tests\Feature\Mail;

use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Symfony\Component\Mailer\Bridge\Brevo\Transport\BrevoApiTransport;
use Symfony\Component\Mailer\Transport\FailoverTransport;
use Tests\TestCase;

class BrevoMailerTest extends TestCase
{
    public function test_brevo_mailer_builds_the_symfony_api_transport_from_the_dsn(): void
    {
        config()->set('services.brevo.dsn', 'brevo+api://test-key@default');

        $transport = Mail::mailer('brevo')->getSymfonyTransport();

        $this->assertInstanceOf(BrevoApiTransport::class, $transport);
    }

    public function test_hybrid_mailer_is_a_failover_over_brevo_then_smtp(): void
    {
        config()->set('services.brevo.dsn', 'brevo+api://test-key@default');

        $transport = Mail::mailer('hybrid')->getSymfonyTransport();

        $this->assertInstanceOf(FailoverTransport::class, $transport);
        $this->assertSame(['brevo', 'smtp'], config('mail.mailers.hybrid.mailers'));
    }

    public function test_brevo_mailer_refuses_to_build_without_a_dsn(): void
    {
        config()->set('services.brevo.dsn', null);

        $this->expectException(RuntimeException::class);

        Mail::mailer('brevo')->getSymfonyTransport();
    }
}
