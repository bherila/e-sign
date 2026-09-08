<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use App\Domain\Identity\Audit\AuditActor;
use App\Domain\Identity\Audit\AuditEvent;
use App\Domain\Identity\Audit\AuditRecorder;
use App\Domain\Identity\Models\Workspace;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class AuditEventTest extends TestCase
{
    use RefreshDatabase;

    public function test_recording_an_event_stores_the_actor_action_subject_and_payload(): void
    {
        $workspace = Workspace::factory()->create();
        $user = User::factory()->create(['name' => 'Dana Operator']);

        $event = app(AuditRecorder::class)->record(
            AuditActor::user($user),
            'identity.owner_bootstrapped',
            $workspace,
            ['mode' => 'sso'],
        );

        $this->assertSame('user', $event->actor_type);
        $this->assertSame((string) $user->getKey(), $event->actor_id);
        $this->assertSame('Dana Operator', $event->actor_label);
        $this->assertSame('identity.owner_bootstrapped', $event->action);
        $this->assertSame(Workspace::class, $event->subject_type);
        $this->assertSame((string) $workspace->getKey(), $event->subject_id);
        $this->assertSame(['mode' => 'sso'], $event->payload);
        $this->assertNotNull($event->created_at);
    }

    public function test_a_console_actor_records_the_command_rather_than_a_pretend_user(): void
    {
        $event = app(AuditRecorder::class)->record(AuditActor::console('esign:bootstrap-owner'), 'identity.probe');

        $this->assertSame('cli', $event->actor_type);
        $this->assertNull($event->actor_id);
        $this->assertSame('esign:bootstrap-owner', $event->actor_label);
        $this->assertNull($event->subject_type);
        $this->assertNull($event->payload);
    }

    public function test_the_table_has_no_updated_at_because_it_is_append_only(): void
    {
        $event = app(AuditRecorder::class)->record(AuditActor::system('worker'), 'identity.probe');

        $this->assertNull($event::UPDATED_AT);
        $this->assertArrayNotHasKey('updated_at', $event->getAttributes());
    }

    public function test_an_audit_event_cannot_be_updated(): void
    {
        $event = app(AuditRecorder::class)->record(AuditActor::system('worker'), 'identity.probe');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('append-only');

        $event->action = 'identity.rewritten';
        $event->save();
    }

    public function test_an_audit_event_cannot_be_deleted(): void
    {
        $event = app(AuditRecorder::class)->record(AuditActor::system('worker'), 'identity.probe');

        try {
            $event->delete();
            $this->fail('Deleting an audit event must be refused.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('append-only', $exception->getMessage());
        }

        $this->assertDatabaseCount('esign_audit_events', 1);
        $this->assertSame(1, AuditEvent::count());
    }
}
