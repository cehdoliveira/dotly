<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/inc/lists.php';

use PHPUnit\Framework\TestCase;

final class ListsTest extends TestCase
{
    public function testYesNoListsHasExpectedKeys(): void
    {
        $this->assertArrayHasKey('yes', $GLOBALS['yes_no_lists']);
        $this->assertArrayHasKey('no', $GLOBALS['yes_no_lists']);
        $this->assertEquals('sim', $GLOBALS['yes_no_lists']['yes']);
        $this->assertEquals('não', $GLOBALS['yes_no_lists']['no']);
    }

    public function testWeekNameHasAllDays(): void
    {
        $this->assertCount(7, $GLOBALS['week_name']);
        $this->assertEquals('Domingo', $GLOBALS['week_name']['0']);
        $this->assertEquals('Sábado', $GLOBALS['week_name']['6']);
    }

    public function testMonthNameHasAllMonths(): void
    {
        $this->assertCount(12, $GLOBALS['month_name']);
        $this->assertEquals('Janeiro', $GLOBALS['month_name']['01']);
        $this->assertEquals('Dezembro', $GLOBALS['month_name']['12']);
    }

    public function testUfbrListsHasAllStates(): void
    {
        $this->assertCount(27, $GLOBALS['ufbr_lists']);
        $this->assertEquals('São Paulo', $GLOBALS['ufbr_lists']['SP']);
        $this->assertEquals('Acre', $GLOBALS['ufbr_lists']['AC']);
    }

    public function testAccentsListsAreReversible(): void
    {
        $this->assertEquals('a', $GLOBALS['withoutaccents_lists']['á']);
        $this->assertEquals('Á', $GLOBALS['accents_lists']['á']);
    }
}
