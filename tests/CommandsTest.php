<?php

namespace EngageHub\Laravel\Tests;

use EngageHub\Laravel\CommandRunner;
use EngageHub\Laravel\EngageHubServiceProvider;
use EngageHub\Laravel\Jobs\RunEngageHubCommand;
use EngageHub\Webhooks\WebhookSignature;
use Illuminate\Support\Facades\Queue;

/** Commands pushed by EngageHub to POST /engagehub/commands, and the run-once lock shared with the poll. */
class CommandsTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('app.url', 'https://app.test');
        $app['config']->set('engagehub.sync.models', []);
    }

    private function send(string $body, string $secret): \Illuminate\Testing\TestResponse
    {
        return $this->call('POST', '/engagehub/commands', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ENGAGEHUB_SIGNATURE' => WebhookSignature::header($body, $secret, time()),
        ], $body);
    }

    public function test_the_command_url_defaults_to_app_url_and_the_path(): void
    {
        $this->assertSame('https://app.test/engagehub/commands', EngageHubServiceProvider::commandUrl(config('engagehub')));

        config()->set('engagehub.commands.enabled', false);
        $this->assertNull(EngageHubServiceProvider::commandUrl(config('engagehub')));
    }

    public function test_a_signed_backfill_is_queued(): void
    {
        Queue::fake();

        $this->send('{"id":"cmd-1","type":"backfill"}', hash('sha256', 'eh_test_key'))->assertStatus(202);

        Queue::assertPushed(RunEngageHubCommand::class, fn (RunEngageHubCommand $job) => $job->command === ['id' => 'cmd-1', 'type' => 'backfill']);
    }

    public function test_a_wrong_signature_is_refused_and_nothing_runs(): void
    {
        Queue::fake();

        $this->send('{"id":"cmd-1","type":"backfill"}', hash('sha256', 'another_key'))->assertStatus(401);

        Queue::assertNothingPushed();
    }

    public function test_a_command_runs_once_even_when_both_the_push_and_the_poll_bring_it(): void
    {
        $this->transport->queueJson(200, ['ok' => true]); // running
        $this->transport->queueJson(200, ['ok' => true]); // done

        $runner = $this->app->make(CommandRunner::class);

        $this->assertSame('done', $runner->run(['id' => 'cmd-1', 'type' => 'backfill']));
        $this->assertSame('skipped', $runner->run(['id' => 'cmd-1', 'type' => 'backfill']));

        $reports = array_values(array_filter($this->transport->requests, fn (array $r) => str_ends_with($r['url'], '/connection/sync-runs')));
        $this->assertSame(['running', 'done'], array_map(fn (array $r) => json_decode((string) $r['body'], true)['status'], $reports));
    }
}
