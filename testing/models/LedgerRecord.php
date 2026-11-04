<?php

use Programster\PgsqlObjects\AbstractTableRowObject;
use Programster\PgsqlObjects\TableInterface;

class LedgerRecord extends AbstractTableRowObject
{
    // deliberately untyped, so the test can see exactly what type each column was converted to.
    private mixed $m_exactAmount = null;
    private mixed $m_approximateAmount = null;


    protected function getAccessorFunctions() : array
    {
        return array(
            'exact_amount'       => function () { return $this->m_exactAmount; },
            'approximate_amount' => function () { return $this->m_approximateAmount; },
        );
    }


    protected function getSetFunctions() : array
    {
        return array(
            'exact_amount'       => function ($x) { $this->m_exactAmount = $x; },
            'approximate_amount' => function ($x) { $this->m_approximateAmount = $x; },
        );
    }


    public function getTableHandler() : TableInterface
    {
        return LedgerTable::getInstance();
    }


    # Accessors
    public function getExactAmount() : mixed { return $this->m_exactAmount; }
    public function getApproximateAmount() : mixed { return $this->m_approximateAmount; }
}
