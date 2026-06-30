<?php


error_reporting(E_ALL);
ini_set('display_errors', 1);


// Credentials are empty by default. Place real credentials in
// kdp/Admin/dbconfig.local.php (gitignored) for local dev, or set
// environment variables on the hosted server. See attendance/dbconfig.php
// for the same pattern.
$host = 'localhost';
$dbname = '';
$username = '';
$password = '';

// Optional: load gitignored local override if present
if (file_exists(__DIR__ . '/dbconfig.local.php')) {
    $local = require __DIR__ . '/dbconfig.local.php';
    if (is_array($local)) {
        $host     = $local['host']     ?? $host;
        $dbname   = $local['dbname']   ?? $dbname;
        $username = $local['username'] ?? $username;
        $password = $local['password'] ?? $password;
    }
}

// Create connection
$conn = new mysqli($host, $username, $password, $dbname);

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}
?>
