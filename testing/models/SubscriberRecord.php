<?php

use Programster\PgsqlObjects\AbstractRecord;
use Programster\PgsqlObjects\TableInterface;

/**
 * A subscriber, whose ID is their email address. The ID column isn't in the accessors or setters, as the package
 * handles it, so getEmail() returns the ID.
 */
class SubscriberRecord extends AbstractRecord
{
    private ?string $m_name = null;


    public function getTableHandler() : TableInterface
    {
        return SubscriberTable::getInstance();
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
    public function getEmail() : string { return (string)$this->getId(); }
    public function getName() : ?string { return $this->m_name; }


    # Setters
    /**
     * Change the name without saving, so the tests can save subscribers in a batch.
     * @param string $name
     * @return void
     */
    public function setName(string $name) : void
    {
        $this->m_name = $name;
    }
}
