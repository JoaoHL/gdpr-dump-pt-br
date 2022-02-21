<?php

declare(strict_types=1);

namespace Smile\GdprDump\Tests\Unit\Converter\Generator;

use Smile\GdprDump\Converter\Generator\SetValue;
use Smile\GdprDump\Converter\Parameters\ValidationException;
use Smile\GdprDump\Tests\Unit\TestCase;

class SetValueTest extends TestCase
{
    /**
     * Test the converter.
     */
    public function testConverter(): void
    {
        // Assert that empty values are allowed
        $this->assertValueIsSet(null);
        $this->assertValueIsSet(false);
        $this->assertValueIsSet('');
        $this->assertValueIsSet('0');
        $this->assertValueIsSet(0);

        // Assert that non-empty values are allowed
        $this->assertValueIsSet(true);
        $this->assertValueIsSet('notAnonymized');
        $this->assertValueIsSet(-1);
        $this->assertValueIsSet(10.2);
    }

    /**
     * Assert that an exception is thrown when the value is not set.
     */
    public function testValueNotSet(): void
    {
        $this->expectException(ValidationException::class);
        new SetValue([]);
    }

    /**
     * Assert that an exception is thrown when the value is not a scalar and not null.
     */
    public function testValueNotScalar(): void
    {
        $this->expectException(ValidationException::class);
        new SetValue(['value' => ['1']]);
    }

    /**
     * Assert that the converter generates the specified value.
     *
     * @param mixed $value
     */
    private function assertValueIsSet($value): void
    {
        $converter = new SetValue(['value' => $value]);
        $this->assertSame($value, $converter->convert($value));
    }
}
