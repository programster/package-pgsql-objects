<?php

use Programster\PgsqlObjects\AbstractRecord;
use Programster\PgsqlObjects\TableInterface;

class LedgerRecord extends AbstractRecord
{
    // deliberately untyped, so the test can see exactly what type each column was converted to.
    private mixed $m_exactAmount = null;
    private mixed $m_approximateAmount = null;


    public function getTableHandler() : TableInterface
    {
        return LedgerTable::getInstance();
    }


    protected function getAccessorFunctions() : array
    {
        return [
            'exact_amount'       => function () : mixed { return $this->m_exactAmount; },
            'approximate_amount' => function () : mixed { return $this->m_approximateAmount; },
        ];
    }


    protected function getSetFunctions() : array
    {
        return [
            'exact_amount'       => function (mixed $x) : void { $this->m_exactAmount = $x; },
            'approximate_amount' => function (mixed $x) : void { $this->m_approximateAmount = $x; },
        ];
    }


    # Accessors
    public function getExactAmount() : mixed { return $this->m_exactAmount; }
    public function getApproximateAmount() : mixed { return $this->m_approximateAmount; }
}
