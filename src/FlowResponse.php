<?php

declare(strict_types=1);

namespace Na2axl\LaravelApiFlow;

use Illuminate\Contracts\Support\Responsable;
use Illuminate\Http\JsonResponse;

/**
 * The response returned by FlowController::answer(). A flat JSON envelope:
 * the step's own payload under `data`, plus the legal transitions as
 * top-level metadata so clients can render the next screen without
 * hardcoding the flow.
 */
final class FlowResponse implements Responsable
{
    /** @var array<string, mixed> */
    private array $metadata = [];

    private function __construct(
        private readonly mixed $data = null,
        private readonly int $status = 200,
    ) {}

    public static function make(mixed $data = null, int $status = 200): self
    {
        return new self($data, $status);
    }

    /**
     * @param list<string> $nextSteps
     * @param list<string> $backSteps
     */
    public function withSteps(array $nextSteps, array $backSteps): self
    {
        $this->metadata[LaravelApiFlow::nextStepsKey()] = $nextSteps;
        $this->metadata[LaravelApiFlow::backStepsKey()] = $backSteps;

        return $this;
    }

    /** @param array<string, mixed> $metadata */
    public function withMetadata(array $metadata): self
    {
        $this->metadata = array_merge($this->metadata, $metadata);

        return $this;
    }

    public function toResponse($request): JsonResponse
    {
        return response()->json([
            'data' => $this->data,
            ...$this->metadata,
        ], $this->status);
    }
}
