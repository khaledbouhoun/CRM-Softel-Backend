<?php

namespace App\Exceptions;

use App\Http\Responses\ApiResponse;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Throwable;

/**
 * Converts every exception raised under /api into the standard error envelope.
 * Never includes stack traces, SQL or file paths; the full exception is still
 * written to the Laravel log by the framework's reporting step.
 */
class ApiExceptionRenderer
{
    public function render(Throwable $e, Request $request): JsonResponse
    {
        return match (true) {
            $e instanceof ValidationException => ApiResponse::error(
                'Validation failed.', 422, 'VALIDATION_FAILED', $e->errors(),
            ),
            $e instanceof BusinessRuleException => ApiResponse::error(
                $e->getMessage(), $e->status, $e->errorCode, $e->errors,
            ),
            $e instanceof NotFoundHttpException => $this->notFound($e),
            $e instanceof MethodNotAllowedHttpException => ApiResponse::error(
                'HTTP method not allowed for this endpoint.', 405, 'METHOD_NOT_ALLOWED',
            ),
            $e instanceof TooManyRequestsHttpException => ApiResponse::error(
                'Too many requests. Please retry shortly.', 429, 'TOO_MANY_REQUESTS',
            ),
            $e instanceof QueryException => $this->databaseError($e),
            $e instanceof HttpExceptionInterface => ApiResponse::error(
                $e->getMessage() !== '' ? $e->getMessage() : 'Request could not be processed.',
                $e->getStatusCode(),
                'HTTP_'.$e->getStatusCode(),
            ),
            default => ApiResponse::error('An unexpected server error occurred.', 500, 'SERVER_ERROR'),
        };
    }

    private function notFound(NotFoundHttpException $e): JsonResponse
    {
        $previous = $e->getPrevious();

        if ($previous instanceof ModelNotFoundException) {
            $model = Str::headline(class_basename($previous->getModel()));

            return ApiResponse::error("{$model} not found.", 404, 'NOT_FOUND');
        }

        return ApiResponse::error('Resource not found.', 404, 'NOT_FOUND');
    }

    private function databaseError(QueryException $e): JsonResponse
    {
        // SQLSTATE 23xxx = integrity constraint violation (FK, unique, not null).
        if (str_starts_with((string) $e->getCode(), '23')) {
            return ApiResponse::error(
                'The operation conflicts with existing data (database constraint).',
                409,
                'CONSTRAINT_VIOLATION',
            );
        }

        return ApiResponse::error('A database error occurred.', 500, 'DATABASE_ERROR');
    }
}
