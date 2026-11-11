<?php

namespace Tests\Unit;

use App\Support\TemporaryPassword;
use PHPUnit\Framework\TestCase;

class TemporaryPasswordGeneratorTest extends TestCase
{
    public function test_format_has_no_ambiguous_characters(): void
    {
        foreach (range(1, 200) as $_) {
            $this->assertMatchesRegularExpression('/^[a-hjkmnp-z2-9]{4}(-[a-hjkmnp-z2-9]{4}){2}$/', TemporaryPassword::generate());
        }
    }

    public function test_values_are_unique(): void
    {
        $values = array_map(fn () => TemporaryPassword::generate(), range(1, 500));

        $this->assertCount(500, array_unique($values));
    }
}
