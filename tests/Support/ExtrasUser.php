<?php

namespace EngageHub\Laravel\Tests\Support;

/** A config-mapped user with computed contact fields. */
class ExtrasUser extends PlainUser
{
    /** @return array<string, mixed> */
    public function engageHubContactExtras(): array
    {
        return [
            'attributes' => ['is_pro' => $this->plan === 'pro'],
            'consent' => ['email' => $this->plan !== 'opted_out'],
        ];
    }
}
