<?php

use Programster\PgsqlObjects\AbstractRecord;
use Programster\PgsqlObjects\TableInterface;

class DottedNameRecord extends AbstractRecord
{
    // deliberately untyped, so the test can see exactly what type the column was converted to.
    private mixed $m_reading = null;


    public function getTableHandler() : TableInterface
    {
        return DottedNameTable::getInstance();
    }


    protected function getAccessorFunctions() : array
    {
        return [
            'reading' => function () : mixed { return $this->m_reading; },
        ];
    }


    protected function getSetFunctions() : array
    {
        return [
            'reading' => function (mixed $x) : void { $this->m_reading = $x; },
        ];
    }


    # Accessors
    public function getReading() : mixed { return $this->m_reading; }
}
