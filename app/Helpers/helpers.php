<?php

use App\Http\Classes\WhiteSms;
use App\Models\Author;
use App\Models\BookStock;
use App\Models\Cart;
use App\Models\Category;
use App\Models\WhiteSmsLog;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Hekmatinasser\Verta\Verta;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

if (! function_exists('formattedDate')) {
    function formattedDate(mixed $data): ?string
    {
        if (blank($data)) {
            return null;
        }

        $date = $data instanceof CarbonInterface
            ? $data
            : Carbon::parse($data);

        if ($date->diffInDays(now()) < 14) {
            return numberToWordFa($date->diffForHumans());
        }

        $verta = Verta::instance($date);
        $currentVerta = Verta::instance(now());

        return $verta->year === $currentVerta->year
            ? $verta->format('%d %B')
            : $verta->format('%d %B %Y');
    }
}

if (! function_exists('numberToWordFa')) {
    function numberToWordFa(?string $text): ?string
    {
        if ($text === null || $text === '') {
            return $text;
        }

        $numbers = [
            '1' => 'یک',
            '2' => 'دو',
            '3' => 'سه',
            '4' => 'چهار',
            '5' => 'پنج',
            '6' => 'شش',
            '7' => 'هفت',
            '8' => 'هشت',
            '9' => 'نه',
            '10' => 'ده',
            '۱' => 'یک',
            '۲' => 'دو',
            '۳' => 'سه',
            '۴' => 'چهار',
            '۵' => 'پنج',
            '۶' => 'شش',
            '۷' => 'هفت',
            '۸' => 'هشت',
            '۹' => 'نه',
            '۱۰' => 'ده',
        ];

        return preg_replace_callback(
            '/[0-9۰-۹]+/u',
            static fn (array $matches): string => $numbers[$matches[0]] ?? $matches[0],
            $text
        );
    }
}

if (! function_exists('toPng')) {
    function toPng(string $path): string
    {
        $info = pathinfo($path);
        $directory = $info['dirname'] ?? '';
        $filename = $info['filename'] ?? '';

        $prefix = $directory !== '' && $directory !== '.'
            ? rtrim($directory, '/\\') . '/'
            : '';

        return $prefix . $filename . '.png';
    }
}

if (! function_exists('stream')) {
    function stream(?string $url): ?string
    {
        $url = trim((string) $url);

        if ($url === '') {
            return null;
        }

        if (str_contains($url, '/api/stream/')) {
            return $url;
        }

        $path = parse_url($url, PHP_URL_PATH) ?: $url;
        $path = '/' . ltrim($path, '/');

        if (! str_starts_with($path, '/storage/') && ! str_starts_with($path, '/media/')) {
            return $url;
        }

        $encodedPath = rtrim(strtr(base64_encode($path), '+/', '-_'), '=');

        return url("/api/stream/{$encodedPath}");
    }
}

if (! function_exists('getUserAvatar')) {
    function getUserAvatar(mixed $user): string
    {
        $default = '/storage/uploads/users/_default.jpg';
        $avatar = checkImage(data_get($user, 'avatar_path'), $default);

        return filter_var($avatar, FILTER_VALIDATE_URL)
            ? $avatar
            : asset($avatar);
    }
}

if (! function_exists('project_config')) {
    function project_config(?string $key = null, mixed $default = null): mixed
    {
        $projectKey = config('project.current')
            ?? config('app.project')
            ?? env('APP_PROJECT', 'ravangosha');

        $projectConfig = config("project.{$projectKey}", []);

        return blank($key)
            ? $projectConfig
            : data_get($projectConfig, $key, $default);
    }
}

if (! function_exists('convertPersianToEnglish')) {
    function convertPersianToEnglish(string $string): string
    {
        return strtr($string, [
            '۰' => '0',
            '۱' => '1',
            '۲' => '2',
            '۳' => '3',
            '۴' => '4',
            '۵' => '5',
            '۶' => '6',
            '۷' => '7',
            '۸' => '8',
            '۹' => '9',
        ]);
    }
}

if (! function_exists('createSlug')) {
    function createSlug(string $string): string
    {
        return Str::slug($string);
    }
}

if (! function_exists('calculateDurationCourse')) {
    function calculateDurationCourse(mixed $course): string
    {
        $totalSeconds = collect(data_get($course, 'arvanVideos', []))
            ->sum(static fn (mixed $video): int => max(0, (int) data_get($video, 'duration', 0)));

        $hours = intdiv($totalSeconds, 3600);
        $minutes = intdiv($totalSeconds % 3600, 60);
        $seconds = $totalSeconds % 60;

        return sprintf('%d:%02d:%02d', $hours, $minutes, $seconds);
    }
}

if (! function_exists('getStudyTime')) {
    function getStudyTime(?string $str): int
    {
        $text = trim(strip_tags((string) $str));

        if ($text === '') {
            return 0;
        }

        $words = preg_split('/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return max(1, (int) ceil(count($words) / 160));
    }
}

if (! function_exists('getPath')) {
    function getPath(?string $url): string
    {
        return preg_replace(
            '~^https?://(?:www\.)?ravangosha\.net~i',
            '',
            (string) $url
        ) ?? (string) $url;
    }
}

if (! function_exists('canReLogin')) {
    function canReLogin(): bool
    {
        $sessionToken = request()->session()->get('reLoginToken');
        $cookieToken = request()->cookie('reLoginToken');

        return filled($sessionToken)
            && filled($cookieToken)
            && hash_equals((string) $sessionToken, (string) $cookieToken);
    }
}

if (! function_exists('getUserRole')) {
    function getUserRole(): string
    {
        $user = Auth::user();

        if (! $user) {
            return 'student';
        }

        if ($user->hasRole('admin')) {
            return 'admin';
        }

        if ($user->hasRole('instructor')) {
            return 'instructor';
        }

        return 'student';
    }
}

if (! function_exists('excerpt')) {
    function excerpt(?string $title, int $cutOffLength = 120): string
    {
        $title = trim(strip_tags((string) $title));

        return Str::limit($title, max(0, $cutOffLength), '...');
    }
}

if (! function_exists('getRedirectPathAfterPayment')) {
    function getRedirectPathAfterPayment(): string
    {
        return '/user/appointments';
    }
}

if (! function_exists('canIWatchThisVideo')) {
    function canIWatchThisVideo(mixed $video): bool
    {
        return Auth::check() && (bool) data_get($video, 'course.enrolled', false);
    }
}

if (! function_exists('getDurationVideo')) {
    function getDurationVideo(string $path): float
    {
        $fullVideoPath = public_path(ltrim($path, '/'));

        if (! is_file($fullVideoPath) || ! class_exists(\getID3::class)) {
            return 0;
        }

        try {
            $fileInfo = (new \getID3())->analyze($fullVideoPath);

            return (float) ($fileInfo['playtime_seconds'] ?? 0);
        } catch (Throwable $exception) {
            Log::warning('Unable to read video duration.', [
                'path' => $fullVideoPath,
                'message' => $exception->getMessage(),
            ]);

            return 0;
        }
    }
}

if (! function_exists('roundStar')) {
    function roundStar(int|float $number): float
    {
        return round($number / 5, 1) * 5;
    }
}

if (! function_exists('toggleTopic')) {
    function toggleTopic(mixed $topic, int|string $videoId): ?string
    {
        $containsVideo = collect(data_get($topic, 'videos', []))
            ->contains(static fn (mixed $video): bool =>
                (string) data_get($video, 'id') === (string) $videoId
            );

        return $containsVideo ? 'show' : null;
    }
}

if (! function_exists('toggleVideo')) {
    function toggleVideo(mixed $video, int|string $videoId): string
    {
        return (string) data_get($video, 'id') === (string) $videoId
            ? 'btn-success-soft'
            : 'btn-danger-soft';
    }
}

if (! function_exists('getAuthors')) {
    function getAuthors(int $limit)
    {
        return Author::query()
            ->limit(max(0, $limit))
            ->get();
    }
}

if (! function_exists('getCategories')) {
    function getCategories(int $limit)
    {
        return Category::query()
            ->limit(max(0, $limit))
            ->get();
    }
}

if (! function_exists('getAuthorById')) {
    function getAuthorById(int|string $id): ?string
    {
        return Author::query()->find($id)?->name;
    }
}

if (! function_exists('getCategoryById')) {
    function getCategoryById(int|string $id): ?string
    {
        return Category::query()->find($id)?->title;
    }
}

if (! function_exists('isBookAvailable')) {
    function isBookAvailable(int|string $bookId): mixed
    {
        return BookStock::isBookAvailable($bookId);
    }
}

if (! function_exists('toman')) {
    function toman(int|float|null $amount): string
    {
        return number_format((float) ($amount ?? 0)) . ' تومان';
    }
}

if (! function_exists('getTotalQuantity')) {
    function getTotalQuantity(): mixed
    {
        return Cart::totalQuantity();
    }
}

if (! function_exists('getTotalPrice')) {
    function getTotalPrice(): mixed
    {
        return Cart::totalPrice();
    }
}

if (! function_exists('carts')) {
    function carts(): mixed
    {
        return Auth::user()?->carts ?? [];
    }
}

if (! function_exists('isActiveMenu')) {
    function isActiveMenu(string $path): ?string
    {
        $currentPath = '/' . ltrim(request()->path(), '/');
        $targetPath = '/' . ltrim($path, '/');

        return rtrim($currentPath, '/') === rtrim($targetPath, '/')
            ? 'active'
            : null;
    }
}

if (! function_exists('convertToPercentage')) {
    function convertToPercentage(int|float $number, int|float $from): float
    {
        if ((float) $from === 0.0) {
            return 0.0;
        }

        return round(($number * 100) / $from, 1);
    }
}

if (! function_exists('convertToFullCalendarFormat')) {
    function convertToFullCalendarFormat(iterable $data): array
    {
        $events = [];

        foreach ($data as $item) {
            $events[] = [
                'id' => data_get($item, 'id'),
                'start' => data_get($item, 'available_date') . 'T' . data_get($item, 'start_time'),
                'end' => data_get($item, 'available_date') . 'T' . data_get($item, 'end_time'),
            ];
        }

        return $events;
    }
}

if (! function_exists('toObject')) {
    function toObject(array $Array): object
    {
        $object = new stdClass();

        foreach ($Array as $key => $value) {
            $object->{$key} = is_array($value)
                ? toObject($value)
                : $value;
        }

        return $object;
    }
}

if (! function_exists('DisplayDateFa')) {
    function DisplayDateFa(mixed $datetime): string
    {
        $date = Carbon::parse($datetime);

        if ($date->isToday()) {
            return 'امروز';
        }

        if ($date->isTomorrow()) {
            return 'فردا';
        }

        $dayName = match ($date->dayOfWeek) {
            Carbon::SUNDAY => 'یکشنبه',
            Carbon::MONDAY => 'دوشنبه',
            Carbon::TUESDAY => 'سه‌شنبه',
            Carbon::WEDNESDAY => 'چهارشنبه',
            Carbon::THURSDAY => 'پنجشنبه',
            Carbon::FRIDAY => 'جمعه',
            Carbon::SATURDAY => 'شنبه',
        };

        return $dayName . ' ' . convertDateToShamsi($date);
    }
}

if (! function_exists('convertDateToShamsi')) {
    function convertDateToShamsi(mixed $date): string
    {
        return verta($date)->format('Y/n/j');
    }
}

if (! function_exists('convertDateToShamsiWithTime')) {
    function convertDateToShamsiWithTime(mixed $date): string
    {
        return verta($date)->format('Y-m-d H:i:s');
    }
}

if (! function_exists('sendWhiteSms')) {
    function sendWhiteSms(string $mobile, int|string $templateId, array $params): void
    {
        $sms = new WhiteSms();
        $sms->setMobile($mobile);
        $sms->setId($templateId);

        foreach ($params as $key => $value) {
            $sms->setParam($key, $value);
        }

        $sms->send();

        WhiteSmsLog::query()->create([
            'template_id' => $templateId,
            'mobile' => $mobile,
            'params' => $params,
        ]);
    }
}

if (! function_exists('adaptPersianDatePicker')) {
    function adaptPersianDatePicker(int|string $input): string
    {
        return verta((int) $input / 1000)->formatGregorian('Y-m-d H:i:s');
    }
}

if (! function_exists('adaptPersianDatePickerDate')) {
    function adaptPersianDatePickerDate(int|string $input): string
    {
        return verta((int) $input / 1000)->formatGregorian('Y-m-d');
    }
}

if (! function_exists('checkImage')) {
    function checkImage(?string $path, string $default = '/storage/defaults/user.png'): string
    {
        if (blank($path)) {
            return $default;
        }

        if (filter_var($path, FILTER_VALIDATE_URL)) {
            return $path;
        }

        $publicPath = parse_url($path, PHP_URL_PATH) ?: $path;
        $fullPath = public_path(ltrim($publicPath, '/'));

        return is_file($fullPath) ? $path : $default;
    }
}

if (! function_exists('checkFile')) {
    function checkFile(?string $path): string|false
    {
        if (blank($path)) {
            return false;
        }

        if (filter_var($path, FILTER_VALIDATE_URL)) {
            return $path;
        }

        $publicPath = parse_url($path, PHP_URL_PATH) ?: $path;

        return is_file(public_path(ltrim($publicPath, '/')))
            ? $path
            : false;
    }
}

if (! function_exists('adaptHaDateTime')) {
    function adaptHaDateTime(string $input): string
    {
        try {
            [$date, $time] = array_pad(
                preg_split('/\s+/u', trim($input), 2) ?: [],
                2,
                null
            );

            if (! $date || ! $time) {
                throw new InvalidArgumentException('Date and time are required.');
            }

            $dateComponents = array_map('intval', explode('/', $date));

            if (count($dateComponents) !== 3) {
                throw new InvalidArgumentException('Invalid Jalali date format.');
            }

            [$year, $month, $day] = $dateComponents;

            $gregorianDate = Verta::createJalali($year, $month, $day)->datetime();

            return $gregorianDate->format('Y-m-d') . ' ' . $time;
        } catch (Throwable $exception) {
            Log::error('Date parsing error.', [
                'input' => $input,
                'message' => $exception->getMessage(),
            ]);

            throw new RuntimeException("Error parsing the date: {$input}", 0, $exception);
        }
    }
}

if (! function_exists('persianDatePickerToGregorian')) {
    function persianDatePickerToGregorian(string $shamsiDate): string
    {
        return Verta::parse($shamsiDate)
            ->datetime()
            ->format('Y-m-d H:i:s');
    }
}

if (! function_exists('getDateFromDateTime')) {
    function getDateFromDateTime(mixed $dateTimeString): string
    {
        return Carbon::parse($dateTimeString)->format('Y-m-d');
    }
}

if (! function_exists('getTimeFromDateTime')) {
    function getTimeFromDateTime(mixed $dateTimeString): string
    {
        return Carbon::parse($dateTimeString)->format('H:i');
    }
}

if (! function_exists('addMinutesToTime')) {
    function addMinutesToTime(string $timeString, int $addMinutes = 45): string
    {
        return Carbon::createFromFormat('H:i', $timeString)
            ->addMinutes($addMinutes)
            ->format('H:i');
    }
}