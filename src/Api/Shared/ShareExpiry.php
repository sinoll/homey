<?php

declare(strict_types=1);

namespace App\Api\Shared;

use DateTimeImmutable;
use DateTimeZone;

final class ShareExpiry
{
    public static function normalize(mixed $body): string|null|false
    {
        if (!is_array($body) || !array_key_exists('expiresAt', $body) || $body['expiresAt'] === null || $body['expiresAt'] === '') {
            return null;
        }
        if (
            !is_string($body['expiresAt'])
            || preg_match('/\A\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d(?:\.\d{1,6})?(?:Z|[+-]\d\d:\d\d)\z/', $body['expiresAt']) !== 1
        ) {
            return false;
        }

        try {
            $date = new DateTimeImmutable($body['expiresAt']);
        } catch (\Exception) {
            return false;
        }
        $errors = DateTimeImmutable::getLastErrors();
        if (
            ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
            || $date <= new DateTimeImmutable('now', new DateTimeZone('UTC'))
        ) {
            return false;
        }

        return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }
}
