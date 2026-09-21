<?php

declare(strict_types=1);

namespace Na2axl\LaravelApiFlow;

use Illuminate\Support\Facades\Cache;

/**
 * Small shared accessors so the controller, the validation rule, and the
 * form request all hit the same configured cache store.
 */
final class LaravelApiFlow
{
    public static function cache(): \Illuminate\Cache\Repository
    {
        return Cache::store(config('flow.cache_store'));
    }

    public static function staleTime(): int
    {
        return (int) config('flow.stale_time', 10);
    }

    public static function flowKeyName(): string
    {
        return config('flow.flow_key_key', 'flow_key');
    }

    public static function nextStepsKey(): string
    {
        return config('flow.next_steps_key', 'next_steps');
    }

    public static function backStepsKey(): string
    {
        return config('flow.back_steps_key', 'back_steps');
    }
}
