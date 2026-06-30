<?php
require_once __DIR__ . '/auth.php';

$attendance_public_scripts = ['index.php'];
$attendance_current_script = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));
$attendance_is_cli = PHP_SAPI === 'cli';
$attendance_is_public_script = in_array($attendance_current_script, $attendance_public_scripts, true);

if (!$attendance_is_cli && !$attendance_is_public_script) {
    require_login();
}

function attendance_render_database_error_page($message)
{
    http_response_code(503);
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>KDP-MIS | Service Unavailable</title>
    <style>
        body {
            margin: 0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px;
            font-family: Arial, sans-serif;
            background: linear-gradient(135deg, #eef2ff, #f8fafc);
            color: #1f2937;
        }
        .error-card {
            width: 100%;
            max-width: 560px;
            background: #ffffff;
            border: 1px solid #dbe3f0;
            border-radius: 16px;
            box-shadow: 0 12px 32px rgba(15, 23, 42, 0.08);
            padding: 28px;
        }
        h1 {
            margin: 0 0 12px;
            font-size: 28px;
            color: #243b8f;
        }
        p {
            margin: 0 0 12px;
            line-height: 1.6;
        }
    </style>
</head>
<body>
    <div class="error-card">
        <h1>Attendance Service Unavailable</h1>
        <p>The attendance system could not connect to its database.</p>
        <p><?php echo htmlspecialchars((string)$message); ?></p>
    </div>
</body>
</html>
<?php
    exit();
}

function attendance_try_connection(array $config)
{
    try {
        $mysqli = @new mysqli(
            $config['host'],
            $config['username'],
            $config['password'],
            $config['dbname']
        );
    } catch (Throwable $exception) {
        return [null, $exception->getMessage()];
    }

    if (!$mysqli instanceof mysqli) {
        return [null, 'Database connection could not be created.'];
    }

    if ($mysqli->connect_errno) {
        $error = $mysqli->connect_error ?: ('MySQL error ' . $mysqli->connect_errno);
        return [null, $error];
    }

    return [$mysqli, null];
}

function attendance_is_local_runtime()
{
    if (PHP_SAPI === 'cli') {
        return true;
    }

    $host = strtolower(trim((string)($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? '')));
    if ($host === '') {
        return false;
    }

    $host = preg_replace('/:\d+$/', '', $host);

    return in_array($host, ['localhost', '127.0.0.1', '::1'], true);
}

mysqli_report(MYSQLI_REPORT_OFF);

/**
 * Database credentials are NEVER read from committed code.
 * Resolution order:
 *   1. Environment variables: ATTENDANCE_DB_HOST, ATTENDANCE_DB_DB, ATTENDANCE_DB_USER, ATTENDANCE_DB_PASS
 *   2. attendance/dbconfig.local.php (gitignored — your private overrides)
 *   3. attendance/dbconfig.php embedded fallback (legacy; shows a deprecation warning)
 *
 * Operators should rotate any credentials that were ever committed to source control.
 */

$attendance_db_configs = [];

// 1. Environment variables take precedence
$env_host = getenv('ATTENDANCE_DB_HOST');
$env_db   = getenv('ATTENDANCE_DB_DB');
$env_user = getenv('ATTENDANCE_DB_USER');
$env_pass = getenv('ATTENDANCE_DB_PASS');
if (!empty($env_host) || !empty($env_db) || !empty($env_user)) {
    $attendance_db_configs[] = [
        'label'    => 'env-vars',
        'host'     => $env_host ?: 'localhost',
        'dbname'   => $env_db   ?: 'kdpatt',
        'username' => $env_user ?: 'root',
        'password' => $env_pass !== false ? (string)$env_pass : '',
    ];
}

// 2. Local override file (gitignored)
$local_config_path = __DIR__ . '/dbconfig.local.php';
if (file_exists($local_config_path)) {
    $local_configs = null;
    require $local_config_path;
    if (!empty($local_configs) && is_array($local_configs)) {
        foreach ($local_configs as $cfg) {
            $attendance_db_configs[] = $cfg;
        }
    }
}

// 3. Legacy fallback — credentials intentionally emptied.
//    For real credentials, supply env vars or attendance/dbconfig.local.php.
$attendance_db_configs[] = [
    'label'    => 'legacy-attendance',
    'host'     => '',
    'dbname'   => '',
    'username' => '',
    'password' => '',
];

if (attendance_is_local_runtime()) {
    $attendance_db_configs[] = [
        'label'    => 'local-attendance',
        'host'     => 'localhost',
        'dbname'   => 'kdpatt',
        'username' => 'root',
        'password' => '',
    ];
}

$conn = null;
$db_connection_error = '';
$db_connection_errors = [];

foreach ($attendance_db_configs as $attendance_db_config) {
    list($candidateConnection, $candidateError) = attendance_try_connection($attendance_db_config);
    if ($candidateConnection instanceof mysqli) {
        $candidateConnection->set_charset('utf8mb4');
        $conn = $candidateConnection;
        $db_connection_error = '';
        break;
    }

    $db_connection_errors[] = (string)$attendance_db_config['label'] . ': ' . (string)$candidateError;
}

if (!$conn instanceof mysqli) {
    if (!empty($db_connection_errors)) {
        $db_connection_error = implode(' ', $db_connection_errors);
    } elseif ($db_connection_error === '') {
        $db_connection_error = 'No working attendance database configuration was found.';
    }

    if (!$attendance_is_cli && !$attendance_is_public_script) {
        attendance_render_database_error_page($db_connection_error);
    }
}
?>
