<?php

namespace EngageHub\Laravel\Tests;

use EngageHub\Laravel\Facades\EngageHub;

class ManagerInlineTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('engagehub.dispatch', 'sync');
    }

    public function test_identify_hits_contacts_sync_inline(): void
    {
        $this->transport->queueJson(202, [
            'accepted' => 1, 'created' => 1, 'updated' => 0, 'rejected' => 0,
            'results' => [['index' => 0, 'identity' => 'u1', 'status' => 'created', 'contact_id' => 5]],
        ]);

        EngageHub::identify(['external_id' => 'u1', 'email' => 'jane@example.com']);

        $this->assertCount(1, $this->transport->requests);
        $this->assertSame('https://engagehub.test/api/v1/contacts/sync', $this->transport->requests[0]['url']);
        $this->assertSame('u1', $this->transport->lastJson()['external_id']);
    }

    public function test_track_hits_events_with_auto_event_id(): void
    {
        $this->transport->queueJson(202, ['accepted' => 1, 'duplicates' => 0, 'rejected' => 0]);

        EngageHub::track('signup_completed', ['external_id' => 'u1'], ['plan' => 'pro']);

        $json = $this->transport->lastJson();

        $this->assertSame('https://engagehub.test/api/v1/events', $this->transport->requests[0]['url']);
        $this->assertSame('signup_completed', $json['name']);
        $this->assertSame('u1', $json['external_id']);
        $this->assertSame(['plan' => 'pro'], $json['properties']);
        $this->assertNotEmpty($json['event_id']);
    }

    public function test_track_with_a_model_sends_external_id_and_email_only(): void
    {
        $this->transport->queueJson(202, ['accepted' => 1, 'duplicates' => 0, 'rejected' => 0]);
        config(['engagehub.enabled' => false]);
        $user = \EngageHub\Laravel\Tests\Support\SyncedUser::create(['name' => 'Jane Doe', 'email' => 'jane@example.com']);
        config(['engagehub.enabled' => true]);

        EngageHub::track('book_published', $user, ['book_id' => 8841]);

        $json = $this->transport->lastJson();
        $this->assertSame(['external_id' => (string) $user->id, 'email' => 'jane@example.com'], array_intersect_key($json, ['external_id' => 1, 'email' => 1]));
        $this->assertArrayNotHasKey('first_name', $json);
        $this->assertSame(['book_id' => 8841], $json['properties']);
    }

    public function test_status_returns_ping_payload(): void
    {
        $this->transport->queueJson(200, ['ok' => true, 'group' => ['slug' => 'ebookwriter', 'name' => 'eBookWriter'], 'scopes' => ['events:write']]);

        $this->assertSame('ebookwriter', EngageHub::status()['group']['slug']);
    }

    public function test_disabled_engagehub_makes_no_requests(): void
    {
        config(['engagehub.enabled' => false]);

        EngageHub::track('signup');
        EngageHub::identify(['external_id' => 'u1']);

        $this->assertCount(0, $this->transport->requests);
    }
}
