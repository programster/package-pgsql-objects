<?php

/*
 * Exception to throw if a query fails
 * 
 */


namespace Programster\PgsqlObjects\Exceptions;


class ExceptionQueryFailed extends \Exception
{
    private $m_query;


    /**
     * Create a new unexpected value type exception.
     * @param type $unexpectedValue - the value of a type that was unexpected
     * @param string $message
     * @param int $code
     * @param \Throwable $previous
     */
    public function __construct(string $query, string $message = "", int $code = 0, \Throwable $previous = null)
    {
        $this->m_query;
    }


    # Get the unexpected value which caused the exception.
    public function getQuery() { return $this->m_query; }
}

