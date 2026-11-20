<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Master switch
    |--------------------------------------------------------------------------
    | Disable to make every EngageHub call a no-op (observers, facade, jobs).
    | Useful in local/test environments without an EngageHub instance.
    */
    'enabled' => env('ENGAGEHUB_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Credentials
    |--------------------------------------------------------------------------
    | In EngageHub: Integrations → Connect an app. The key it shows once has
    | events:write, contacts:write and contacts:read. base_url is the
    | versioned API root, e.g. https://your-engagehub-host/api/v1.
    */
    'api_key' => env('ENGAGEHUB_API_KEY'),
    'base_url' => env('ENGAGEHUB_BASE_URL', 'https://engagehub.example.com/api/v1'),

    /*
    | Only for a key that is not tied to a group (a workspace-wide key): the
    | slug of the group to write into. Keys made by "Connect an app" carry
    | their group, so leave this empty.
    */
    'group' => env('ENGAGEHUB_GROUP'),

    /*
    |--------------------------------------------------------------------------
    | Dispatch mode
    |--------------------------------------------------------------------------
    | queue — observer/facade calls dispatch queued jobs (recommended).
    | sync  — calls hit the EngageHub API inline (simple setups, tinkering).
    */
    'dispatch' => env('ENGAGEHUB_DISPATCH', 'queue'),

    'queue' => [
        'connection' => env('ENGAGEHUB_QUEUE_CONNECTION'),
        'name' => env('ENGAGEHUB_QUEUE', 'engagehub'),
    ],

    /*
    |--------------------------------------------------------------------------
    | HTTP client
    |--------------------------------------------------------------------------
    */
    'timeout' => (int) env('ENGAGEHUB_TIMEOUT', 10),
    'max_retries' => (int) env('ENGAGEHUB_MAX_RETRIES', 3),

    /*
    |--------------------------------------------------------------------------
    | Commands from EngageHub
    |--------------------------------------------------------------------------
    | "Sync existing data" on EngageHub's Integrations page leaves a command for
    | this app. `engagehub:poll` picks it up and runs the backfill; it is
    | scheduled every minute, so the app's `schedule:run` cron must be running.
    */
    'poll' => [
        'enabled' => env('ENGAGEHUB_POLL', true),
    ],

    /*
    | Pushed commands: EngageHub calls POST <url> (signed with a hash of the API
    | key) when someone clicks "Sync existing data", so it starts at once; the
    | backfill runs on the queue above, so a queue worker must be running.
    | The poll stays as the backup. url defaults to APP_URL + path and must be
    | reachable from EngageHub; set ENGAGEHUB_COMMANDS=false to stay poll-only.
    */
    'commands' => [
        'enabled' => env('ENGAGEHUB_COMMANDS', true),
        'path' => env('ENGAGEHUB_COMMANDS_PATH', 'engagehub/commands'),
        'url' => env('ENGAGEHUB_COMMANDS_URL'),
    ],

    'sync' => [

        /*
        |----------------------------------------------------------------------
        | Delete behavior
        |----------------------------------------------------------------------
        | What happens in EngageHub when a synced model is deleted locally:
        | delete — DELETE /contacts/{external_id}: the person leaves the group
        |          in EngageHub (the contact row is kept, so an unsubscribe is
        |          never forgotten). Soft deletes count; a restore syncs again.
        | ignore — do nothing
        */
        'on_delete' => env('ENGAGEHUB_ON_DELETE', 'delete'),

        /*
        |----------------------------------------------------------------------
        | Backfill chunk size (max 500 — the server's batch limit)
        |----------------------------------------------------------------------
        */
        'chunk' => (int) env('ENGAGEHUB_SYNC_CHUNK', 500),

        /*
        |----------------------------------------------------------------------
        | Synced models
        |----------------------------------------------------------------------
        | Two ways to register a model:
        |
        | 1. Add the EngageHub\Laravel\Concerns\SyncsToEngageHub trait to the model
        |    (override toEngageHubContact() / engageHubShouldSync() as needed), AND
        |    list the class here (numeric key) so engagehub:sync can find it:
        |
        |        App\Models\User::class,
        |
        | 2. Config-only mapping for models you don't want to touch:
        |
        |        App\Models\User::class => [
        |            'external_id' => 'id',           // model attribute
        |            'map' => [                        // engagehub field => model attribute
        |                'email' => 'email',
        |                'first_name' => 'first_name',
        |            ],
        |            'attributes' => ['role'],         // model attributes => attributes
        |            'watch' => ['marketing_opt_out'], // extra columns whose change re-syncs
        |        ],
        |
        |    For computed values (derived attributes, consent), add
        |    engageHubContactExtras(): array to the model; it's merged over the
        |    mapping, e.g. ['attributes' => ['owns_x' => true], 'consent' => ['email' => false]].
        |    (A sync can only take consent away; EngageHub owns turning it back on.)
        |
        | Both register the observer (created/updated/deleted) and make the
        | model part of the engagehub:sync backfill.
        */
        'models' => [],
    ],
];
