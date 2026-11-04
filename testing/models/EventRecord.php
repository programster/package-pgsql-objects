<?php

use Programster\PgsqlObjects\AbstractTableRowObject;
use Programster\PgsqlObjects\TableInterface;

class EventRecord extends AbstractTableRowObject
{
    private ?string $m_name = null;


    protected function getAccessorFunctions() : array
    {
        return array(
            'name' => function () { return $this->m_name; },
        );
    }


    protected function getSetFunctions() : array
    {
        return array(
            'name' => function ($x) { $this->m_name = $x; },
        );
    }


    public function getTableHandler() : TableInterface
    {
        return EventTable::getInstance();
    }


    # Accessors
    public function getName() : ?string { return $this->m_name; }
}
