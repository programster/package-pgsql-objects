<?php

/*
 * Runs the README's PHP examples, in order, so the README can't drift from what the package does. Each block runs with
 * a use statement for every one of the package's classes it doesn't import itself, as a reader's file would have. The
 * quick start loads a user by an ID it knows, so that user is created first, and is deleted by the quick start.
 */

final class DocsTest
{
    private const README = __DIR__ . "/../../README.md";

    // the ID of the user the README's quick start loads, changes and deletes.
    private const QUICK_START_USER_ID = "01a1bc23-57a0-7318-8532-ce41ea306b73";


    public function __construct()
    {
        $db = ConnectionHandler::getDb();
        $db->query("TRUNCATE {$db->escapeIdentifier("user")}");
    }


    public function run() : void
    {
        $user = UserRecord::createFromArray([
            'id' => self::QUICK_START_USER_ID,
            'name' => 'Ada',
            'email' => 'ada@example.com',
        ]);

        $user->save();
        $blocks = self::findPhpBlocks((string)file_get_contents(self::README));
        assert(count($blocks) >= 2, "The README should have the UUID example and the quick start.");

        foreach ($blocks as $code)
        {
            eval(self::addPackageUseStatements($code));
        }

        $remaining = UserTable::getInstance()->loadIds(
            [self::QUICK_START_USER_ID],
            \Programster\PgsqlObjects\CacheMode::IGNORE_CACHE
        );

        assert(count($remaining) === 0, "The README's quick start should delete the user it loads.");
    }


    /**
     * Find the code in each of a markdown document's php code blocks, in order.
     * @param string $markdown - the document.
     * @return string[] - the code of each block.
     */
    private static function findPhpBlocks(string $markdown) : array
    {
        preg_match_all('/```php\n(.*?)```/s', $markdown, $matches);
        return $matches[1];
    }


    /**
     * Add a use statement, before a block's code, for every one of the package's classes that the block doesn't
     * import itself.
     *
     * @param string $code - the block's code.
     *
     * @return string - the code, with the use statements before it.
     */
    private static function addPackageUseStatements(string $code) : string
    {
        preg_match_all('/^use\s+[^;]*\\\\(\w+);/m', $code, $imports);
        $imported = array_flip($imports[1]);
        $useStatements = "";

        foreach (self::findPackageClasses() as $className)
        {
            $shortName = substr($className, (int)strrpos($className, "\\") + 1);

            if (!isset($imported[$shortName]))
            {
                $useStatements .= "use {$className};\n";
            }
        }

        return $useStatements . $code;
    }


    /**
     * Find the fully qualified name of every class, interface and enum in the package, from its files' paths, which
     * follow composer.json's PSR-4 mapping.
     *
     * @return string[] - the names, e.g. "Programster\PgsqlObjects\CacheMode".
     */
    private static function findPackageClasses() : array
    {
        $sourceDir = __DIR__ . "/../../src";
        $classNames = [];

        foreach ((array)glob("{$sourceDir}/*.php") as $file)
        {
            $classNames[] = "Programster\\PgsqlObjects\\" . basename((string)$file, ".php");
        }

        foreach ((array)glob("{$sourceDir}/exceptions/*.php") as $file)
        {
            $classNames[] = "Programster\\PgsqlObjects\\Exceptions\\" . basename((string)$file, ".php");
        }

        return $classNames;
    }
}
