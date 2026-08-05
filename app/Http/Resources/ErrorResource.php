<?php

namespace App\Http\Resources;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ErrorResource extends JsonResource
{
    public static $wrap = null;

    public function __construct(
        protected string $message,
        protected int $status = 400,
        protected array|object $errors = [],
        protected array|object $meta = [],
    ) {
        parent::__construct(null);
    }

    public function toArray(Request $request): array
    {
        return [
            'success' => false,
            'message' => $this->message,
            'data' => null,
            'errors' => (object) $this->errors,
            'meta' => (object) $this->meta,
        ];
    }

    public function withResponse(Request $request, JsonResponse $response): void
    {
        $response->setStatusCode($this->status);
    }
}