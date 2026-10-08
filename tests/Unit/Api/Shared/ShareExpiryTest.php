<?php

declare(strict_types=1);

namespace App\Tests\Unit\Api\Shared;

use App\Api\Shared\ShareExpiry;
use Codeception\Test\Unit;

final class ShareExpiryTest extends Unit
{
    public function testNormalizesIsoDateTimeWithMilliseconds(): void
    {
        $expiry = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
            ->modify('+1 day')
            ->format('Y-m-d\TH:i:s.v\Z');

        $this->assertSame(
            (new \DateTimeImmutable($expiry))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
            ShareExpiry::normalize(['expiresAt' => $expiry]),
        );
    }

    public function testNormalizesDateTimeWithOffset(): void
    {
        $this->assertSame(
            '2030-01-01 12:00:00',
            ShareExpiry::normalize(['expiresAt' => '2030-01-01T14:00:00+02:00']),
        );
    }

    public function testAcceptsMissingOrNullExpiry(): void
    {
        $this->assertNull(ShareExpiry::normalize([]));
        $this->assertNull(ShareExpiry::normalize(['expiresAt' => null]));
    }

    public function testRejectsInvalidOrPastExpiry(): void
    {
        $this->assertFalse(ShareExpiry::normalize(['expiresAt' => '2030-02-30T12:00:00Z']));
        $this->assertFalse(ShareExpiry::normalize(['expiresAt' => '2030-01-01T12:00']));
        $this->assertFalse(ShareExpiry::normalize(['expiresAt' => '2000-01-01T12:00:00Z']));
    }
}
