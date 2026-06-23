<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Dto\RunInput;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Validation;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class RunInputValidationTest extends TestCase
{
    private ValidatorInterface $validator;

    #[\Override]
    protected function setUp(): void
    {
        $this->validator = Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator();
    }

    public function testValidDatePasses(): void
    {
        self::assertCount(0, $this->validator->validate(new RunInput('2025-04-15')));
    }

    public function testInvalidDateIsRejected(): void
    {
        self::assertGreaterThan(0, $this->validator->validate(new RunInput('not-a-date'))->count());
    }

    public function testBlankDateIsRejected(): void
    {
        self::assertGreaterThan(0, $this->validator->validate(new RunInput(''))->count());
    }
}
