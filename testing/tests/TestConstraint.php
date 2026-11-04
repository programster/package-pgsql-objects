<?php

/*
 * Test that Constraint generates SQL that can create and then drop each kind of constraint.
 */

use Programster\PgsqlObjects\Constraint;
use Programster\PgsqlObjects\DeferConfig;

class TestConstraint
{
    public function __construct()
    {
    }


    public function run() : void
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

            // create in dependency order, then drop in reverse. Each query throws if the SQL is invalid.
            foreach ([$primary, $unique, $foreignKey] as $constraint)
            {
                $db->query($constraint->getSqlCreate());
            }

            foreach ([$foreignKey, $unique, $primary] as $constraint)
            {
                $db->query($constraint->getSqlDelete());
            }
        }
        finally
        {
            $db->query('DROP TABLE IF EXISTS "constraint_child", "constraint_parent"');
        }
    }
}
