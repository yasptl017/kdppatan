<?php
/**
 * Database Migration Runner
 * Run pending migrations from the /db folder
 */

include 'Admin/dbconfig.php';

echo "<h2>Database Migration Runner</h2>";
echo "<hr>";

// List of migrations to run
$migrations = [
    'migration_001_create_dept_timetable.sql',
    'migration_002_create_dept_results.sql',
    'migration_003_create_dept_material.sql'
];

$totalSuccess = 0;
$totalFailed = 0;

foreach ($migrations as $migrationFile) {
    $filePath = 'db/' . $migrationFile;
    
    echo "<h4>Running: $migrationFile</h4>";

    if (!file_exists($filePath)) {
        echo "<div style='color: orange; padding: 10px; border: 1px solid orange; border-radius: 5px;'>";
        echo "⚠ Migration file not found: $filePath (skipped)";
        echo "</div>";
        echo "<hr>";
        continue;
    }

    echo "<p>📁 Reading file: <strong>$filePath</strong></p>";

    // Read and execute the SQL
    $sqlContent = file_get_contents($filePath);

    // Split by semicolon to handle multiple statements
    $statements = array_filter(array_map('trim', explode(';', $sqlContent)));

    $migrationSuccess = true;

    foreach ($statements as $statement) {
        // Skip comments and empty lines
        if (empty($statement) || strpos(trim($statement), '--') === 0) {
            continue;
        }

        if ($conn->query($statement)) {
            echo "<p style='color: green;'>✅ Executed: " . substr($statement, 0, 60) . "...</p>";
        } else {
            echo "<p style='color: red;'>❌ Error: " . $conn->error . "</p>";
            $migrationSuccess = false;
            $totalFailed++;
        }
    }

    if ($migrationSuccess) {
        $totalSuccess++;
        echo "<div style='color: green; padding: 10px; background: #d4edda; border: 1px solid green; border-radius: 5px;'>";
        echo "✅ Migration completed successfully!";
        echo "</div>";
    }

    echo "<hr>";
}

// Final Summary
echo "<div style='padding: 15px; border: 2px solid #4e73df; border-radius: 5px; font-weight: bold;'>";
echo "<h3>Migration Summary</h3>";
echo "✅ Successful: $totalSuccess<br>";
echo "❌ Failed: $totalFailed<br>";

if ($totalFailed === 0) {
    echo "<div style='color: green; margin-top: 10px;'>";
    echo "🎉 All migrations applied successfully!<br>";
    echo "You can now use the following features:<br>";
    echo "• Time Tables<br>";
    echo "• Results<br>";
    echo "• Materials<br>";
    echo "</div>";
} else {
    echo "<div style='color: red; margin-top: 10px;'>";
    echo "⚠ Some migrations failed. Please check the errors above.";
    echo "</div>";
}

echo "</div>";

echo "<hr>";
echo "<p><a href='index.php'>← Back to Home</a></p>";
?>
