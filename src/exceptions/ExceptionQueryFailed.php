<?php

/*
 * Exception to throw if a query fails
 */

declare(strict_types = 1);

namespace Programster\PgsqlObjects\Exceptions;


class ExceptionQueryFailed extends \Exception
{
    private readonly string $m_query;


    /**
     * Create a new query failed exception.
     * @param string $query - the query that failed.
     * @param string $message
     * @param int $code
     * @param \Throwable|null $previous
     */
    public function __construct(string $query, string $message = "", int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
        $this->m_query = $query;
    }


    # Accessors
    public function getQuery() : string { return $this->m_query; }
}
