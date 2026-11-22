<?php

/*
 * What to sort loaded rows by: a column and a direction, e.g. new SortBy('created_at', SortOrder::DESC). The ID column
 * is always the tiebreaker, in the same direction. As is usual in PostgreSQL, NULLs come last when ascending, and
 * first when descending.
 */

declare(strict_types = 1);

namespace Programster\PgsqlObjects;

use Programster\PgsqlObjects\Exceptions\ExceptionMissingRequiredData;

final readonly class SortBy
{
    private string $m_column;
    private SortOrder $m_direction;


    /**
     * @param string $column - the name of the column to sort by. The table checks it's one of its columns.
     * @param SortOrder $direction - the direction to sort in.
     * @throws ExceptionMissingRequiredData - if $column is empty.
     */
    public function __construct(string $column, SortOrder $direction)
    {
        if ($column === '')
        {
            throw new ExceptionMissingRequiredData("The column to sort by can't be empty.");
        }

        $this->m_column = $column;
        $this->m_direction = $direction;
    }


    # Accessors
    public function getColumn() : string { return $this->m_column; }
    public function getDirection() : SortOrder { return $this->m_direction; }
}
