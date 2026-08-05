<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\IcalBuilder;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

class CalendarFeedController extends Controller
{
    /**
     * Return the public calendar feed identified by its opaque bearer token.
     */
    public function feed(string $token): Response
    {
        $user = User::query()->where('feed_token', $token)->firstOrFail();

        $meetings = $user->subscribedClasses()
            ->with('meetings.classroom.teacher')
            ->get()
            ->flatMap(fn ($class) => $class->meetings);

        return new Response(
            app(IcalBuilder::class)->buildMany($meetings),
            200,
            new CalendarFeedHeaderBag([
                'Content-Type' => 'text/calendar; charset=utf-8',
                'Content-Disposition' => 'inline; filename="calendar.ics"',
                'Cache-Control' => 'no-store, max-age=0',
                'Pragma' => 'no-cache',
            ]),
        );
    }
}

/**
 * Preserves the public feed's exact cache contract without Symfony's default
 * private directive, which would be inappropriate for a bearer-token URL.
 */
final class CalendarFeedHeaderBag extends ResponseHeaderBag
{
    public function set(string $key, string|array|null $values, bool $replace = true): void
    {
        if (strtolower($key) !== 'cache-control') {
            parent::set($key, $values, $replace);

            return;
        }

        $key = strtr($key, self::UPPER, self::LOWER);
        $values = is_array($values) ? array_values($values) : [$values];
        $this->headers[$key] = $replace || ! isset($this->headers[$key])
            ? $values
            : array_merge($this->headers[$key], $values);
        $this->headerNames[$key] = 'Cache-Control';
        $this->cacheControl = $this->parseCacheControl(implode(', ', $this->headers[$key]));
    }
}
