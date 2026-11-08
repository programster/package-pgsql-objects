<?php

use Programster\PgsqlObjects\AbstractTableRowObject;
use Programster\PgsqlObjects\TableInterface;

class AuthorRecord extends AbstractTableRowObject
{
    private ?string $m_name = null;


    public function getTableHandler() : TableInterface
    {
        return AuthorTable::getInstance();
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


    # Setters
    /**
     * Change the name without saving, so the tests can save authors in a Transaction.
     * @param string $name
     * @return void
     */
    public function setName(string $name) : void
    {
        $this->m_name = $name;
    }
}
