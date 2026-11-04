<?php

use Programster\PgsqlObjects\AbstractTableRowObject;
use Programster\PgsqlObjects\TableInterface;

class TicketRecord extends AbstractTableRowObject
{
    private ?string $m_title = null;
    private ?string $m_status = null;


    protected function getAccessorFunctions() : array
    {
        $accessors = array(
            'title' => function () { return $this->m_title; },
        );

        // only send the status if one was set, so the database default applies otherwise.
        if ($this->m_status !== null)
        {
            $accessors['status'] = function () { return $this->m_status; };
        }

        return $accessors;
    }


    protected function getSetFunctions() : array
    {
        return array(
            'title'  => function ($x) { $this->m_title = $x; },
            'status' => function ($x) { $this->m_status = $x; },
        );
    }


    public function getTableHandler() : TableInterface
    {
        return TicketTable::getInstance();
    }


    public function getTitle() : ?string
    {
        return $this->m_title;
    }


    public function getStatus() : ?string
    {
        return $this->m_status;
    }
}
