<?php

namespace EngageHub\Laravel\Tests;

use EngageHub\Laravel\EngageHubManager;
use InvalidArgumentException;

/** engagehub.sync.meters: declared to EngageHub via PUT /connection/schema. */
class MetersTest extends TestCase
{
    public function test_meters_are_normalized_with_a_label_and_an_optional_limit(): void
    {
        config()->set('engagehub.sync.meters', [
            'credits' => ['used' => 'credits_used', 'limit' => 'credits'],
            'video_minutes' => 'minutes_used',
        ]);

        $this->assertSame([
            ['used' => 'credits_used', 'limit' => 'credits', 'label' => 'Credits'],
            ['used' => 'minutes_used', 'limit' => null, 'label' => 'Video Minutes'],
        ], $this->app->make(EngageHubManager::class)->meters());
    }

    public function test_a_meter_without_used_is_a_config_error(): void
    {
        config()->set('engagehub.sync.meters', ['credits' => ['limit' => 'credits']]);

        $this->expectException(InvalidArgumentException::class);

        $this->app->make(EngageHubManager::class)->meters();
    }

    public function test_an_app_without_meters_sends_nothing(): void
    {
        $this->assertFalse($this->app->make(EngageHubManager::class)->describe(force: true));
        $this->assertSame([], $this->transport->requests);
    }

    public function test_meters_are_sent_once_and_again_when_they_change(): void
    {
        $this->transport->queueJson(200, ['ok' => true, 'meters' => 1]);
        config()->set('engagehub.sync.meters', ['credits' => ['used' => 'credits_used', 'limit' => 'credits']]);
        $engagehub = $this->app->make(EngageHubManager::class);

        $this->assertTrue($engagehub->describe());
        $this->assertSame('PUT', $this->transport->requests[0]['method']);
        $this->assertStringEndsWith('/connection/schema', $this->transport->requests[0]['url']);
        $this->assertSame(['meters' => [['used' => 'credits_used', 'limit' => 'credits', 'label' => 'Credits']]], $this->transport->lastJson());

        $this->assertFalse($engagehub->describe(), 'unchanged: not sent again');

        // Removing every meter clears them in EngageHub.
        config()->set('engagehub.sync.meters', []);
        $this->assertTrue($engagehub->describe());
        $this->assertSame(['meters' => []], $this->transport->lastJson());
        $this->assertCount(2, $this->transport->requests);
    }
}
