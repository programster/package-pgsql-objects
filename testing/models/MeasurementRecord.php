<?php

use Programster\PgsqlObjects\AbstractTableRowObject;
use Programster\PgsqlObjects\TableInterface;

class MeasurementRecord extends AbstractTableRowObject
{
    // deliberately untyped, so the test can see exactly what type each column was converted to.
    private mixed $m_reading = null;
    private mixed $m_price = null;


    protected function getAccessorFunctions() : array
    {
        return array(
            'reading' => function () { return $this->m_reading; },
            'price'   => function () { return $this->m_price; },
        );
    }


    protected function getSetFunctions() : array
    {
        return array(
            'reading' => function ($x) { $this->m_reading = $x; },
            'price'   => function ($x) { $this->m_price = $x; },
        );
    }


    public function getTableHandler() : TableInterface
    {
        return MeasurementTable::getInstance();
    }


    # Accessors
    public function getReading() : mixed { return $this->m_reading; }
    public function getPrice() : mixed { return $this->m_price; }
}
