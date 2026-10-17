<?php

namespace Tests\Unit;

use App\Support\PhilippineMobileNumber;
use PHPUnit\Framework\TestCase;

class PhilippineMobileNumberTest extends TestCase
{
    public function test_it_normalizes_local_and_international_philippine_mobile_numbers(): void
    {
        self::assertSame('+639171234567', PhilippineMobileNumber::normalize('0917 123 4567'));
        self::assertSame('+639171234567', PhilippineMobileNumber::normalize('+63 917-123-4567'));
        self::assertSame('+639171234567', PhilippineMobileNumber::normalize('639171234567'));
    }

    public function test_it_formats_the_local_display_and_rejects_invalid_characters(): void
    {
        self::assertSame('0900 000 0000', PhilippineMobileNumber::format('09000000000'));
        self::assertSame('0900 000 0000', PhilippineMobileNumber::format('+639000000000'));
        self::assertNull(PhilippineMobileNumber::normalize('0917ABC4567'));
        self::assertNull(PhilippineMobileNumber::normalize('0912345678'));
    }
}
