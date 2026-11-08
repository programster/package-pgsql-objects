<?php

use Programster\PgsqlObjects\AbstractRecord;
use Programster\PgsqlObjects\TableInterface;

class SampleRecord extends AbstractRecord
{
    // deliberately untyped, so the test can see exactly what type the column was converted to.
    private float|int|string|null $m_reading = null;


    public function getTableHandler() : TableInterface
    {
        return SampleTable::getInstance();
    }


    protected function getAccessorFunctions() : array
    {
        return [
            'reading' => function () : float|int|string|null { return $this->m_reading; },
        ];
    }


    protected function getSetFunctions() : array
    {
        return [
            'reading' => function (float|int|string|null $x) : void { $this->m_reading = $x; },
        ];
    }


    # Accessors
    public function getReading() : float|int|string|null { return $this->m_reading; }
}
