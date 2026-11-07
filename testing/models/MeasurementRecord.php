<?php

use Programster\PgsqlObjects\AbstractTableRowObject;
use Programster\PgsqlObjects\TableInterface;

class MeasurementRecord extends AbstractTableRowObject
{
    // deliberately untyped, so the test can see exactly what type each column was converted to.
    private mixed $m_reading = null;
    private mixed $m_price = null;


    public function getTableHandler() : TableInterface
    {
        return MeasurementTable::getInstance();
    }


    protected function getAccessorFunctions() : array
    {
        return [
            'reading' => function () : mixed { return $this->m_reading; },
            'price'   => function () : mixed { return $this->m_price; },
        ];
    }


    protected function getSetFunctions() : array
    {
        return [
            'reading' => function (mixed $x) : void { $this->m_reading = $x; },
            'price'   => function (mixed $x) : void { $this->m_price = $x; },
        ];
    }


    # Accessors
    public function getReading() : mixed { return $this->m_reading; }
    public function getPrice() : mixed { return $this->m_price; }
}
