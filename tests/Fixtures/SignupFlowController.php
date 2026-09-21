<?php

declare(strict_types=1);

namespace Na2axl\LaravelApiFlow\Tests\Fixtures;

use Na2axl\LaravelApiFlow\FlowController;
use Na2axl\LaravelApiFlow\FlowResponse;
use Na2axl\LaravelApiFlow\FlowState;

/**
 * A three-step signup flow: initiate → email → plan → confirm → done.
 * `email` may be re-answered from `plan`, and `email`/`plan` from `confirm`
 * (back edges are only meaningful on non-terminal steps).
 *
 * @extends FlowController<SignupState>
 */
class SignupFlowController extends FlowController
{
    public const FLOW_ID = 'signup';

    protected string $flowStateClass = SignupState::class;

    protected string $flowId = self::FLOW_ID;

    public function stepInitiate(): FlowResponse
    {
        return $this->initiateFlow();
    }

    public function stepEmail(SetEmailRequest $request): FlowResponse
    {
        $this->validateStep($request->flowKey(), 'email');
        $this->appendState($request->flowKey(), ['email' => $request->validated('email')]);

        return $this->answer($request->flowKey(), 'email');
    }

    public function stepPlan(SetPlanRequest $request): FlowResponse
    {
        $this->validateStep($request->flowKey(), 'plan');
        $this->appendState($request->flowKey(), ['plan' => $request->validated('plan')]);

        return $this->answer($request->flowKey(), 'plan', ['state' => $this->getState($request->flowKey())->toArray()]);
    }

    public function stepConfirm(ConfirmRequest $request): FlowResponse
    {
        $this->validateStep($request->flowKey(), 'confirm');

        return $this->answer($request->flowKey(), 'confirm', ['state' => $this->getState($request->flowKey())->toArray()]);
    }

    protected function nextSteps(string $current, FlowState $state): array
    {
        return match ($current) {
            'initiate' => ['email'],
            'email' => ['plan'],
            'plan' => ['confirm'],
            'confirm' => [],
            default => [],
        };
    }

    protected function backSteps(string $current, FlowState $state): array
    {
        return match ($current) {
            'plan' => ['email'],
            'confirm' => ['email', 'plan'],
            default => [],
        };
    }

    protected function onRewind(string $step, FlowState $state): void
    {
        if ($step === 'email') {
            $state->plan = null;
        }
    }
}
