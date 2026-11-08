<?php

use Programster\PgsqlObjects\AbstractRecord;
use Programster\PgsqlObjects\TableInterface;

class ReviewRecord extends AbstractRecord
{
    private ?string $m_authorId = null;
    private ?string $m_text = null;


    public function getTableHandler() : TableInterface
    {
        return ReviewTable::getInstance();
    }


    protected function getAccessorFunctions() : array
    {
        return [
            'author_id' => function () : ?string { return $this->m_authorId; },
            'text'      => function () : ?string { return $this->m_text; },
        ];
    }


    protected function getSetFunctions() : array
    {
        return [
            'author_id' => function (?string $x) : void { $this->m_authorId = $x; },
            'text'      => function (?string $x) : void { $this->m_text = $x; },
        ];
    }


    # Accessors
    public function getAuthorId() : ?string { return $this->m_authorId; }
    public function getText() : ?string { return $this->m_text; }
}
