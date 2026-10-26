<?php

declare(strict_types=1);

namespace App\Shared\Mail;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Throwable;

/**
 * A moment as an email states it: in the person's own time zone, with the same moment in UTC beside it, so it can be
 * compared with a log or a colleague's.
 */
final class MailTime
{
    public static function format(DateTimeInterface $moment, string $timezone): string
    {
        $utc = DateTimeImmutable::createFromInterface($moment)->setTimezone(new DateTimeZone('UTC'));

        try {
            $zone = new DateTimeZone($timezone);
        } catch (Throwable) {
            $zone = new DateTimeZone('UTC');
        }

        if ($zone->getName() === 'UTC') {
            return $utc->format('j M Y, H:i').' UTC';
        }

        $local = $utc->setTimezone($zone);
        $offset = $local->format('P');

        if ($offset === '+00:00') {
            return $local->format('j M Y, H:i').' UTC';
        }

        [$hours, $minutes] = explode(':', substr($offset, 1));
        $label = 'UTC'.$offset[0].(int) $hours.($minutes === '00' ? '' : ':'.$minutes);

        return $local->format('j M Y, H:i').' '.$label.' ('.$utc->format('H:i').' UTC)';
    }
}
