<?php

declare(strict_types=1);

namespace Na2axl\LaravelApiFlow;

/**
 * Base state for a multi-step flow. Subclass it and add the properties your
 * flow collects along the way — each answered step merges its payload into
 * the state via the controller.
 */
abstract class FlowState
{
    /**
     * @var array Steps that may be answered from here.
     */
    public array $nextSteps = [];

    /**
     * @var array Steps that may be re-answered from here.
     */
    public array $backSteps = [];

    /**
     * The steps the client may call next. The first entry is the default the
     * UI should render; the rest are alternatives the user may choose.
     *
     * @param array<string, mixed> $rawState The raw state data.
     */
    public function __construct(array $rawState = [])
    {
        $this->nextSteps = $rawState[LaravelApiFlow::nextStepsKey()] ?? [];
        $this->backSteps = $rawState[LaravelApiFlow::backStepsKey()] ?? [];
    }

    public function toArray(): array
    {
        return [
            LaravelApiFlow::nextStepsKey() => $this->nextSteps,
            LaravelApiFlow::backStepsKey() => $this->backSteps,
        ];
    }
}
