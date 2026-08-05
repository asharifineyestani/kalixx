<?php

namespace App\Http\Resources;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SuccessResource extends JsonResource
{
    public static $wrap = null;

    public function __construct(
        protected string $message,
        mixed $resource = null,
        protected int $status = 200,
        protected array|object $meta = [],
    ) {
        parent::__construct($resource);
    }

    public function toArray(Request $request): array
    {
        return [
            'success' => true,
            'message' => $this->message,
            'data' => $this->resource === null
                ? null
                : parent::toArray($request),
            'errors' => (object) [],
            'meta' => (object) $this->meta,
        ];
    }

    public function withResponse(Request $request, JsonResponse $response): void
    {
        $response->setStatusCode($this->status);
    }
}