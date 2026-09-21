<?php

declare(strict_types=1);

namespace Na2axl\LaravelApiFlow;

use BackedEnum;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;
use Na2axl\LaravelApiFlow\Exceptions\FlowExpiredException;
use Na2axl\LaravelApiFlow\Exceptions\UnexpectedFlowStepException;

/**
 * Base controller for server-driven multi-step API flows.
 *
 * A flow is a state machine: each step declares which steps may follow it
 * (nextSteps) and which earlier steps may be re-answered from it (backSteps).
 * The server owns the machine — clients receive the legal transitions with
 * every answer.
 *
 * Subclass it, declare a $flowStateClass and a $flowId, implement
 * nextSteps(), and route one method per step. Each step method calls
 * validateStep() first, does its work, then returns answer().
 *
 * @template TFlowState of FlowState
 */
abstract class FlowController extends Controller
{
    /** @var class-string<TFlowState> */
    protected string $flowStateClass;

    /**
     * Cache key prefix for this flow — unique per flow type
     * (e.g. "onboarding", "checkout").
     */
    protected string $flowId;

    /**
     * Minutes an unanswered flow stays valid. Null falls back to
     * config('flow.stale_time').
     */
    protected ?int $staleTimeInMinutes = null;

    /**
     * Auth guard whose user owns the flow. When set, the owner's id is
     * cached alongside the state so steps can verify ownership.
     */
    protected ?string $flowGuard = null;

    /**
     * Request-scoped memo of the current flow state. Scoped to the current
     * request instance, not to the controller instance — Route objects
     * memoize their controller, so the instance can outlive a request on
     * long-lived runtimes (Octane, FrankenPHP).
     *
     * @var array<string, TFlowState>
     */
    private array $stateMemo = [];

    /**
     * The request instance the memo belongs to. Route objects memoize their
     * controller instance, so under Octane (and in tests reusing the app) a
     * controller can outlive a request — the memo must not.
     */
    private ?Request $memoRequest = null;

    /**
     * Map the current step and state to the steps that may follow it.
     * An empty list terminates the flow.
     *
     * @param TFlowState $state
     *
     * @return list<string>
     */
    abstract protected function nextSteps(string $current, FlowState $state): array;

    /**
     * Steps that may be re-answered from the current one.
     *
     * @param TFlowState $state
     *
     * @return list<string>
     */
    protected function backSteps(string $current, FlowState $state): array
    {
        return [];
    }

    /**
     * Discard the state owned by the rewound step's descendants. Rewinding is
     * destructive downstream — never a partial merge.
     *
     * @param TFlowState $state
     */
    protected function onRewind(string $step, FlowState $state): void
    {
    }

    /**
     * @return TFlowState
     */
    protected function getState(string $flowKey): FlowState
    {
        $this->resetMemoOnNewRequest();

        if (isset($this->stateMemo[$flowKey])) {
            return $this->stateMemo[$flowKey];
        }

        $cached = $this->cache()->get("{$this->flowId}:{$flowKey}");

        $state = new ($this->flowStateClass)($cached ?? []);

        $this->stateMemo[$flowKey] = $state;

        return $state;
    }

    /**
     * @param TFlowState $state
     */
    protected function setState(string $flowKey, FlowState $state): void
    {
        $this->stateMemo[$flowKey] = $state;

        $ttl = now()->addMinutes($this->staleTimeInMinutes ?? LaravelApiFlow::staleTime());

        $this->cache()->put("{$this->flowId}:{$flowKey}", $state->toArray(), $ttl);

        $user = request()->user($this->flowGuard);
        if ($user) {
            $this->cache()->put("{$this->flowId}:{$flowKey}:owner", $user->getAuthIdentifier(), $ttl);
        }
    }

    /**
     * Merge a partial payload into the current state and persist it.
     *
     * @param array<string, mixed> $partial
     */
    protected function appendState(string $flowKey, array $partial): void
    {
        $current = $this->getState($flowKey)->toArray();
        $this->setState($flowKey, new $this->flowStateClass(array_merge($current, $partial)));
    }

    protected function forgetState(string $flowKey): void
    {
        unset($this->stateMemo[$flowKey]);

        $this->cache()->forget("{$this->flowId}:{$flowKey}");
        $this->cache()->forget("{$this->flowId}:{$flowKey}:owner");
    }

    /**
     * The id of the user owning this flow, if $flowGuard is set and the
     * flow was started by an authenticated request.
     */
    protected function flowOwnerId(string $flowKey): mixed
    {
        return $this->cache()->get("{$this->flowId}:{$flowKey}:owner");
    }

    /**
     * Public accessor used by FlowRequest to resolve which flow a step
     * request belongs to.
     */
    public function flowId(): string
    {
        return $this->flowId;
    }

    /**
     * Start a new flow: generate a ULID flow key, seed the initial state,
     * and advertise the first transitions. Use from stepInitiate().
     *
     * @param array<string, mixed> $data
     */
    protected function initiateFlow(array $data = [], string $startStep = 'initiate'): FlowResponse
    {
        $flowKey = $this->generateFlowKey();

        $state = new ($this->flowStateClass)([]);
        $state->nextSteps = $this->nextSteps($startStep, $state);
        $state->backSteps = $this->backSteps($startStep, $state);
        $this->setState($flowKey, $state);

        return FlowResponse::make($data)
            ->withSteps($state->nextSteps, $state->backSteps)
            ->withMetadata([LaravelApiFlow::flowKeyName() => $flowKey]);
    }

    protected function generateFlowKey(): string
    {
        return (string)Str::ulid();
    }

    /**
     * Commit the current step: advertise the legal transitions to the client
     * and terminate the flow when no next step remains.
     *
     * @param array<string, mixed> $data
     */
    protected function answer(
        string            $flowKey,
        string|BackedEnum $currentStep,
        array             $data = [],
    ): FlowResponse
    {
        $stepName = $this->resolveStepName($currentStep);
        $state = $this->getState($flowKey);
        $next = $this->nextSteps($stepName, $state);
        $back = $this->backSteps($stepName, $state);

        $state->nextSteps = $next;
        $state->backSteps = $back;
        $this->setState($flowKey, $state);

        if ($next === []) {
            $this->forgetState($flowKey);
        }

        return FlowResponse::make($data)->withSteps($next, $back);
    }

    /**
     * Guard a step method: the flow must be alive and the step must be a
     * legal transition from the flow's current position.
     *
     * @return TFlowState
     *
     * @throws FlowExpiredException
     * @throws UnexpectedFlowStepException
     */
    protected function validateStep(string $flowKey, string|BackedEnum $expectedStep): FlowState
    {
        $stepName = $this->resolveStepName($expectedStep);

        if (!$this->cache()->has("{$this->flowId}:{$flowKey}")) {
            throw new FlowExpiredException();
        }

        $state = $this->getState($flowKey);

        $isForward = in_array($stepName, $state->nextSteps, true);
        $isBack = in_array($stepName, $state->backSteps, true);

        if (!$isForward && !$isBack) {
            throw new UnexpectedFlowStepException();
        }

        if ($isBack) {
            // Re-answering an earlier step discards everything after it, then
            // the step body runs normally and re-runs its side effects. The
            // rewind mutates the memo only: if the body later fails (e.g. a
            // throttle refusal), the cached flow must stay untouched, so the
            // write-through happens when the body commits via setState().
            $this->onRewind($stepName, $state);
        }

        return $state;
    }

    private function resolveStepName(string|BackedEnum $step): string
    {
        return $step instanceof BackedEnum ? (string)$step->value : $step;
    }

    /**
     * The memo is only valid within one HTTP request. Controllers are
     * memoized by their Route object, so on long-lived runtimes (Octane,
     * FrankenPHP, test suites reusing the app) the instance survives —
     * compare the current request to detect the boundary.
     */
    private function resetMemoOnNewRequest(): void
    {
        $current = request();

        if ($this->memoRequest !== $current) {
            $this->stateMemo = [];
            $this->memoRequest = $current;
        }
    }

    private function cache(): \Illuminate\Cache\Repository
    {
        return LaravelApiFlow::cache();
    }
}
