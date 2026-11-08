<?php

use Programster\PgsqlObjects\AbstractRecord;
use Programster\PgsqlObjects\TableInterface;

class LedgerRecord extends AbstractRecord
{
    // deliberately untyped, so the test can see exactly what type each column was converted to.
    private float|int|string|null $m_exactAmount = null;
    private float|int|string|null $m_approximateAmount = null;


    public function getTableHandler() : TableInterface
    {
        return LedgerTable::getInstance();
    }


    protected function getAccessorFunctions() : array
    {
        return [
            'exact_amount'       => function () : float|int|string|null { return $this->m_exactAmount; },
            'approximate_amount' => function () : float|int|string|null { return $this->m_approximateAmount; },
        ];
    }


    protected function getSetFunctions() : array
    {
        return [
            'exact_amount'       => function (float|int|string|null $x) : void { $this->m_exactAmount = $x; },
            'approximate_amount' => function (float|int|string|null $x) : void { $this->m_approximateAmount = $x; },
        ];
    }


    # Accessors
    public function getExactAmount() : float|int|string|null { return $this->m_exactAmount; }
    public function getApproximateAmount() : float|int|string|null { return $this->m_approximateAmount; }
}
