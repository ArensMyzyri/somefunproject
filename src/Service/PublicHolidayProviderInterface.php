<?php

declare(strict_types=1);

namespace App\Service;

interface PublicHolidayProviderInterface
{
    /**
     * Whether the given date is a public holiday in the given German federal state.
     *
     * @param string $federalState federal state code, e.g. "BY", "BE"
     */
    public function isPublicHoliday(\DateTimeImmutable $date, string $federalState): bool;
}
