<?php

namespace EngageHub\Laravel\Jobs;

use EngageHub\Laravel\CommandRunner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/** A command EngageHub pushed to POST /engagehub/commands, run off the request. */
class RunEngageHubCommand implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** A backfill of a large app can take a while; it reports its own progress. */
    public int $timeout = 3600;

    public int $tries = 1;

    /** @param  array{id: string, type: string}  $command */
    public function __construct(public array $command) {}

    public function handle(CommandRunner $runner): void
    {
        $runner->run($this->command);
    }
}
