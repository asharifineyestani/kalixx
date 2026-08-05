<?php

namespace App\Modules\Auth\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Resources\ErrorResource;
use App\Http\Resources\SuccessResource;
use App\Models\User;
use App\Modules\Auth\Models\SocialAccount;
use App\Modules\Auth\Services\GoogleIdTokenVerifier;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response as HttpResponse;
use Throwable;

class GoogleAuthController extends Controller
{
    public function login(
        Request $request,
        GoogleIdTokenVerifier $verifier,
    ): SuccessResource|ErrorResource {
        $validated = $this->validateRequest($request);

        try {
            $googlePayload = $verifier->verify(
                $validated['id_token']
            );
        } catch (Throwable $exception) {
            report($exception);

            return new ErrorResource(
                message: __('auth.invalid_credentials'),
                status: HttpResponse::HTTP_SERVICE_UNAVAILABLE,
            );
        }

        if (!$this->isValidGooglePayload($googlePayload)) {
            return new ErrorResource(
                message: __('auth.invalid_credentials'),
                status: HttpResponse::HTTP_UNAUTHORIZED,
            );
        }

        $user = $this->findOrCreateUser($googlePayload);

        return $this->authResponse(
            $user,
            $validated['device_name'] ?? null,
        );
    }

    protected function validateRequest(Request $request): array
    {
        $validator = Validator::make($request->all(), [
            'id_token' => [
                'required',
                'string',
            ],
            'device_name' => [
                'nullable',
                'string',
                'max:255',
            ],
        ]);

        if ($validator->fails()) {
            throw new HttpResponseException(
                (new ErrorResource(
                    message: __('auth.validation_error'),
                    status: HttpResponse::HTTP_UNPROCESSABLE_ENTITY,
                    errors: $validator->errors()->toArray(),
                ))->response()
            );
        }

        return $validator->validated();
    }

    protected function isValidGooglePayload(?array $payload): bool
    {
        if (
            !$payload ||
            empty($payload['sub']) ||
            empty($payload['email'])
        ) {
            return false;
        }

        return filter_var(
            $payload['email_verified'] ?? false,
            FILTER_VALIDATE_BOOL,
        );
    }

    protected function findOrCreateUser(array $payload): User
    {
        $googleId = (string) $payload['sub'];
        $email = Str::lower(
            trim((string) $payload['email'])
        );
        $name = trim((string) ($payload['name'] ?? ''));
        $avatarUrl = isset($payload['picture'])
            ? (string) $payload['picture']
            : null;

        return DB::transaction(function () use (
            $googleId,
            $email,
            $name,
            $avatarUrl,
        ): User {
            $socialAccount = SocialAccount::query()
                ->where('provider', 'google')
                ->where('provider_user_id', $googleId)
                ->lockForUpdate()
                ->first();

            if ($socialAccount) {
                $socialAccount->forceFill([
                    'email' => $email,
                    'avatar_url' => $avatarUrl,
                ])->save();

                return $socialAccount->user;
            }

            $user = User::query()
                ->where('email', $email)
                ->lockForUpdate()
                ->first();

            if (!$user) {
                $user = new User();

                $user->forceFill([
                    'name' => $name !== ''
                        ? $name
                        : Str::before($email, '@'),
                    'email' => $email,
                    'email_verified_at' => now(),
                    'mobile' => null,
                    'password' => null,
                ])->save();
            } elseif (is_null($user->email_verified_at)) {
                $user->forceFill([
                    'email_verified_at' => now(),
                ])->save();
            }

            $socialAccount = new SocialAccount();

            $socialAccount->forceFill([
                'user_id' => $user->id,
                'provider' => 'google',
                'provider_user_id' => $googleId,
                'email' => $email,
                'avatar_url' => $avatarUrl,
            ])->save();

            return $user;
        });
    }

    protected function safeUser(User $user): array
    {
        $data = $user
            ->makeHidden([
                'password',
                'remember_token',
                'partner_id',
                'bio',
                'headline',
            ])
            ->toArray();

        $data['avatar_url'] = $user->avatar_url;
        $data['avatar_path'] = $user->avatar_url;

        return $data;
    }

    protected function activePlanPayload(?User $user): array
    {
        if (!$user) {
            return $this->inactivePlanPayload();
        }

        $subscription = $user->activeSubscription()
            ->with('plan')
            ->first();

        if (!$subscription || !$subscription->plan) {
            return $this->inactivePlanPayload();
        }

        return [
            'is_active' => true,
            'remaining_days' => max(
                now()->diffInDays($subscription->expires_at, false),
                0,
            ),
            'expires_at' => formattedDate($subscription->expires_at),
            'plan' => [
                'slug' => $subscription->plan->slug,
                'title' => $subscription->plan->title,
            ],
        ];
    }

    protected function inactivePlanPayload(): array
    {
        return [
            'is_active' => false,
            'remaining_days' => 0,
            'expires_at' => null,
            'plan' => null,
        ];
    }

    protected function authPayload(
        User $user,
        ?string $token = null,
    ): array {
        $payload = [
            'user' => $this->safeUser($user),
            'active_plan' => $this->activePlanPayload($user),
        ];

        if ($token !== null) {
            $payload['token'] = $token;
        }

        return $payload;
    }

    protected function createAuthToken(
        User $user,
        ?string $deviceName = null,
    ): string {
        return $user
            ->createToken($deviceName ?: 'api')
            ->plainTextToken;
    }

    protected function authResponse(
        User $user,
        ?string $deviceName = null,
    ): SuccessResource {
        $token = $this->createAuthToken($user, $deviceName);

        return new SuccessResource(
            message: __('auth.login_success'),
            resource: $this->authPayload($user, $token),
        );
    }
}
