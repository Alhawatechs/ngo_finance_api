<?php

namespace Tests\Unit;

use App\Services\AccountCodeScheme;
use PHPUnit\Framework\TestCase;

class AccountCodeSchemeTest extends TestCase
{
    public function test_compare_natural_order(): void
    {
        $this->assertLessThan(0, AccountCodeScheme::compare('1', '2'));
        $this->assertLessThan(0, AccountCodeScheme::compare('11', '11.1'));
        $this->assertLessThan(0, AccountCodeScheme::compare('11.2', '11.10'));
        $this->assertSame(0, AccountCodeScheme::compare('11.1', '11.1'));
    }

    public function test_is_well_formed(): void
    {
        $this->assertTrue(AccountCodeScheme::isWellFormed('1'));
        $this->assertTrue(AccountCodeScheme::isWellFormed('11'));
        $this->assertTrue(AccountCodeScheme::isWellFormed('11.1.1'));
        $this->assertFalse(AccountCodeScheme::isWellFormed('10000'));
    }

    public function test_next_code_l1(): void
    {
        $used = ['1' => true, '2' => true];
        $this->assertSame('3', AccountCodeScheme::nextCode(null, 0, [], $used));
    }

    public function test_next_code_l2_under_l1(): void
    {
        $used = [];
        $this->assertSame('11', AccountCodeScheme::nextCode('1', 1, [], $used));
        $this->assertSame('12', AccountCodeScheme::nextCode('1', 1, ['11'], $used));
    }
}
