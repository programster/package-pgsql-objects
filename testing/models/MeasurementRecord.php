<?php

use Programster\PgsqlObjects\AbstractRecord;
use Programster\PgsqlObjects\TableInterface;

class MeasurementRecord extends AbstractRecord
{
    // deliberately untyped, so the test can see exactly what type each column was converted to.
    private float|int|string|null $m_reading = null;
    private float|int|string|null $m_price = null;


    public function getTableHandler() : TableInterface
    {
        return MeasurementTable::getInstance();
    }


    protected function getAccessorFunctions() : array
    {
        return [
            'reading' => function () : float|int|string|null { return $this->m_reading; },
            'price'   => function () : float|int|string|null { return $this->m_price; },
        ];
    }


    protected function getSetFunctions() : array
    {
        return [
            'reading' => function (float|int|string|null $x) : void { $this->m_reading = $x; },
            'price'   => function (float|int|string|null $x) : void { $this->m_price = $x; },
        ];
    }


    # Accessors
    public function getReading() : float|int|string|null { return $this->m_reading; }
    public function getPrice() : float|int|string|null { return $this->m_price; }
}
