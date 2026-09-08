<?php

namespace Tests\Feature\Compatibility;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Guards the recorded firma-compat-v1 fixtures against drift. These assertions are the
 * shape the consumer's polling code actually depends on; the facade serializers must
 * satisfy the same assertions once they exist.
 */
class FirmaFixtureShapeTest extends TestCase
{
    private const DIR = __DIR__.'/../../Fixtures/firma/firma-compat-v1';

    public static function workflows(): array
    {
        return [
            'platform-nda' => ['platform-nda'],
            'practice-nda' => ['practice-nda'],
            'order-form' => ['order-form'],
            'data-destruction' => ['data-destruction'],
        ];
    }

    #[DataProvider('workflows')]
    public function test_request_uses_boolean_status_flags_and_timestamps(string $workflow): void
    {
        $request = $this->load($workflow, 'request');

        foreach (['sent', 'finished', 'cancelled', 'declined', 'expired'] as $flag) {
            $this->assertIsBool($request['status'][$flag], "status.$flag must be boolean");
        }
        foreach (['created_on', 'sent_on', 'finished_on', 'cancelled_on', 'declined_on', 'last_changed_on'] as $ts) {
            $this->assertArrayHasKey($ts, $request['timestamps']);
        }
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $request['id']);
    }

    #[DataProvider('workflows')]
    public function test_users_and_fields_are_wrapped_in_results(string $workflow): void
    {
        $users = $this->load($workflow, 'users');
        $fields = $this->load($workflow, 'fields');

        $this->assertArrayHasKey('results', $users);
        $this->assertArrayHasKey('results', $fields);
        $this->assertNotEmpty($users['results']);

        foreach ($users['results'] as $user) {
            $this->assertArrayHasKey('finished_on', $user);
            $this->assertArrayHasKey('order', $user);
            $this->assertArrayHasKey('email', $user);
        }
    }

    #[DataProvider('workflows')]
    public function test_fields_carry_both_legacy_and_nested_coordinates(string $workflow): void
    {
        foreach ($this->load($workflow, 'fields')['results'] as $field) {
            foreach (['x_postion', 'y_position', 'width', 'heigh', 'page_number', 'field_type', 'recipient_id', 'position'] as $key) {
                $this->assertArrayHasKey($key, $field);
            }
            $this->assertSame($field['x_postion'], $field['position']['x']);
            $this->assertSame($field['y_position'], $field['position']['y']);
            $this->assertSame($field['width'], $field['position']['width']);
            $this->assertSame($field['heigh'], $field['position']['height']);
        }
    }

    #[DataProvider('workflows')]
    public function test_download_is_json_with_a_url_and_partial_flag(string $workflow): void
    {
        $download = $this->load($workflow, 'download');

        $this->assertArrayHasKey('download_url', $download);
        $this->assertIsBool($download['is_partial']);
        $this->assertContains($download['status'], ['in_progress', 'finished', 'cancelled']);
    }

    public function test_fixtures_contain_no_real_identifiers(): void
    {
        foreach (glob(self::DIR.'/*/*.json') as $file) {
            $json = file_get_contents($file);
            $this->assertDoesNotMatchRegularExpression('/https?:\/\/(?!files\.example\.test)/', $json, basename($file));
            $this->assertDoesNotMatchRegularExpression('/@(?!example\.test)/', $json, basename($file));
        }
    }

    private function load(string $workflow, string $name): array
    {
        return json_decode(file_get_contents(self::DIR."/$workflow/$name.json"), true, 512, JSON_THROW_ON_ERROR);
    }
}
