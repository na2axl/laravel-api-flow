<?php

declare(strict_types=1);

namespace Na2axl\LaravelApiFlow\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Na2axl\LaravelApiFlow\LaravelApiFlow;

/**
 * Checks that a flow key is valid and active for the given flow ID.
 */
final readonly class ActiveFlowKey implements ValidationRule
{
    private function __construct(
        private string $flowId,
    ) {}

    /**
     * @return array<string|ValidationRule>
     */
    public static function make(string $flowId): array
    {
        return ['required', 'string', 'ulid', new self($flowId)];
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! LaravelApiFlow::cache()->has("{$this->flowId}:{$value}")) {
            $fail('The flow has expired or is invalid.');
        }
    }
}
