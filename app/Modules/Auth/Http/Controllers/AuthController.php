<?php

namespace App\Modules\Auth\Http\Controllers;

use Cryptommer\Smsir\Objects\Parameters;
use Cryptommer\Smsir\Smsir;
use App\Http\Controllers\Controller;
use App\Http\Resources\ErrorResource;
use App\Http\Resources\SuccessResource;
use App\Models\User;
use App\Modules\Auth\Models\SmsLog;
use Carbon\Carbon;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Medisa\Subscription\Actions\CreateFreeTrialSubscriptionAction;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

class AuthController extends Controller
{
    public const MAX_COUNT_SMS_PER_DAY = 50;
    public const OTP_TTL_MINUTES = 5;
    public const SMS_LOCK_SECONDS = 10;
    public const SMS_RESEND_COOLDOWN_SECONDS = 60;

    protected function normalizeMobile(string $mobile): string
    {
        return convertPersianToEnglish($mobile);
    }

    protected function validateWithNormalizedMobile(
        Request $request,
        array $rules,
    ): array {
        $data = $request->all();

        if (isset($data['mobile'])) {
            $data['mobile'] = $this->normalizeMobile($data['mobile']);
        }

        $validator = Validator::make($data, $rules);

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

    protected function getLatestValidOtp(string $mobile): ?SmsLog
    {
        return SmsLog::query()
            ->where('mobile', $mobile)
            ->whereNull('used_at')
            ->where(
                'created_at',
                '>=',
                now()->subMinutes(self::OTP_TTL_MINUTES),
            )
            ->latest()
            ->first();
    }

    protected function markOtpAsUsed(SmsLog $otp): void
    {
        SmsLog::query()
            ->whereKey($otp->id)
            ->whereNull('used_at')
            ->update([
                'used_at' => now(),
            ]);
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

    /*
    |--------------------------------------------------------------------------
    | Identify Flow
    |--------------------------------------------------------------------------
    */

    public function identify(Request $request): SuccessResource
    {
        $validated = $this->validateWithNormalizedMobile($request, [
            'mobile' => [
                'required',
                'regex:/^(09)\d{9}$/',
            ],
        ]);

        $user = User::query()
            ->where('mobile', $validated['mobile'])
            ->first();

        if (!$user) {
            return new SuccessResource(
                message: 'OK',
                resource: [
                    'next_step' => 'otp',
                    'user_exists' => false,
                    'has_password' => false,
                    'available_actions' => [
                        'register_with_otp',
                    ],
                ],
            );
        }

        if (empty($user->password)) {
            return new SuccessResource(
                message: 'OK',
                resource: [
                    'next_step' => 'otp',
                    'user_exists' => true,
                    'has_password' => false,
                    'available_actions' => [
                        'login_with_otp',
                        'set_password',
                    ],
                ],
            );
        }

        return new SuccessResource(
            message: 'OK',
            resource: [
                'next_step' => 'password',
                'user_exists' => true,
                'has_password' => true,
                'available_actions' => [
                    'login_with_password',
                    'login_with_otp',
                    'forgot_password',
                ],
            ],
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Send OTP
    |--------------------------------------------------------------------------
    */

    public function sendCode(Request $request): SuccessResource
    {
        $validated = $this->validateWithNormalizedMobile($request, [
            'mobile' => [
                'required',
                'regex:/^(09)\d{9}$/',
            ],
            'purpose' => [
                'nullable',
                'string',
                'in:login,register,reset_password,set_password',
            ],
        ]);

        $this->requestCodeInternal(
            $request,
            $validated['mobile'],
        );

        return new SuccessResource(
            message: __('auth.code_sent'),
            resource: [
                'ttl' => self::OTP_TTL_MINUTES * 60,
                'purpose' => $validated['purpose'] ?? 'login',
            ],
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Verify OTP
    |--------------------------------------------------------------------------
    */

    public function verifyCode(
        Request $request,
    ): SuccessResource|ErrorResource {
        $validated = $this->validateWithNormalizedMobile($request, [
            'mobile' => [
                'required',
                'regex:/^(09)\d{9}$/',
            ],
            'code' => [
                'required',
                'digits:4',
            ],
            'purpose' => [
                'nullable',
                'string',
                'in:login,register',
            ],
            'device_name' => [
                'nullable',
                'string',
                'max:255',
            ],
        ]);

        $mobile = $validated['mobile'];
        $code = $validated['code'];
        $purpose = $validated['purpose'] ?? 'login';

        $otp = $this->getLatestValidOtp($mobile);

        if (!$otp || (string) $otp->code !== (string) $code) {
            return new ErrorResource(
                message: __('auth.invalid_or_expired_code'),
                status: HttpResponse::HTTP_BAD_REQUEST,
            );
        }

        $user = User::query()
            ->where('mobile', $mobile)
            ->first();

        if (!$user) {
            return new SuccessResource(
                message: 'OK',
                resource: [
                    'next_step' => 'register',
                    'mobile' => $mobile,
                ],
            );
        }

        if ($purpose === 'register') {
            $hasPassword = !empty($user->password);

            return new SuccessResource(
                message: 'OK',
                resource: [
                    'next_step' => $hasPassword
                        ? 'password'
                        : 'otp',
                    'user_exists' => true,
                    'has_password' => $hasPassword,
                    'available_actions' => $hasPassword
                        ? [
                            'login_with_password',
                            'login_with_otp',
                            'forgot_password',
                        ]
                        : [
                            'login_with_otp',
                            'set_password',
                        ],
                ],
            );
        }

        DB::transaction(function () use ($otp, $user): void {
            $this->markOtpAsUsed($otp);

            if (is_null($user->mobile_verified_at)) {
                $user->forceFill([
                    'mobile_verified_at' => now(),
                ])->save();
            }
        });

        return $this->authResponse(
            $user,
            $validated['device_name'] ?? null,
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Register
    |--------------------------------------------------------------------------
    */

    public function register(
        Request $request,
    ): SuccessResource|ErrorResource {
        $validated = $this->validateWithNormalizedMobile($request, [
            'mobile' => [
                'required',
                'regex:/^(09)\d{9}$/',
            ],
            'name' => [
                'required',
                'string',
                'max:255',
            ],
            'code' => [
                'required',
                'digits:4',
            ],
            'device_name' => [
                'nullable',
                'string',
                'max:255',
            ],
        ]);

        $mobile = $validated['mobile'];
        $otp = $this->getLatestValidOtp($mobile);

        if (
            !$otp ||
            (string) $otp->code !== (string) $validated['code']
        ) {
            return new ErrorResource(
                message: __('auth.invalid_or_expired_code'),
                status: HttpResponse::HTTP_BAD_REQUEST,
            );
        }

        $userExists = User::query()
            ->where('mobile', $mobile)
            ->exists();

        if ($userExists) {
            return new ErrorResource(
                message: __('auth.mobile_already_registered'),
                status: HttpResponse::HTTP_CONFLICT,
            );
        }

        $user = DB::transaction(
            function () use ($validated, $mobile, $otp): User {
                $this->markOtpAsUsed($otp);

                $user = User::query()->create([
                    'mobile' => $mobile,
                    'name' => $validated['name'],
                    'password' => null,
                ]);

                $user->forceFill([
                    'mobile_verified_at' => now(),
                ])->save();

                return $user;
            }
        );

        app(CreateFreeTrialSubscriptionAction::class)
            ->execute($user);

        $token = $this->createAuthToken(
            $user,
            $validated['device_name'] ?? null,
        );

        return new SuccessResource(
            message: __('auth.register_success'),
            resource: $this->authPayload(
                $user->fresh(),
                $token,
            ),
            status: HttpResponse::HTTP_CREATED,
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Login with Password
    |--------------------------------------------------------------------------
    */

    public function loginWithPassword(
        Request $request,
    ): SuccessResource|ErrorResource {
        $validated = $this->validateWithNormalizedMobile($request, [
            'mobile' => [
                'required',
                'regex:/^(09)\d{9}$/',
            ],
            'password' => [
                'required',
                'string',
            ],
            'device_name' => [
                'nullable',
                'string',
                'max:255',
            ],
        ]);

        $user = User::query()
            ->where('mobile', $validated['mobile'])
            ->first();

        $credentialsAreInvalid =
            !$user ||
            empty($user->password) ||
            !Hash::check(
                $validated['password'],
                $user->password,
            );

        if ($credentialsAreInvalid) {
            return new ErrorResource(
                message: __('auth.invalid_credentials'),
                status: HttpResponse::HTTP_UNAUTHORIZED,
            );
        }

        return $this->authResponse(
            $user,
            $validated['device_name'] ?? null,
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Set Password / Reset Password
    |--------------------------------------------------------------------------
    */

    public function setPassword(
        Request $request,
    ): SuccessResource|ErrorResource {
        $validated = $this->validateWithNormalizedMobile($request, [
            'mobile' => [
                'required',
                'regex:/^(09)\d{9}$/',
            ],
            'code' => [
                'required',
                'digits:4',
            ],
            'new_password' => [
                'required',
                'string',
                'min:6',
                'confirmed',
            ],
        ]);

        $mobile = $validated['mobile'];
        $otp = $this->getLatestValidOtp($mobile);

        if (
            !$otp ||
            (string) $otp->code !== (string) $validated['code']
        ) {
            return new ErrorResource(
                message: __('auth.invalid_or_expired_code'),
                status: HttpResponse::HTTP_BAD_REQUEST,
            );
        }

        $user = User::query()
            ->where('mobile', $mobile)
            ->first();

        if (!$user) {
            return new ErrorResource(
                message: __('auth.invalid_credentials'),
                status: HttpResponse::HTTP_UNAUTHORIZED,
            );
        }

        DB::transaction(function () use (
            $user,
            $validated,
            $otp,
        ): void {
            $this->markOtpAsUsed($otp);

            $user->forceFill([
                'password' => Hash::make(
                    $validated['new_password']
                ),
                'mobile_verified_at' => $user->mobile_verified_at
                    ?? now(),
            ])->save();
        });

        return new SuccessResource(
            message: __('auth.password_changed'),
            resource: [
                'next_step' => 'password',
                'available_actions' => [
                    'login_with_password',
                    'login_with_otp',
                    'forgot_password',
                ],
            ],
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Me
    |--------------------------------------------------------------------------
    */

    public function me(Request $request): SuccessResource
    {
        $user = $request->user();

        return new SuccessResource(
            message: 'OK',
            resource: [
                'user' => $user
                    ? $this->safeUser($user)
                    : null,
                'subscription' => $this->activePlanPayload($user),
            ],
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Logout
    |--------------------------------------------------------------------------
    */

    public function logout(Request $request): SuccessResource
    {
        $request->user()
            ?->currentAccessToken()
            ?->delete();

        return new SuccessResource(
            message: __('auth.logged_out'),
            resource: null,
        );
    }

    public function logoutAll(Request $request): SuccessResource
    {
        $request->user()
            ?->tokens()
            ->delete();

        return new SuccessResource(
            message: __('auth.logged_out'),
            resource: null,
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Internal
    |--------------------------------------------------------------------------
    */

    protected function requestCodeInternal(
        Request $request,
        string $mobile,
    ): void {
        $lock = Cache::lock(
            "sms:lock:$mobile",
            self::SMS_LOCK_SECONDS,
        );

        if (!$lock->get()) {
            throw new HttpResponseException(
                (new ErrorResource(
                    message: __('auth.cooldown'),
                    status: HttpResponse::HTTP_TOO_MANY_REQUESTS,
                ))->response()
            );
        }

        try {
            $lastSms = SmsLog::query()
                ->where('mobile', $mobile)
                ->latest()
                ->first();

            if ($lastSms) {
                $secondsSinceLastSms = Carbon::now()
                    ->diffInSeconds($lastSms->created_at);

                if (
                    $secondsSinceLastSms <
                    self::SMS_RESEND_COOLDOWN_SECONDS
                ) {
                    throw new HttpResponseException(
                        (new ErrorResource(
                            message: __('auth.cooldown'),
                            status: HttpResponse::HTTP_TOO_MANY_REQUESTS,
                        ))->response()
                    );
                }
            }

            $todaySmsCount = SmsLog::query()
                ->where('mobile', $mobile)
                ->whereDate('created_at', Carbon::today())
                ->count();

            if ($todaySmsCount >= self::MAX_COUNT_SMS_PER_DAY) {
                throw new HttpResponseException(
                    (new ErrorResource(
                        message: __('auth.daily_limit'),
                        status: HttpResponse::HTTP_TOO_MANY_REQUESTS,
                    ))->response()
                );
            }

            $code = random_int(1000, 9999);

            $log = SmsLog::query()->create([
                'mobile' => $mobile,
                'ip' => $request->ip(),
                'code' => $code,
            ]);

            $this->sendSms($log);
        } finally {
            $lock->release();
        }
    }

protected function sendSms(SmsLog $log): void
{
    $templateId = config('services.smsir.verify_template_id');

    if (!$templateId) {
        throw new \RuntimeException(
            'SMS.ir verification template ID is not configured.'
        );
    }

    Smsir::Send()->Verify(
        $log->mobile,
        (int) $templateId,
        [
            new Parameters(
                'CODE',
                (string) $log->code,
            ),
        ],
    );
}
}