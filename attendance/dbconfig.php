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

if (!function_exists('attendance_holiday_dates')) {
    /**
     * Create the holidays table if it does not exist yet.
     *
     * Only the admin management page calls this; every other page uses
     * attendance_holiday_dates(), which tolerates the table being absent so a
     * fresh install never breaks before an admin opens Manage Holidays.
     */
    function attendance_ensure_holidays_table(mysqli $conn)
    {
        $conn->query("CREATE TABLE IF NOT EXISTS `holidays` (
            `id`           INT          NOT NULL AUTO_INCREMENT,
            `holiday_date` DATE         NOT NULL,
            `name`         VARCHAR(150) NOT NULL DEFAULT '',
            `status`       TINYINT(1)   NOT NULL DEFAULT 1,
            `created_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_holiday_date` (`holiday_date`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    /**
     * Active holidays as a lookup: 'Y-m-d' => holiday name.
     *
     * A holiday is a non-teaching day, so slot expansion skips these dates
     * entirely. Disabled rows (status = 0) are ignored, which lets an admin
     * cancel a holiday without losing the record.
     *
     * Returns an empty array when the table is missing, so pages keep working
     * on installs where no holiday has ever been added.
     */
    function attendance_holiday_dates(mysqli $conn)
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }
        $cache = [];
        $res = @$conn->query("SELECT holiday_date, name FROM holidays WHERE status = 1");
        if ($res instanceof mysqli_result) {
            while ($row = $res->fetch_assoc()) {
                $date = trim((string)$row['holiday_date']);
                if ($date !== '') {
                    $cache[$date] = (string)$row['name'];
                }
            }
            $res->free();
        }
        return $cache;
    }

    /** True when $date (Y-m-d) is an active holiday. */
    function attendance_is_holiday(array $holiday_dates, $date)
    {
        return isset($holiday_dates[(string)$date]);
    }
}

if (!function_exists('attendance_sort_students_naturally')) {
    /**
     * Order student rows by enrollment number in natural (human) order.
     *
     * Plain SQL `ORDER BY enrollmentNo` sorts as text, so prefixed numbers such
     * as CO-1, CO-2 … CO-10 come out as CO-1, CO-10, CO-11, CO-2 — the digits
     * are compared character by character. strnatcasecmp() compares embedded
     * numbers by value instead, giving CO-1, CO-2, CO-3 … CO-10, CO-11.
     *
     * Enrollment numbers made up only of digits (e.g. 226310316001) are already
     * equal-length in practice and keep exactly the same ascending order they
     * had before, so existing installations see no change.
     *
     * Sorting happens in PHP rather than SQL deliberately: the SQL equivalent
     * needs REGEXP_REPLACE/REGEXP_SUBSTR (MySQL 8.0+ / MariaDB 10.0+), and if
     * the host lacked them every attendance page would fail. This works on any
     * version.
     *
     * @param array $rows        Rows containing at least the enrollment key.
     * @param string $enrollKey  Key holding the enrollment number.
     * @param string $nameKey    Optional tie-breaker key.
     * @param string $groupKey   Optional key sorted before the enrollment
     *                           number (lab/tutorial pages group by batch
     *                           first, and that grouping must be preserved).
     */
    function attendance_sort_students_naturally(array $rows, $enrollKey = 'enrollmentNo', $nameKey = 'name', $groupKey = '')
    {
        usort($rows, function ($a, $b) use ($enrollKey, $nameKey, $groupKey) {
            if ($groupKey !== '') {
                $ga = strtoupper(trim((string)($a[$groupKey] ?? '')));
                $gb = strtoupper(trim((string)($b[$groupKey] ?? '')));
                if ($ga !== $gb) {
                    return strnatcasecmp($ga, $gb);
                }
            }
            $cmp = strnatcasecmp(
                (string)($a[$enrollKey] ?? ''),
                (string)($b[$enrollKey] ?? '')
            );
            if ($cmp !== 0) {
                return $cmp;
            }
            if ($nameKey === '') {
                return 0;
            }
            return strnatcasecmp(
                (string)($a[$nameKey] ?? ''),
                (string)($b[$nameKey] ?? '')
            );
        });

        return $rows;
    }
}
?>
