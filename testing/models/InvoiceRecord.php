<?php

use Programster\PgsqlObjects\AbstractRecord;
use Programster\PgsqlObjects\TableInterface;

class InvoiceRecord extends AbstractRecord
{
    private int $m_quantity;
    private int $m_unitPricePence;
    private ?int $m_totalPence = null; // stored generated column.
    private ?int $m_totalWithVatPence = null; // virtual generated column.
    private ?int $m_sequenceNumber = null; // identity column.


    public function getTableHandler() : TableInterface
    {
        return InvoiceTable::getInstance();
    }


    protected function getAccessorFunctions() : array
    {
        return [
            'quantity'             => function () : int { return $this->m_quantity; },
            'unit_price_pence'     => function () : int { return $this->m_unitPricePence; },
            'total_pence'          => function () : ?int { return $this->m_totalPence; },
            'total_with_vat_pence' => function () : ?int { return $this->m_totalWithVatPence; },
            'sequence_number'      => function () : ?int { return $this->m_sequenceNumber; },
        ];
    }


    protected function getSetFunctions() : array
    {
        return [
            'quantity'             => function (int $x) : void { $this->m_quantity = $x; },
            'unit_price_pence'     => function (int $x) : void { $this->m_unitPricePence = $x; },
            'total_pence'          => function (?int $x) : void { $this->m_totalPence = $x; },
            'total_with_vat_pence' => function (?int $x) : void { $this->m_totalWithVatPence = $x; },
            'sequence_number'      => function (?int $x) : void { $this->m_sequenceNumber = $x; },
        ];
    }


    # Accessors
    public function getQuantity() : int { return $this->m_quantity; }
    public function getUnitPricePence() : int { return $this->m_unitPricePence; }
    public function getTotalPence() : ?int { return $this->m_totalPence; }
    public function getTotalWithVatPence() : ?int { return $this->m_totalWithVatPence; }
    public function getSequenceNumber() : ?int { return $this->m_sequenceNumber; }


    # Setters
    /**
     * Change the quantity without saving, so the tests can check save() on an existing row.
     * @param int $quantity
     * @return void
     */
    public function setQuantity(int $quantity) : void
    {
        $this->m_quantity = $quantity;
    }
}
