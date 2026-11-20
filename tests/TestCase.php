<?php

namespace EngageHub\Laravel\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use EngageHub\Config;
use EngageHub\Laravel\EngageHubManager;
use EngageHub\Laravel\EngageHubServiceProvider;
use EngageHub\Laravel\Tests\Support\FakeTransport;
use EngageHub\EngageHubClient;

abstract class TestCase extends \Orchestra\Testbench\TestCase
{
    protected FakeTransport $transport;

    protected function getPackageProviders($app): array
    {
        return [EngageHubServiceProvider::class];
    }

    protected function getPackageAliases($app): array
    {
        return ['EngageHub' => \EngageHub\Laravel\Facades\EngageHub::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('engagehub.api_key', 'eh_test_key');
        $app['config']->set('engagehub.base_url', 'https://engagehub.test/api/v1');
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Route all SDK HTTP through the fake; the manager singleton must
        // be rebuilt against the re-bound client.
        $this->transport = new FakeTransport;
        $this->app->singleton(EngageHubClient::class, fn (): EngageHubClient => new EngageHubClient(
            new Config(apiKey: 'eh_test_key', baseUrl: 'https://engagehub.test/api/v1'),
            $this->transport,
            retrySleeper: static fn () => null,
        ));
        $this->app->forgetInstance(EngageHubManager::class);

        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->nullable();
            $table->string('email')->nullable();
            $table->string('role')->nullable();
            $table->string('plan')->nullable();
            $table->timestamps();
        });

        Support\SyncedUser::$syncGate = true;
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('users');

        parent::tearDown();
    }
}
