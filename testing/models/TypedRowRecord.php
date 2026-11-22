<?php

use Programster\PgsqlObjects\AbstractRecord;
use Programster\PgsqlObjects\TableInterface;

class TypedRowRecord extends AbstractRecord
{
    private mixed $m_name = null;
    private mixed $m_amount = null;
    private mixed $m_flag = null;
    private mixed $m_tags = null;
    private mixed $m_meta = null;
    private mixed $m_status = null;
    private mixed $m_happenedAt = null;
    private mixed $m_note = null;
    private mixed $m_code = null;


    public function getTableHandler() : TableInterface
    {
        return TypedRowTable::getInstance();
    }


    protected function getAccessorFunctions() : array
    {
        return [
            'name' => function () : mixed { return $this->m_name; },
            'amount' => function () : mixed { return $this->m_amount; },
            'flag' => function () : mixed { return $this->m_flag; },
            'tags' => function () : mixed { return $this->m_tags; },
            'meta' => function () : mixed { return $this->m_meta; },
            'status' => function () : mixed { return $this->m_status; },
            'happened_at' => function () : mixed { return $this->m_happenedAt; },
            'note' => function () : mixed { return $this->m_note; },
            'code' => function () : mixed { return $this->m_code; },
        ];
    }


    protected function getSetFunctions() : array
    {
        return [
            'name' => function (mixed $x) : void { $this->m_name = $x; },
            'amount' => function (mixed $x) : void { $this->m_amount = $x; },
            'flag' => function (mixed $x) : void { $this->m_flag = $x; },
            'tags' => function (mixed $x) : void { $this->m_tags = $x; },
            'meta' => function (mixed $x) : void { $this->m_meta = $x; },
            'status' => function (mixed $x) : void { $this->m_status = $x; },
            'happened_at' => function (mixed $x) : void { $this->m_happenedAt = $x; },
            'note' => function (mixed $x) : void { $this->m_note = $x; },
            'code' => function (mixed $x) : void { $this->m_code = $x; },
        ];
    }


    # Setters
    public function setName(string $name) : void { $this->m_name = $name; }
    public function setNote(?string $note) : void { $this->m_note = $note; }
    public function setCode(?string $code) : void { $this->m_code = $code; }
}
