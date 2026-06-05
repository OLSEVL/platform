<?php

namespace Tests\Unit;

use App\Support\MartingaleAmountsParser;
use Tests\TestCase;

class MartingaleAmountsParserTest extends TestCase
{
    public function test_parses_comma_separated_list(): void
    {
        $r = MartingaleAmountsParser::parse('1, 2, 2, 4, 8, 10');
        $this->assertNull($r['error']);
        $this->assertSame([1.0, 2.0, 2.0, 4.0, 8.0, 10.0], $r['amounts']);
    }

    public function test_accepts_dot_decimal(): void
    {
        $r = MartingaleAmountsParser::parse('1, 2.5, 10');
        $this->assertNull($r['error']);
        $this->assertSame([1.0, 2.5, 10.0], $r['amounts']);
    }

    public function test_rejects_out_of_range(): void
    {
        $r = MartingaleAmountsParser::parse('0.5, 2');
        $this->assertNotNull($r['error']);
    }
}
