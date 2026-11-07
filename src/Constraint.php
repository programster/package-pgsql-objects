<?php

/*
 * A class to represent a constraint on table. This makes it easier to create/remove them.
 */

declare(strict_types = 1);

namespace Programster\PgsqlObjects;


use Programster\PgsqlLib\PgSqlConnection;
use Programster\PgsqlLib\Table;

readonly class Constraint
{
    private string $m_name;
    private Table $m_table;
    private string $m_sqlCreate;
    private string $m_sqlDelete;


    private function __construct(string $name, Table $table, string $sqlCreate, string $sqlDelete)
    {
        $this->m_name = $name;
        $this->m_table = $table;
        $this->m_sqlCreate = $sqlCreate;
        $this->m_sqlDelete = $sqlDelete;
    }


    /**
     * Create a Primary key constraint.
     *
     * @param string|Table $table - the table to create the constraint on. A string is a table name that PostgreSQL
     * resolves using the connection's search_path. Use a Table (e.g. from a table handler's getTable()) to specify
     * the schema.
     *
     * @param string $constraintName - the name for the constraint.
     *
     * @param array $columnNames - the names of the columns that make up the primary key.
     *
     * @param DeferConfig $deferConfig - whether the constraint can be deferred to the end of a transaction.
     *
     * @param PgSqlConnection $conn - the connection, for escaping the names.
     *
     * @return Constraint
     */
    public static function createPrimary(
        string|Table $table,
        string $constraintName,
        array  $columnNames,
        DeferConfig $deferConfig,
        PgSqlConnection $conn
    ) : Constraint
    {
        $tableObject = Constraint::toTable($table);
        $escapedTableName = $conn->escapeTableName($tableObject);
        $escapedName = $conn->escapeIdentifier($constraintName);
        $escapedColumnNames = $conn->escapeIdentifiers($columnNames);

        $sqlCreate =
            "ALTER TABLE $escapedTableName" .
            " ADD CONSTRAINT $escapedName" .
            " PRIMARY KEY (" . implode(",", $escapedColumnNames) . ")" .
            " {$deferConfig->value}";

        $sqlDelete = "ALTER TABLE $escapedTableName DROP CONSTRAINT $escapedName";
        return new Constraint($constraintName, $tableObject, $sqlCreate, $sqlDelete);
    }


    /**
     * Create a unique constraint.
     *
     * @param string|Table $table - the table to create the constraint on. A string is a table name that PostgreSQL
     * resolves using the connection's search_path. Use a Table (e.g. from a table handler's getTable()) to specify
     * the schema.
     *
     * @param string $constraintName - the name for the constraint.
     *
     * @param array $columnNames - the names of the columns whose combined values must be unique.
     *
     * @param DeferConfig $deferConfig - whether the constraint can be deferred to the end of a transaction.
     *
     * @param PgSqlConnection $conn - the connection, for escaping the names.
     *
     * @return Constraint
     */
    public static function createUnique(
        string|Table $table,
        string $constraintName,
        array  $columnNames,
        DeferConfig $deferConfig,
        PgSqlConnection $conn
    ) : Constraint
    {
        $tableObject = Constraint::toTable($table);
        $escapedTableName = $conn->escapeTableName($tableObject);
        $escapedName = $conn->escapeIdentifier($constraintName);
        $escapedColumnNames = $conn->escapeIdentifiers($columnNames);

        $sqlCreate =
            "ALTER TABLE $escapedTableName" .
            " ADD CONSTRAINT $escapedName" .
            " UNIQUE (" . implode(",", $escapedColumnNames) . ")" .
            " {$deferConfig->value}";

        $sqlDelete = "ALTER TABLE $escapedTableName DROP CONSTRAINT $escapedName";
        return new Constraint($constraintName, $tableObject, $sqlCreate, $sqlDelete);
    }


    /**
     * Create a foreign key constraint.
     *
     * @param string|Table $table - the table we are creating the constraint for. A string is a table name that
     * PostgreSQL resolves using the connection's search_path. Use a Table (e.g. from a table handler's getTable())
     * to specify the schema.
     *
     * @param string $columnName - the name of the column in this table that is a foreign key referencing another table.
     *
     * @param string $constraintName - the name for the constraint.
     *
     * @param string|Table $referencedTable - the table the foreign key references, which may be in a different schema.
     * A string or Table, as with $table.
     *
     * @param string $referencedTableColumn - the column in the foreign table that we reference.
     *
     * @param DeferConfig $deferConfig - whether the constraint can be deferred to the end of a transaction.
     *
     * @param PgSqlConnection $conn - the connection, for escaping the names.
     *
     * @return Constraint
     */
    public static function createForeignKey(
        string|Table $table,
        string $columnName,
        string $constraintName,
        string|Table $referencedTable,
        string $referencedTableColumn,
        DeferConfig $deferConfig,
        PgSqlConnection $conn
    ) : Constraint
    {
        $tableObject = Constraint::toTable($table);
        $escapedTableName = $conn->escapeTableName($tableObject);
        $escapedConstraintName = $conn->escapeIdentifier($constraintName);
        $escapedColumnName = $conn->escapeIdentifier($columnName);
        $escapedReferencedTableName = $conn->escapeTableName($referencedTable);
        $escapedReferencedColumn = $conn->escapeIdentifier($referencedTableColumn);

        $sqlCreate =
            "ALTER TABLE $escapedTableName" .
            " ADD CONSTRAINT $escapedConstraintName" .
            " FOREIGN KEY ({$escapedColumnName})" .
            " REFERENCES $escapedReferencedTableName ($escapedReferencedColumn)" .
            " {$deferConfig->value}";

        $sqlDelete = "ALTER TABLE $escapedTableName DROP CONSTRAINT $escapedConstraintName";
        return new Constraint($constraintName, $tableObject, $sqlCreate, $sqlDelete);
    }


    /**
     * Get the plain (unescaped) name of the table the constraint is on, without its schema, e.g. user.
     * Use getTable() to also get the schema, if one was given.
     *
     * @return string
     */
    public function getTableName() : string
    {
        return $this->m_table->getName();
    }


    /**
     * Turn a table given as a string or a Table into a Table. A string becomes a Table with no schema, so that
     * PostgreSQL resolves it using the connection's search_path.
     *
     * @param string|Table $table - the table name, or Table.
     *
     * @return Table
     *
     * @throws \Programster\PgsqlLib\Exceptions\ExceptionInvalidTableName - if the table name is empty.
     */
    private static function toTable(string|Table $table) : Table
    {
        if (is_string($table))
        {
            $tableObject = new Table($table);
        }
        else
        {
            $tableObject = $table;
        }

        return $tableObject;
    }


    # Accessors
    public function getName() : string { return $this->m_name; }
    public function getTable() : Table { return $this->m_table; }
    public function getSqlCreate() : string { return $this->m_sqlCreate; }
    public function getSqlDelete() : string { return $this->m_sqlDelete; }
}
