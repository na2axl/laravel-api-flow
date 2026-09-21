<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Cache store
    |--------------------------------------------------------------------------
    | Which cache store holds in-progress flow state. Null = default store.
    | Flow state is ephemeral by design, so a fast store (redis, array)
    | is recommended over a persistent one (database, file).
    */
    'cache_store' => env('FLOW_CACHE_STORE'),

    /*
    |--------------------------------------------------------------------------
    | Default stale time (minutes)
    |--------------------------------------------------------------------------
    | How long an unanswered flow stays valid before it expires with a 410.
    | Controllers may override this per-flow via $staleTimeInMinutes.
    */
    'stale_time' => env('FLOW_STALE_TIME', 10),

    /*
    |--------------------------------------------------------------------------
    | Metadata keys
    |--------------------------------------------------------------------------
    | The JSON keys used to advertise legal transitions in responses.
    */
    'next_steps_key' => 'next_steps',
    'back_steps_key' => 'back_steps',

    /*
    |--------------------------------------------------------------------------
    | Flow key field
    |--------------------------------------------------------------------------
    | The request field carrying the ULID flow key on step calls, and the
    | metadata key returning it from the initiate step.
    */
    'flow_key_key' => 'flow_key',
];
