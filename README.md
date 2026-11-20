# engagehub/laravel

Laravel bridge for the EngageHub SDK: install it in any Laravel app and its users (and any other models you choose) mirror into your EngageHub workspace as contacts — a one-time backfill plus automatic ongoing sync — and you get an ergonomic `EngageHub::track()` for events.

> **Status (Nov 2026):** speaks EngageHub's REST API v1 (phase 1a: contacts, events, ping). Contract: [apps/engagehub/docs/data-sync/06-rest-api-and-webhooks.md](../../apps/engagehub/docs/data-sync/06-rest-api-and-webhooks.md). Not on Packagist or npm yet: install from this repository. Plans, subscriptions and objects come in phase 1b.

## Install

```jsonc
// composer.json of the consuming Laravel app — both path repos are needed
"repositories": [
  { "type": "path", "url": "/path/to/engagehub/packages/engagehub-php", "options": { "symlink": true } },
  { "type": "path", "url": "/path/to/engagehub/packages/engagehub-laravel", "options": { "symlink": true } }
]
```

```bash
# Pulls in engagehub/engagehub-php (^0.3) automatically
composer require engagehub/laravel:^0.3
php artisan vendor:publish --tag=engagehub-config
```

Versions come from the `"version"` field in each package's `composer.json` (both 0.3.0, matching
`EngageHubClient::VERSION`). Bump all three together on a release. Drop the field once the packages
are published from their own git repositories with tags.

```dotenv
ENGAGEHUB_API_KEY=eh_live_...            # EngageHub: Integrations → Connect an app
ENGAGEHUB_BASE_URL=https://your-engagehub-host/api/v1
ENGAGEHUB_DISPATCH=queue               # queue (recommended, needs a worker) | sync (inline HTTP)
```

Verify: `php artisan engagehub:status`.

## Sync a model

Option 1 — trait (models you own):

```php
use EngageHub\Laravel\Concerns\SyncsToEngageHub;

class User extends Authenticatable
{
    use SyncsToEngageHub;

    // Optional overrides:
    public function toEngageHubContact(): array          // field mapping (default: id→external_id, email, name split)
    {
        return [
            'external_id' => (string) $this->id,
            'email' => $this->email,
            'first_name' => $this->first_name,
            'attributes' => ['role' => $this->role, 'tier' => $this->subscription_tier],
            'updated_at' => $this->updated_at?->toIso8601String(), // lets EngageHub skip late, older updates
        ];
    }

    public function engageHubShouldSync(): bool          // gate rows (e.g. skip internal users)
    {
        return ! $this->is_test_account;
    }

    public function engageHubWatchedAttributes(): array  // only these changes trigger a sync
    {
        return ['email', 'first_name', 'subscription_tier', 'role'];
    }
}
```

Option 2 — config mapping (no model changes), in `config/engagehub.php`:

```php
'sync' => [
    'models' => [
        App\Models\User::class => [
            'external_id' => 'id',
            'map' => ['email' => 'email', 'name' => 'name'], // full name, split into first/last
            'attributes' => ['role'],
        ],
    ],
],
```

Either way: `created`/`updated` push the contact (queued by default, with `created_at`/`updated_at`), and `deleted` tells EngageHub the user is gone, so they leave the group there (`ENGAGEHUB_ON_DELETE=delete`, the default, or `ignore`). With `SoftDeletes`, a soft delete counts as a delete and `restore()` syncs the user again. The contact row stays in EngageHub, so an unsubscribe is never forgotten.

**List trait models in `engagehub.sync.models` too** (numeric entry, no mapping) so the backfill can find them:

```php
'models' => [App\Models\User::class],
```

## Backfill existing data

```bash
php artisan engagehub:sync                                  # all registered models
php artisan engagehub:sync --model="App\Models\User"        # one model
php artisan engagehub:sync --after-id=15000 --rpm=120       # resume + slower pacing
php artisan engagehub:sync --dry-run                        # count without sending
```

Chunked (500/request), rate-limit aware, resumable, exits non-zero if any rows were rejected.

## "Sync existing data" from EngageHub

Clicking **Sync existing data** on EngageHub's Integrations page runs the backfill in your app:

- **Pushed (0.4+):** EngageHub calls `POST /engagehub/commands` on your app at once. The request is
  signed with the SHA-256 of your API key, so there is nothing extra to configure; the backfill runs
  on the queue (`ENGAGEHUB_QUEUE`), so a **queue worker** must be running. The URL is `APP_URL` +
  `/engagehub/commands` and must be reachable from EngageHub (override with `ENGAGEHUB_COMMANDS_URL`,
  turn off with `ENGAGEHUB_COMMANDS=false`).
- **Polled (backup):** `engagehub:poll` runs every minute from your scheduler and picks up any
  command the push could not deliver. Keep `schedule:run` in your cron.

A command runs once, whichever way it arrives.

## Track events

```php
use EngageHub\Laravel\Facades\EngageHub;

EngageHub::track('order_shipped', $user, ['order_id' => $order->id]); // pass the model: sends external_id + email
EngageHub::track('order_shipped', ['external_id' => (string) $user->id], ['order_id' => $order->id]);
EngageHub::identify($user);                       // force a contact sync
EngageHub::identify(['external_id' => 'crm-77', 'email' => 'x@y.z']);
EngageHub::forget($user);                         // the user was deleted in your app
```

Pass the model to `track()` when you can: the event then carries the email too, so EngageHub can create the contact even if the event job runs before the user's sync job. An event with only an `external_id` EngageHub has never seen is rejected (`unknown_contact`).

Queued calls carry a pre-generated `event_id`, so queue retries dedupe server-side. Rejected items are logged to your app's log channel and shown in the connection's request log in EngageHub (Integrations → the app → Request log).

## Tests

```bash
composer install && composer test
```
