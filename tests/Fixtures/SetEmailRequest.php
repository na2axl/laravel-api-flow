<?php

declare(strict_types=1);

namespace Na2axl\LaravelApiFlow\Tests\Fixtures;

use Na2axl\LaravelApiFlow\Http\FlowRequest;

class SetEmailRequest extends FlowRequest
{
    protected bool $defaultAuthorize = true;

    protected function stepRules(): array
    {
        return ['email' => ['required', 'email']];
    }
}
