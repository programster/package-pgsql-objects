<?php

use Programster\PgsqlObjects\AbstractRecord;
use Programster\PgsqlObjects\TableInterface;

class DocumentRecord extends AbstractRecord
{
    private ?string $m_body = null;


    public function getTableHandler() : TableInterface
    {
        return DocumentTable::getInstance();
    }


    protected function getAccessorFunctions() : array
    {
        return [
            'body' => function () : ?string { return $this->m_body; },
        ];
    }


    protected function getSetFunctions() : array
    {
        return [
            'body' => function (?string $x) : void { $this->m_body = $x; },
        ];
    }


    # Accessors
    public function getBody() : ?string { return $this->m_body; }
}
