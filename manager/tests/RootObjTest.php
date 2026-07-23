<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class RootObjTest extends TestCase
{
    private rootOBJ $obj;

    protected function setUp(): void
    {
        $this->obj = new rootOBJ();
    }

    public function testSetAndGetData(): void
    {
        $this->obj->set_data(['key' => 'value']);
        $this->assertSame(['key' => 'value'], $this->obj->get_data());
    }

    public function testDynamicGetterSetter(): void
    {
        $this->obj->set_filter([" active = 'yes' "]);
        $this->assertSame([" active = 'yes' "], $this->obj->get_filter());
    }

    public function testDynamicPropertyDefaultToNull(): void
    {
        $this->assertNull($this->obj->get_table());
    }

    public function testRenderHtmlReturnsRawData(): void
    {
        $data = ['name' => 'test'];
        $result = $this->obj->render($data, '.html');
        $this->assertSame($data, $result);
    }

    public function testRenderJsonOutputsJson(): void
    {
        $data = ['name' => 'test'];
        ob_start();
        $this->obj->render($data, '.json');
        $output = ob_get_clean();
        $this->assertJson($output);
        $this->assertSame($data, json_decode($output, true));
    }

    public function testDataPropertyIsInitiallyEmptyArray(): void
    {
        $this->assertIsArray($this->obj->get_data());
        $this->assertEmpty($this->obj->get_data());
    }
}
