<?php

declare(strict_types=1);

namespace Na2axl\LaravelApiFlow\Http;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Na2axl\LaravelApiFlow\Exceptions\FlowExpiredException;
use Na2axl\LaravelApiFlow\LaravelApiFlow;
use Na2axl\LaravelApiFlow\Rules\ActiveFlowKey;
use RuntimeException;

/**
 * Base form request for flow steps. Carries the flow key, verifies the flow
 * is still alive, and enforces ownership when the flow is authenticated.
 *
 * Subclasses only declare their step fields in stepRules().
 */
abstract class FlowRequest extends FormRequest
{
    protected ?string $flowId = null;

    protected ?string $flowGuard = null;

    /**
     * Whether a step is callable without an authenticated user. Guest flows
     * (sign-in, register, forgot-password) set this to true.
     */
    protected bool $defaultAuthorize = false;

    protected function resolveFlowId(): string
    {
        if ($this->flowId !== null) {
            return $this->flowId;
        }

        $controller = $this->route()?->getController();

        if ($controller !== null) {
            if (method_exists($controller, 'flowId')) {
                return $controller->flowId();
            }

            if (property_exists($controller, 'flowId')) {
                return $controller->flowId;
            }

            if (defined($controller::class . '::FLOW_ID')) {
                return $controller::FLOW_ID;
            }
        }


        throw new RuntimeException('Flow request is not bound to a flow controller.');
    }

    /**
     * @throws FlowExpiredException
     */
    public function authorize(): bool
    {
        $flowKey = $this->input(LaravelApiFlow::flowKeyName());

        if (!is_string($flowKey) || !Str::isUlid($flowKey)) {
            return true; // malformed — rules() will reject it with a 422
        }

        $flowId = $this->resolveFlowId();

        if (!LaravelApiFlow::cache()->has("{$flowId}:{$flowKey}")) {
            throw new FlowExpiredException();
        }

        $user = $this->user($this->flowGuard);

        if ($user) {
            return LaravelApiFlow::cache()->get("{$flowId}:{$flowKey}:owner") === $user->getAuthIdentifier();
        }

        return $this->defaultAuthorize;
    }

    /**
     * @return array<string, mixed>
     */
    final public function rules(): array
    {
        return [
            LaravelApiFlow::flowKeyName() => ActiveFlowKey::make($this->resolveFlowId()),
            ...$this->stepRules(),
        ];
    }

    /**
     * The validated flow key for use in the step method.
     */
    public function flowKey(): string
    {
        return (string)$this->validated(LaravelApiFlow::flowKeyName());
    }

    /**
     * @return array<string, mixed>
     */
    abstract protected function stepRules(): array;
}
