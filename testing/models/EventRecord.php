<?php

use Programster\PgsqlObjects\AbstractRecord;
use Programster\PgsqlObjects\TableInterface;

class EventRecord extends AbstractRecord
{
    private ?string $m_name = null;


    public function getTableHandler() : TableInterface
    {
        return EventTable::getInstance();
    }


    protected function getAccessorFunctions() : array
    {
        return [
            'name' => function () : ?string { return $this->m_name; },
        ];
    }


    protected function getSetFunctions() : array
    {
        return [
            'name' => function (?string $x) : void { $this->m_name = $x; },
        ];
    }


    # Accessors
    public function getName() : ?string { return $this->m_name; }
}
