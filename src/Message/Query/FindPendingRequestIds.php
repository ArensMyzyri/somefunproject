<?php

declare(strict_types=1);

namespace App\Message\Query;

use App\Bus\QueryInterface;

/**
 * Fetch the next page of pending leave request ids, in submission order, for keyset
 * (cursor) pagination — so the dispatcher never loads the whole pending set at once.
 *
 * The cursor is the (submittedAt, id) of the last row of the previous page; both null/0
 * for the first page.
 */
final readonly class FindPendingRequestIds implements QueryInterface
{
    public function __construct(
        public ?\DateTimeImmutable $afterSubmittedAt,
        public int $afterId,
        public int $limit,
    ) {
    }
}
