<?php

declare(strict_types=1);

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Validated input for an absence run: the run date in Y-m-d form.
 */
final readonly class RunInput
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Date(message: 'The run date must be a valid Y-m-d date.')]
        public string $date,
    ) {
    }
}
