<?php
/**
 * Reset PostgreSQL sequences after seeding with explicit IDs.
 *
 * When seeders insert rows with explicit IDs (e.g. id => 1),
 * PostgreSQL's auto-increment sequence is not advanced. This
 * script resets all sequences to MAX(id) + 1 so that subsequent
 * INSERT statements without explicit IDs don't collide.
 *
 * Usage: php reset-sequences.php
 */

$host = getenv('DB_HOST') ?: 'localhost';
$port = getenv('DB_PORT') ?: '5432';
$db   = getenv('DB_DATABASE') ?: 'hatchloom_integration';
$user = getenv('DB_USERNAME') ?: 'hatchloom';
$pass = getenv('DB_PASSWORD') ?: 'secret';

$pdo = new PDO("pgsql:host={$host};port={$port};dbname={$db}", $user, $pass);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Find all tables with an 'id' column backed by a sequence
$stmt = $pdo->query("
    SELECT table_name
    FROM information_schema.columns
    WHERE column_name = 'id'
      AND column_default LIKE 'nextval%'
      AND table_schema = 'public'
");

foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $table) {
    $seq = $pdo->query("SELECT pg_get_serial_sequence('{$table}', 'id')")->fetchColumn();
    if ($seq) {
        $max = $pdo->query("SELECT COALESCE(MAX(id), 0) FROM {$table}")->fetchColumn();
        $next = $max + 1;
        $pdo->exec("SELECT setval('{$seq}', {$next}, false)");
        echo "  {$table}: sequence reset to {$next}\n";
    }
}

echo "All sequences reset.\n";
