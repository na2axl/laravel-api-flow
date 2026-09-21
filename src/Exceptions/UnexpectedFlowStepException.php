<?php

declare(strict_types=1);

namespace Na2axl\LaravelApiFlow\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;

/**
 * Thrown when a client answers a step that is not a legal transition from
 * the flow's current position — i.e. it appears in neither the advertised
 * next steps nor the advertised back steps.
 */
class UnexpectedFlowStepException extends Exception
{
    public function __construct(string $message = 'Unexpected step.')
    {
        parent::__construct($message, 409);
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'code' => 'FLOW_UNEXPECTED_STEP',
        ], 409);
    }
}
