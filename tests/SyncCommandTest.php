<?php

namespace EngageHub\Laravel\Tests;

use EngageHub\Laravel\Tests\Support\SyncedUser;

class SyncCommandTest extends TestCase
{
    public function test_backfills_all_rows_in_one_batch(): void
    {
        SyncedUser::withoutEvents(function (): void {
            SyncedUser::create(['name' => 'A One', 'email' => 'a@example.com']);
            SyncedUser::create(['name' => 'B Two', 'email' => 'b@example.com']);
            SyncedUser::create(['name' => 'C Three', 'email' => 'c@example.com']);
        });

        $this->transport->queueJson(202, [
            'accepted' => 3, 'created' => 3, 'updated' => 0, 'rejected' => 0,
            'results' => [
                ['index' => 0, 'identity' => '1', 'status' => 'created', 'contact_id' => 1],
                ['index' => 1, 'identity' => '2', 'status' => 'created', 'contact_id' => 2],
                ['index' => 2, 'identity' => '3', 'status' => 'created', 'contact_id' => 3],
            ],
        ]);

        $this->artisan('engagehub:sync', ['--model' => [SyncedUser::class], '--rpm' => 0])
            ->expectsOutputToContain('3 created, 0 updated, 0 rejected')
            ->assertSuccessful();

        $batch = $this->transport->lastJson()['batch'];

        $this->assertCount(3, $batch);
        $this->assertSame('a@example.com', $batch[0]['email']);
        $this->assertSame('A', $batch[0]['first_name']);
    }

    public function test_after_id_resumes_and_chunk_splits_requests(): void
    {
        SyncedUser::withoutEvents(function (): void {
            foreach (range(1, 4) as $i) {
                SyncedUser::create(['email' => "u{$i}@example.com"]);
            }
        });

        $this->transport->queueJson(202, ['accepted' => 1, 'created' => 1, 'updated' => 0, 'rejected' => 0, 'results' => []]);

        $this->artisan('engagehub:sync', [
            '--model' => [SyncedUser::class],
            '--after-id' => 2,
            '--chunk' => 1,
            '--rpm' => 0,
        ])->assertSuccessful();

        $this->assertCount(2, $this->transport->requests);
        $this->assertSame('3', json_decode($this->transport->requests[0]['body'], true)['batch'][0]['external_id']);
        $this->assertSame('4', json_decode($this->transport->requests[1]['body'], true)['batch'][0]['external_id']);
    }

    public function test_dry_run_sends_nothing(): void
    {
        SyncedUser::withoutEvents(fn () => SyncedUser::create(['email' => 'a@example.com']));

        $this->artisan('engagehub:sync', ['--model' => [SyncedUser::class], '--dry-run' => true])
            ->assertSuccessful();

        $this->assertCount(0, $this->transport->requests);
    }

    public function test_rejections_fail_the_command(): void
    {
        SyncedUser::withoutEvents(fn () => SyncedUser::create(['email' => 'a@example.com']));

        $this->transport->queueJson(202, [
            'accepted' => 0, 'created' => 0, 'updated' => 0, 'rejected' => 1,
            'results' => [['index' => 0, 'identity' => '1', 'status' => 'rejected', 'errors' => [['code' => 'invalid', 'message' => 'The email field must be a valid email address.', 'field' => 'email']]]],
        ]);

        $this->artisan('engagehub:sync', ['--model' => [SyncedUser::class], '--rpm' => 0])
            ->assertFailed();
    }

    public function test_no_models_registered_fails_with_guidance(): void
    {
        $this->artisan('engagehub:sync')->assertFailed();
    }

    public function test_status_command_reports_connection(): void
    {
        $this->transport->queueJson(200, [
            'ok' => true, 'group' => ['slug' => 'ebookwriter', 'name' => 'eBookWriter'], 'connection' => ['name' => 'eBookWriter app', 'environment' => 'production'], 'key' => ['name' => 'SDK', 'prefix' => 'eh_live_abcd'], 'scopes' => ['contacts:write'],
        ]);

        $this->artisan('engagehub:status')
            ->expectsOutputToContain('Connection OK.')
            ->assertSuccessful();
    }

    public function test_status_command_fails_on_auth_error(): void
    {
        $this->transport->queueJson(401, ['message' => 'Invalid API key.']);

        $this->artisan('engagehub:status')->assertFailed();
    }
}
