<?php

namespace App\Http\Responses;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

/**
 * The single place that builds the JSON envelope shared by every endpoint:
 *
 *   success: { success: true,  message, data, meta }
 *   error:   { success: false, message, error_code, errors }
 */
final class ApiResponse
{
    public const JSON_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION;

    public static function success(
        mixed $data = null,
        string $message = 'OK.',
        int $status = 200,
        ?array $meta = null,
    ): JsonResponse {
        if ($data instanceof JsonResource) {
            $data = $data->resolve(request());
        }

        return new JsonResponse([
            'success' => true,
            'message' => $message,
            'data' => $data,
            'meta' => $meta,
        ], $status, [], self::JSON_FLAGS);
    }

    public static function created(mixed $data, string $message, ?array $meta = null): JsonResponse
    {
        return self::success($data, $message, 201, $meta);
    }

    /**
     * @param  class-string<JsonResource>  $resourceClass
     */
    public static function paginated(LengthAwarePaginator $paginator, string $resourceClass, string $message): JsonResponse
    {
        return self::success(
            self::resolveCollection($paginator->getCollection(), $resourceClass),
            $message,
            200,
            [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ],
        );
    }

    /**
     * Full (non-paginated) list, used for small configuration lists.
     *
     * @param  class-string<JsonResource>  $resourceClass
     */
    public static function collection(Collection $items, string $resourceClass, string $message): JsonResponse
    {
        return self::success(
            self::resolveCollection($items, $resourceClass),
            $message,
            200,
            ['total' => $items->count()],
        );
    }

    public static function error(string $message, int $status, string $errorCode, ?array $errors = null): JsonResponse
    {
        return new JsonResponse([
            'success' => false,
            'message' => $message,
            'error_code' => $errorCode,
            'errors' => $errors === [] ? null : $errors,
        ], $status, [], self::JSON_FLAGS);
    }

    /**
     * @param  class-string<JsonResource>  $resourceClass
     */
    private static function resolveCollection(Collection $items, string $resourceClass): array
    {
        $request = request();

        return $items->map(fn ($item) => (new $resourceClass($item))->resolve($request))->values()->all();
    }
}
