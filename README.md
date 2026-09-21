# Laravel API Flow

**Server-driven multi-step API flows for Laravel.** Declare your onboarding, checkout, or KYC flow as a state machine — the API tells clients which steps are legal next, so no frontend ever hardcodes the flow graph again.

## The problem

Multi-step flows rot fast when step logic is scattered across controllers: the frontend hardcodes the step order, "go back" buttons break invariants, and skipped steps cause inconsistent state. This package moves the state machine to the server:

- Each step declares which steps may **follow** it (`nextSteps`) and which earlier steps may be **re-answered** from it (`backSteps`)
- Every response advertises the legal transitions (`next_steps` / `back_steps`) — the client just renders them
- Illegal transitions are rejected with `409 FLOW_UNEXPECTED_STEP`; expired flows with `410 FLOW_EXPIRED`
- Re-answering a step **rewinds** the flow: downstream state is discarded, never partially merged
- One `Route::flow()` call registers the whole flow — no route bookkeeping

## Installation

```bash
composer require na2axl/laravel-api-flow
```

Optionally publish the config:

```bash
php artisan vendor:publish --tag=api-flow-config
```

## Usage

### 1. Define the flow state

```php
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
    
    public function toArray() : array{
        return array_merge(
            [
                'email' => $this->email,
                'plan' => $this->plan
            ],
            parent::toArray()
        );
    }
}
```

### 2. Declare the state machine in a controller

Steps are public methods named `stepXxx`. Each step receives a `FlowRequest`
subclass carrying the `flow_key`, guards the transition with
`validateStep()`, does its work, and commits with `answer()`.

```php
use Na2axl\LaravelApiFlow\FlowController;
use Na2axl\LaravelApiFlow\FlowResponse;
use Na2axl\LaravelApiFlow\FlowState;

class SignupFlowController extends FlowController
{
    public const FLOW_ID = 'signup';

    protected string $flowStateClass = SignupState::class;
    protected string $flowId = self::FLOW_ID;
    protected ?int $staleTimeInMinutes = 15; // optional, default from config

    // GET /signup/initiate — starts the flow, returns the flow key
    // Every flow controller must have a stepInitiate() method.
    public function stepInitiate(): FlowResponse
    {
        return $this->initiateFlow();
    }

    // POST /signup/set-email
    public function stepSetEmail(SetEmailRequest $request): FlowResponse
    {
        $this->validateStep($request->flowKey(), 'set-email'); // 410 if expired, 409 if illegal
        $this->appendState($request->flowKey(), ['email' => $request->validated('email')]);

        return $this->answer($request->flowKey(), 'set-email');
    }

    // POST /signup/set-plan
    public function stepSetPlan(SetPlanRequest $request): FlowResponse
    {
        $this->validateStep($request->flowKey(), 'set-plan');
        $this->appendState($request->flowKey(), ['plan' => $request->validated('plan')]);

        return $this->answer($request->flowKey(), 'set-plan');
    }

    protected function nextSteps(string $current, FlowState $state): array
    {
        return match ($current) {
            'initiate' => ['set-email'],
            'set-email' => ['set-plan'],
            'set-plan' => [], // empty = flow terminates, state is discarded
            default => [],
        };
    }

    protected function backSteps(string $current, FlowState $state): array
    {
        // "Change my email" on the plan screen re-answers the email step.
        return $current === 'set-plan' ? ['set-email'] : [];
    }

    protected function onRewind(string $step, FlowState $state): void
    {
        // Rewinding is destructive downstream: re-answering `email`
        // discards the plan the user had already picked.
        if ($step === 'set-email') {
            $state->plan = null;
        }
    }
}
```

### 3. One request class per step

`FlowRequest` carries the `flow_key` field, rejects expired flows with a `410` during authorization, validates the key
format (`required|string|ulid` + an active-flow check), and enforces ownership for authenticated flows.
You only declare the step's own fields:

```php
use Na2axl\LaravelApiFlow\Http\FlowRequest;

class SetEmailRequest extends FlowRequest
{
    // Guest flows (register, sign-in, forgot-password) allow unauthenticated steps.
    protected bool $defaultAuthorize = true;

    protected function stepRules(): array
    {
        return ['email' => ['required', 'email']];
    }
}
```

The flow id is resolved automatically from the controller the step is routed
to (via its `flowId()` method or `FLOW_ID` constant); set the `$flowId`
property to override.

### 4. Register all step routes in one call

```php
Route::flow('signup', SignupFlowController::class);
```

The macro registers one route per public `step*` method:

| Method         | Route                    | Name                    |
|----------------|--------------------------|-------------------------|
| `stepInitiate` | `GET /signup/initiate`   | `flow.signup.initiate`  |
| `stepSetEmail` | `POST /signup/set-email` | `flow.signup.set-email` |
| `stepSetPlan`  | `POST /signup/set-plan`  | `flow.signup.set-plan`  |

Middleware stacks apply as usual — wrap the call in a group:

```php
Route::middleware('auth:sanctum')->group(function () {
    Route::flow('onboarding', OnboardingFlowController::class);
});
```

### 5. The client renders what the API advertises

```json
GET /signup/initiate  →  200
{
    "data": [],
    "next_steps": ["email"],
    "back_steps": [],
    "flow_key": "01J8Q0ZK0Z8ZK0Z8ZK0Z8ZK0Z8"
}
```

```json
POST /signup/email  { "flow_key": "01J…", "email": "a@b.c" }  →  200
{ "data": {}, "next_steps": ["plan"], "back_steps": [] }
```

```json
POST /signup/plan (skipped ahead)  →  409
{ "message": "Unexpected step.", "code": "FLOW_UNEXPECTED_STEP" }
```

```json
POST /signup/email (stale or unknown flow key)  →  410
{ "message": "Flow expired.", "code": "FLOW_EXPIRED" }
```

## Design notes (the opinionated parts)

- **Server owns the graph.** The frontend never hardcodes step order; conditional branching (`nextSteps` receives the full `$state`) is a server-side concern.
- **Rewind is destructive downstream.** Re-answering a step discards descendant state via `onRewind()` — a partial merge would silently keep decisions made on outdated answers.
- **Rewinds don't touch the cache until commit.** `onRewind()` mutates the request-scoped memo only; if the step body later fails (validation, throttling), the cached flow stays intact. Write-through happens on `setState()`.
- **Memoization scoped to the request, not the controller.** Laravel `Route` objects memoize their controller instance, so under Octane/FrankenPHP a controller outlives a request. The state memo detects the request boundary and resets — no stale state leaks across long-lived workers.
- **Ephemeral by design.** Flow state lives in the cache with a TTL (default 10 min, per-flow via `$staleTimeInMinutes`, globally in `config/flow.php`). Completed flows forget themselves.
- **Ownership.** Set `$flowGuard` to cache the authenticated user's id alongside the flow; `FlowRequest::authorize()` then rejects steps from other users with a 403.

## Configuration

| Key                   | Env                | Default       | Purpose                                 |
|-----------------------|--------------------|---------------|-----------------------------------------|
| `flow.cache_store`    | `FLOW_CACHE_STORE` | default store | Cache store for flow state              |
| `flow.stale_time`     | `FLOW_STALE_TIME`  | `10`          | Minutes before a flow expires           |
| `flow.next_steps_key` | —                  | `next_steps`  | JSON key for forward transitions        |
| `flow.back_steps_key` | —                  | `back_steps`  | JSON key for back transitions           |
| `flow.flow_key_key`   | —                  | `flow_key`    | Request/response field for the flow key |

## Requirements

- PHP 8.2+
- Laravel 12 / 13

## Testing

```bash
composer install
composer test
```

## License

MIT © Axel Nana
