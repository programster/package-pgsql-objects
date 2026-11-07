<?php

use Programster\PgsqlObjects\AbstractTableRowObject;
use Programster\PgsqlObjects\TableInterface;

class UserRecord extends AbstractTableRowObject
{
    private ?string $m_email = null;
    private ?string $m_name = null;


    public function getTableHandler() : TableInterface
    {
        return UserTable::getInstance();
    }


    protected function getAccessorFunctions() : array
    {
        return [
            'name'  => function () : ?string { return $this->m_name; },
            'email' => function () : ?string { return $this->m_email; },
        ];
    }


    protected function getSetFunctions() : array
    {
        return [
            'name'  => function (?string $x) : void { $this->m_name = $x; },
            'email' => function (?string $x) : void { $this->m_email = $x; },
        ];
    }


    # Accessors
    public function getName() : ?string { return $this->m_name; }
    public function getEmail() : ?string { return $this->m_email; }
}
