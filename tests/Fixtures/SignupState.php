<?php

declare(strict_types=1);

namespace Na2axl\LaravelApiFlow\Tests\Fixtures;

use Illuminate\Support\Arr;
use Na2axl\LaravelApiFlow\FlowState;

class SignupState extends FlowState
{
    public ?string $email = null;
    public ?string $plan = null;

    public function __construct(array $rawState)
    {
        $this->email = Arr::get($rawState, 'email');
        $this->plan = Arr::get($rawState, 'plan');

        parent::__construct($rawState);
    }

    public function toArray(): array
    {
        return array_merge(
            [
                'email' => $this->email,
                'plan' => $this->plan
            ],
            parent::toArray()
        );
    }
}
