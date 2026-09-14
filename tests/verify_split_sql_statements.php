<?php

declare(strict_types=1);

// Standalone verification for Database::splitSqlStatements() - not part
// of tests/integration/run.php since it needs no running web server,
// just the SQL files and a throwaway database for the fresh-bootstrap
// check. Run directly: php tests/verify_split_sql_statements.php

spl_autoload_register(function (string $class): void {
    $prefix = 'Platform\\';
    if (str_starts_with($class, $prefix)) {
        require __DIR__ . '/../app/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    }
});

use Platform\Core\Database;

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException('FAILED: ' . $message);
    }
    echo "OK: $message\n";
}

/** @return string[] */
function callSplitSqlStatements(string $sql): array
{
    $method = new ReflectionMethod(Database::class, 'splitSqlStatements');
    $method->setAccessible(true);
    return $method->invoke(null, $sql);
}

// --- Part 1: splitSqlStatements() against the real schema.sql must not
// merge unrelated statements together, regardless of apostrophes or
// literal semicolons inside "--" comment prose. ---
$schemaSql = file_get_contents(__DIR__ . '/../sql/schema.sql');
check($schemaSql !== false, 'sql/schema.sql is readable');
$statements = callSplitSqlStatements($schemaSql);
check(count($statements) > 5, 'schema.sql splits into a plausible number of statements (' . count($statements) . ' found)');

foreach ($statements as $i => $statement) {
    // A generous ceiling, not a precise one - this codebase's own
    // clients table is a genuinely large single CREATE TABLE once its
    // real per-column comments are included (confirmed ~3.7KB), so this
    // is only here to catch a statement that's an order of magnitude
    // beyond that (a real merge produces something far bigger than any
    // single real table needs). The CREATE TABLE count check right
    // below is the actual precise signal.
    check(strlen($statement) < 15000, "statement #$i is not wildly oversized (" . strlen($statement) . ' chars) - a merged blob would be');
    // Strip "--" line comments the same way a real SQL client would,
    // to check what CODE (not comment prose) actually ended up in this
    // statement, independent of how many apostrophes/semicolons its
    // comments happen to contain.
    $codeOnly = preg_replace('/--[^\n]*/', '', $statement);
    $createTableCount = preg_match_all('/\bCREATE\s+TABLE\b/i', $codeOnly);
    check($createTableCount <= 1, "statement #$i contains at most one CREATE TABLE (found $createTableCount) - more means two statements got merged");
}

// The two known trouble spots from the original bug report - confirm
// their CREATE TABLE landed in two DIFFERENT statements, not fused into
// one, now that comments are parsed correctly.
$shopsIndex = null;
$intasendTxIndex = null;
foreach ($statements as $i => $statement) {
    if (preg_match('/CREATE\s+TABLE\s+IF\s+NOT\s+EXISTS\s+shops\b/i', $statement)) {
        $shopsIndex = $i;
    }
    if (preg_match('/CREATE\s+TABLE\s+IF\s+NOT\s+EXISTS\s+intasend_transactions\b/i', $statement)) {
        $intasendTxIndex = $i;
    }
}
check($shopsIndex !== null, 'the shops table statement was found on its own');
check($intasendTxIndex !== null, 'the intasend_transactions table statement was found on its own');
check($shopsIndex !== $intasendTxIndex, 'shops and intasend_transactions landed in two different statements, not merged into one');

// A synthetic case exercising both bugs at once in one pass: an odd
// apostrophe count in a comment, AND a semicolon inside a comment,
// immediately before a real statement boundary.
$synthetic = <<<SQL
-- a comment with one apostrophe: it's fine, and a semicolon; right here
CREATE TABLE IF NOT EXISTS synthetic_a (id INT PRIMARY KEY);
/* a block comment with a semicolon; and an apostrophe: shouldn't matter */
CREATE TABLE IF NOT EXISTS synthetic_b (id INT PRIMARY KEY);
SQL;
$syntheticStatements = callSplitSqlStatements($synthetic);
check(count($syntheticStatements) === 2, 'synthetic case: comments with apostrophes/semicolons still split into exactly 2 statements (got ' . count($syntheticStatements) . ')');

// --- Part 2: a genuinely fresh, from-scratch bootstrap must succeed
// end to end against a brand-new empty database, using the real
// bootstrap path (not just the statement splitter in isolation). ---
$testDbName = 'nexapos_platform_freshbootstraptest_' . bin2hex(random_bytes(4));
$rootPdo = new PDO('mysql:host=127.0.0.1', 'root', '');
$rootPdo->exec('DROP DATABASE IF EXISTS ' . $testDbName);

putenv('DB_NAME=' . $testDbName);
$_ENV['DB_NAME'] = $testDbName;

try {
    // Database::connection()'s PDO singleton is still unset this early
    // in the script's process, so this call is guaranteed to be the
    // first - and therefore to actually exercise the fresh-bootstrap
    // path against the just-created empty $testDbName.
    $pdo = Database::connection();
    $tableCount = (int) $pdo->query(
        "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = '$testDbName' AND TABLE_NAME IN ('shops','clients','transactions','intasend_transactions','intasend_webhook_events','paystack_webhook_events','sync_changes','shop_invites','join_attempts')"
    )->fetchColumn();
    check($tableCount === 9, "a genuinely fresh bootstrap creates all 9 expected tables (found $tableCount)");
    $clientsChannelExists = (int) $pdo->query(
        "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = '$testDbName' AND TABLE_NAME = 'clients' AND COLUMN_NAME = 'channel'"
    )->fetchColumn();
    check($clientsChannelExists === 1, 'clients.channel exists after a fresh bootstrap (schema.sql is in sync with the migrations)');
} finally {
    $rootPdo->exec('DROP DATABASE IF EXISTS ' . $testDbName);
}

echo "\nAll splitSqlStatements / fresh-bootstrap checks passed.\n";
