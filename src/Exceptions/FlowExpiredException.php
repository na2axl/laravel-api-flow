<?php

declare(strict_types=1);

namespace Na2axl\LaravelApiFlow\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;

/**
 * Thrown when a flow key no longer exists in the cache — either it expired
 * or was completed. Clients should restart the flow from the first step.
 */
class FlowExpiredException extends Exception
{
    public function __construct(string $message = 'Flow expired.')
    {
        parent::__construct($message, 410);
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'code' => 'FLOW_EXPIRED',
        ], 410);
    }
}
