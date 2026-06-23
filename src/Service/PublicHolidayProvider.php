<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Public holidays per German federal state, transcribed from docs/LEAVE_POLICY.md §5.
 *
 * Only the states present in the sample data (Bavaria, Berlin) and the 2025 leave
 * year are covered. A real deployment would source these from a maintained holiday
 * service behind {@see PublicHolidayProviderInterface}.
 */
final class PublicHolidayProvider implements PublicHolidayProviderInterface
{
    /** @var array<string, list<string>> federal state code => list of Y-m-d holiday dates */
    private const HOLIDAYS = [
        'BY' => [
            '2025-01-01',
            '2025-01-06',
            '2025-04-18',
            '2025-04-21',
            '2025-05-01',
            '2025-05-29',
            '2025-06-09',
            '2025-06-19',
            '2025-08-15',
            '2025-10-03',
            '2025-11-01',
            '2025-12-25',
            '2025-12-26',
        ],
        'BE' => [
            '2025-01-01',
            '2025-03-08',
            '2025-04-18',
            '2025-04-21',
            '2025-05-01',
            '2025-05-29',
            '2025-06-09',
            '2025-10-03',
            '2025-12-25',
            '2025-12-26',
        ],
    ];

    #[\Override]
    public function isPublicHoliday(\DateTimeImmutable $date, string $federalState): bool
    {
        return \in_array($date->format('Y-m-d'), self::HOLIDAYS[$federalState] ?? [], true);
    }
}
