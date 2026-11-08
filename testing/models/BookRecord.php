<?php

use Programster\PgsqlObjects\AbstractTableRowObject;
use Programster\PgsqlObjects\TableInterface;

class BookRecord extends AbstractTableRowObject
{
    private ?string $m_authorId = null;
    private ?string $m_title = null;


    public function getTableHandler() : TableInterface
    {
        return BookTable::getInstance();
    }


    protected function getAccessorFunctions() : array
    {
        return [
            'author_id' => function () : ?string { return $this->m_authorId; },
            'title'     => function () : ?string { return $this->m_title; },
        ];
    }


    protected function getSetFunctions() : array
    {
        return [
            'author_id' => function (?string $x) : void { $this->m_authorId = $x; },
            'title'     => function (?string $x) : void { $this->m_title = $x; },
        ];
    }


    # Accessors
    public function getAuthorId() : ?string { return $this->m_authorId; }
    public function getTitle() : ?string { return $this->m_title; }


    # Setters
    /**
     * Change the author without saving, so the tests can save books in a Transaction.
     * @param string $authorId
     * @return void
     */
    public function setAuthorId(string $authorId) : void
    {
        $this->m_authorId = $authorId;
    }


    /**
     * Change the title without saving, so the tests can save books in a Transaction.
     * @param string $title
     * @return void
     */
    public function setTitle(string $title) : void
    {
        $this->m_title = $title;
    }
}
