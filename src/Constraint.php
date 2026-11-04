<?php

/*
 * A class to represent a constraint on table. This makes it easier to create/remove them.
 */

declare(strict_types = 1);

namespace Programster\PgsqlObjects;


use Programster\PgsqlLib\PgSqlConnection;

readonly class Constraint
{
    private string $m_name;
    private string $m_tableName;
    private string $m_sqlCreate;
    private string $m_sqlDelete;


    private function __construct(string $name, string $tableName, string $sqlCreate, string $sqlDelete)
    {
        $this->m_name = $name;
        $this->m_tableName = $tableName;
        $this->m_sqlCreate = $sqlCreate;
        $this->m_sqlDelete = $sqlDelete;
    }


    /**
     * Create a Primary key constraint.
     * @param string $tableName
     * @param string $constraintName
     * @param array $columnNames
     * @param \Programster\PgsqlObjects\DeferConfig $deferConfig
     * @param \Programster\PgsqlLib\PgSqlConnection $conn
     * @return \Programster\PgsqlObjects\Constraint
     */
    public static function createPrimary(
        string $tableName,
        string $constraintName,
        array  $columnNames,
        DeferConfig $deferConfig,
        PgSqlConnection $conn
    ) : Constraint
    {
        $escapedTableName = $conn->escapeIdentifier($tableName);
        $escapedName = $conn->escapeIdentifier($constraintName);
        $escapedColumnNames = $conn->escapeIdentifiers($columnNames);

        $sqlCreate =
            "ALTER TABLE $escapedTableName" .
            " ADD CONSTRAINT $escapedName" .
            " PRIMARY KEY (" . implode(",", $escapedColumnNames) . ")" .
            " {$deferConfig->value}";

        $sqlDelete = "ALTER TABLE $escapedTableName DROP CONSTRAINT $escapedName";
        return new Constraint($constraintName, $tableName, $sqlCreate, $sqlDelete);
    }


    /**
     * Create a unique constraint.
     * @param string $tableName
     * @param string $constraintName
     * @param array $columnNames
     * @param \Programster\PgsqlObjects\DeferConfig $deferConfig
     * @param \Programster\PgsqlLib\PgSqlConnection $conn
     * @return \Programster\PgsqlObjects\Constraint
     */
    public static function createUnique(
        string $tableName,
        string $constraintName,
        array  $columnNames,
        DeferConfig $deferConfig,
        PgSqlConnection $conn
    ) : Constraint
    {
        $escapedTableName = $conn->escapeIdentifier($tableName);
        $escapedName = $conn->escapeIdentifier($constraintName);
        $escapedColumnNames = $conn->escapeIdentifiers($columnNames);

        $sqlCreate =
            "ALTER TABLE $escapedTableName" .
            " ADD CONSTRAINT $escapedName" .
            " UNIQUE (" . implode(",", $escapedColumnNames) . ")" .
            " {$deferConfig->value}";

        $sqlDelete = "ALTER TABLE $escapedTableName DROP CONSTRAINT $escapedName";
        return new Constraint($constraintName, $tableName, $sqlCreate, $sqlDelete);
    }


    /**
     * Create a foreign key constraint.
     * @param string $tableName - the name of the table we are creating the constraint for.
     * @param string $columnName - the name of the column in this table that is a foreign key referencing another table.
     * @param string $constraintName - the name for the constraint
     * @param string $referencedTableName - the table the foreign key references
     * @param string $referencedTableColumn - the column in the foreign table that we reference.
     * @param \Programster\PgsqlObjects\DeferConfig $deferConfig
     * @param \Programster\PgsqlLib\PgSqlConnection $conn
     * @return \Programster\PgsqlObjects\Constraint
     */
    public static function createForeignKey(
        string $tableName,
        string $columnName,
        string $constraintName,
        string $referencedTableName,
        string $referencedTableColumn,
        DeferConfig $deferConfig,
        PgSqlConnection $conn
    ) : Constraint
    {
        $escapedTableName = $conn->escapeIdentifier($tableName);
        $escapedConstraintName = $conn->escapeIdentifier($constraintName);
        $escapedColumnName = $conn->escapeIdentifier($columnName);
        $escapedReferencedTableName = $conn->escapeIdentifier($referencedTableName);
        $escapedReferencedColumn = $conn->escapeIdentifier($referencedTableColumn);

        $sqlCreate =
            "ALTER TABLE $escapedTableName" .
            " ADD CONSTRAINT $escapedConstraintName" .
            " FOREIGN KEY ({$escapedColumnName})" .
            " REFERENCES $escapedReferencedTableName ($escapedReferencedColumn)" .
            " {$deferConfig->value}";

        $sqlDelete = "ALTER TABLE $escapedTableName DROP CONSTRAINT $escapedConstraintName";
        return new Constraint($constraintName, $tableName, $sqlCreate, $sqlDelete);
    }


    # Accessors
    public function getName() : string { return $this->m_name; }
    public function getTableName() : string { return $this->m_tableName; }
    public function getSqlCreate() : string { return $this->m_sqlCreate; }
    public function getSqlDelete() : string { return $this->m_sqlDelete; }
}
