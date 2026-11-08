<?php

use Programster\PgsqlObjects\AbstractRecord;

/**
 * A note, for the note_uuid and note_serial tables. Its accessors list every column, even while one with a default is
 * null, so that the tests check the package leaves those out of a new object's insert itself.
 */
abstract class AbstractNoteRecord extends AbstractRecord
{
    private ?string $m_title = null;
    private ?string $m_status = null;
    private ?string $m_category = null;


    protected function getAccessorFunctions() : array
    {
        return [
            'title'    => function () : ?string { return $this->m_title; },
            'status'   => function () : ?string { return $this->m_status; },
            'category' => function () : ?string { return $this->m_category; },
        ];
    }


    protected function getSetFunctions() : array
    {
        return [
            'title'    => function (?string $x) : void { $this->m_title = $x; },
            'status'   => function (?string $x) : void { $this->m_status = $x; },
            'category' => function (?string $x) : void { $this->m_category = $x; },
        ];
    }


    # Accessors
    public function getTitle() : ?string { return $this->m_title; }
    public function getStatus() : ?string { return $this->m_status; }
    public function getCategory() : ?string { return $this->m_category; }
}
