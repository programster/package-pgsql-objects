<?php

use Programster\PgsqlObjects\AbstractRecord;
use Programster\PgsqlObjects\TableInterface;

class TicketRecord extends AbstractRecord
{
    private ?string $m_title = null;
    private ?string $m_status = null;


    public function getTableHandler() : TableInterface
    {
        return TicketTable::getInstance();
    }


    protected function getAccessorFunctions() : array
    {
        $accessors = [
            'title' => function () : ?string { return $this->m_title; },
        ];

        // only send the status if one was set, so the database default applies otherwise.
        if ($this->m_status !== null)
        {
            $accessors['status'] = function () : ?string { return $this->m_status; };
        }

        return $accessors;
    }


    protected function getSetFunctions() : array
    {
        return [
            'title'  => function (?string $x) : void { $this->m_title = $x; },
            'status' => function (?string $x) : void { $this->m_status = $x; },
        ];
    }


    # Accessors
    public function getTitle() : ?string { return $this->m_title; }
    public function getStatus() : ?string { return $this->m_status; }


    # Setters
    /**
     * Change the title without saving, so the tests can batch save tickets.
     * @param string $title
     * @return void
     */
    public function setTitle(string $title) : void
    {
        $this->m_title = $title;
    }
}
