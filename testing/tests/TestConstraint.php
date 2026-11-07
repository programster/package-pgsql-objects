<?php

/*
 * Test that Constraint generates SQL that can create and then drop each kind of constraint, both for tables given by
 * name (resolved using the search_path), and for tables given as a Table in a specific schema, including a foreign
 * key that references a table in a different schema.
 */

use Programster\PgsqlLib\Table;
use Programster\PgsqlObjects\Constraint;
use Programster\PgsqlObjects\DeferConfig;

class TestConstraint
{
    public function __construct()
    {
    }


    public function run() : void
    {
        $this->testTablesByName();
        $this->testTablesInSchemas();
    }


    private function testTablesByName() : void
    {
        $db = ConnectionHandler::getDb();
        $db->query('DROP TABLE IF EXISTS "constraint_child", "constraint_parent"');
        $db->query('CREATE TABLE "constraint_parent" ("id" int NOT NULL, "code" varchar(10) NOT NULL)');
        $db->query('CREATE TABLE "constraint_child" ("id" int NOT NULL, "parent_id" int NOT NULL)');

        try
        {
            $primary = Constraint::createPrimary(
                'constraint_parent', 'constraint_parent_pkey', ['id'], DeferConfig::NOT_DEFERRABLE, $db
            );

            $unique = Constraint::createUnique(
                'constraint_parent', 'constraint_parent_code_key', ['code'], DeferConfig::INITIALLY_DEFERRED, $db
            );

            $foreignKey = Constraint::createForeignKey(
                'constraint_child', 'parent_id', 'constraint_child_parent_fkey',
                'constraint_parent', 'id', DeferConfig::INITIALLY_IMMEDIATE, $db
            );

            if ($primary->getName() !== 'constraint_parent_pkey' || $foreignKey->getTableName() !== 'constraint_child')
            {
                throw new \Exception("Constraint did not keep its name and table name.");
            }

            if ($foreignKey->getTable()->hasSchemaName())
            {
                throw new \Exception("A constraint on a table given by name was given a schema.");
            }

            $this->createAndDrop([$primary, $unique, $foreignKey]);
        }
        finally
        {
            $db->query('DROP TABLE IF EXISTS "constraint_child", "constraint_parent"');
        }
    }


    private function testTablesInSchemas() : void
    {
        $db = ConnectionHandler::getDb();
        $db->query('DROP SCHEMA IF EXISTS "constraint.parents" CASCADE');
        $db->query('DROP SCHEMA IF EXISTS "constraint_children" CASCADE');
        $db->query('CREATE SCHEMA "constraint.parents"');
        $db->query('CREATE SCHEMA "constraint_children"');

        // tables with the same names in the public schema, which the constraints must not be created on instead.
        $db->query('DROP TABLE IF EXISTS "public"."parent", "public"."child"');
        $db->query('CREATE TABLE "public"."parent" ("id" int, "code" varchar(10))');
        $db->query('CREATE TABLE "public"."child" ("id" int, "parent_id" int)');

        $db->query('CREATE TABLE "constraint.parents"."parent" ("id" int NOT NULL, "code" varchar(10) NOT NULL)');
        $db->query('CREATE TABLE "constraint_children"."child" ("id" int NOT NULL, "parent_id" int NOT NULL)');

        try
        {
            $parent = new Table('parent', 'constraint.parents');
            $child = new Table('child', 'constraint_children');
            $primary = Constraint::createPrimary($parent, 'parent_pkey', ['id'], DeferConfig::NOT_DEFERRABLE, $db);

            $unique = Constraint::createUnique(
                $parent, 'parent_code_key', ['code'], DeferConfig::INITIALLY_DEFERRED, $db
            );

            $foreignKey = Constraint::createForeignKey(
                $child, 'parent_id', 'child_parent_fkey', $parent, 'id', DeferConfig::INITIALLY_IMMEDIATE, $db
            );

            if ($foreignKey->getTable()->getSchemaName() !== 'constraint_children')
            {
                throw new \Exception("Constraint did not keep the schema of its table.");
            }

            $this->createAndDrop([$primary, $unique, $foreignKey], ['parent', 'child']);
        }
        finally
        {
            $db->query('DROP SCHEMA IF EXISTS "constraint.parents" CASCADE');
            $db->query('DROP SCHEMA IF EXISTS "constraint_children" CASCADE');
            $db->query('DROP TABLE IF EXISTS "public"."parent", "public"."child"');
        }
    }


    /**
     * Create the constraints in dependency order, then drop them in reverse. Each query throws if the SQL is invalid.
     *
     * @param Constraint[] $constraints - the constraints, in the order to create them in.
     *
     * @param string[] $decoyTableNames - optionally, the names of tables in the public schema that have the same
     * names as the constrained tables, which must not have been given any of the constraints.
     *
     * @return void
     */
    private function createAndDrop(array $constraints, array $decoyTableNames = []) : void
    {
        $db = ConnectionHandler::getDb();

        foreach ($constraints as $constraint)
        {
            $db->query($constraint->getSqlCreate());
        }

        foreach ($decoyTableNames as $decoyTableName)
        {
            $countQuery =
                'SELECT COUNT(*) AS "count" FROM "pg_catalog"."pg_constraint"' .
                " WHERE \"conrelid\" = to_regclass({$db->escapeValue("public.{$decoyTableName}")})";

            if (pg_fetch_assoc($db->query($countQuery))['count'] !== "0")
            {
                throw new \Exception("A constraint was created on public.{$decoyTableName}, not the schema's table.");
            }
        }

        foreach (array_reverse($constraints) as $constraint)
        {
            $db->query($constraint->getSqlDelete());
        }
    }
}
