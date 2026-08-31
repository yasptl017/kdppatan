<?php
/**
 * Attendance Database Migration Runner
 *
 * Runs SQL migration files against the attendance database.
 *
 * ============================================================
 * SECURITY NOTE — DATABASE CREDENTIALS
 * ============================================================
 * This script NEVER hardcodes credentials. To avoid leaking
 * secrets through public repos, credentials MUST be supplied
 * at runtime via:
 *
 *   1. CLI flags:    --host=... --user=... --pass=... --dbname=...
 *   2. Env vars:      ATTENDANCE_DB_HOST / _USER / _PASS / _DB
 *   3. Interactive:   prompted on stdin when no flag/env is set
 *
 * Local dev / CI / shared-hosting setups can keep credentials
 * in `attendance/dbconfig.php` (gitignored) — only web mode uses
 * that file. CLI mode never reads it.
 * ============================================================
 *
 * CLI usage (from project root):
 *     php attendance/migrate_attendance.php --host=localhost --user=root --pass=secret --dbname=kdpatt
 *     php attendance/migrate_attendance.php --file=db/migration_006_create_attendance_mappings.sql
 *     php attendance/migrate_attendance.php --list
 *
 * Web usage (admin only):
 *     Visit: http://your-host/attendance/migrate_attendance.php
 *     Uses credentials defined in attendance/dbconfig.php (gitignored).
 */

// ── Detect mode ───────────────────────────────────────────────────────────────
$is_cli = (PHP_SAPI === 'cli');

// ── List of attendance migrations to run in order ───────────────────────────
// Auto-discover every db/migration_*.sql file in numeric order. This keeps
// the runner in sync with new migrations without having to edit this list
// each time.
function discover_migrations() {
    $project_root = dirname(__DIR__);
    $db_dir = $project_root . DIRECTORY_SEPARATOR . 'db';
    if (!is_dir($db_dir)) {
        return [];
    }
    $candidates = glob($db_dir . DIRECTORY_SEPARATOR . 'migration_*.sql');
    if (!$candidates) {
        return [];
    }
    sort($candidates, SORT_STRING);
    $out = [];
    foreach ($candidates as $full_path) {
        $rel = 'db/' . basename($full_path);
        $out[] = $rel;
    }
    return $out;
}

// Explicit fallback list (used if the db/ directory can't be read for some
// reason — e.g. a custom deploy that ships migrations outside db/).
$migrations = discover_migrations();
if (empty($migrations)) {
    $migrations = [
        'db/migration_004_create_attendance_faculty.sql',
        'db/migration_005_create_attendance_students_subjects.sql',
        'db/migration_006_create_attendance_mappings.sql',
        'db/migration_007_create_attendance_records.sql',
        'db/migration_008_create_attendance_studentmentor.sql',
        'db/migration_009_fix_attendance_column_widths.sql',
        'db/migration_010_exceptions_all_mapping_types.sql',
    ];
}

// ── Establish connection (CLI mode reads credentials from flags / env / stdin) ─
$conn = null;

if ($is_cli) {
    $options = getopt('', ['file:', 'list', 'help', 'host:', 'user:', 'pass:', 'dbname:']);

    if (isset($options['help'])) {
        fwrite(STDOUT, <<<HELP
Attendance Database Migration Runner

Usage:
  php attendance/migrate_attendance.php [options]

Options:
  --list                  List available migration files and exit.
  --file=PATH             Run a single migration file (relative to project root).
  --host=HOST             MySQL host (or set ATTENDANCE_DB_HOST env).
  --user=USER             MySQL user (or set ATTENDANCE_DB_USER env).
  --pass=PASS             MySQL password (or set ATTENDANCE_DB_PASS env).
  --dbname=NAME           MySQL database name (or set ATTENDANCE_DB_DB env).
                          Defaults to "kdpatt" if not supplied.

If credentials are not supplied via flags or env, the script falls
back to the first hosted-style entry in attendance/dbconfig.local.php
(if one exists), then prompts interactively (password hidden on Unix).

Example:
  php attendance/migrate_attendance.php --host=localhost --user=root
HELP
        );
        exit(0);
    }

    if (isset($options['list'])) {
        $list = discover_migrations();
        if (empty($list)) {
            $list = $migrations;
        }
        fwrite(STDOUT, "Available attendance migrations (auto-discovered):\n");
        foreach ($list as $m) {
            fwrite(STDOUT, "  - $m\n");
        }
        exit(0);
    }

    if (isset($options['file'])) {
        $migrations = [$options['file']];
    }

    // Resolve credentials: flag > env > dbconfig.local.php > interactive prompt
    $host   = $options['host']   ?? getenv('ATTENDANCE_DB_HOST') ?: null;
    $user   = $options['user']   ?? getenv('ATTENDANCE_DB_USER') ?: null;
    $pass   = $options['pass']   ?? getenv('ATTENDANCE_DB_PASS');
    $dbname = $options['dbname'] ?? getenv('ATTENDANCE_DB_DB')   ?: 'kdpatt';

    // Hide pass env value in env listing
    if ($pass === false || $pass === '') {
        $pass = '';
    }

    // Fall back to attendance/dbconfig.local.php (gitignored — only useful on the server)
    if ((!$host || !$user) && file_exists(__DIR__ . '/dbconfig.local.php')) {
        $local_configs = require __DIR__ . '/dbconfig.local.php';
        if (is_array($local_configs)) {
            // Use the first hosted-style entry (skip plain localhost/root ones on the server)
            foreach ($local_configs as $cfg) {
                if (!empty($cfg['username']) && !empty($cfg['dbname'])
                    && $cfg['username'] !== 'root') {
                    $host   = $host   ?: ($cfg['host']     ?? 'localhost');
                    $user   = $user   ?: ($cfg['username'] ?? '');
                    $pass   = $pass   ?: ($cfg['password'] ?? '');
                    $dbname = $dbname !== 'kdpatt' ? $dbname : ($cfg['dbname'] ?? 'kdpatt');
                    break;
                }
            }
        }
    }

    // Interactive fallback
    if (!$host || !$user) {
        fwrite(STDOUT, "Database credentials required for migration.\n");
        if (!$host) {
            $host = trim(readline("MySQL host [localhost]: ") ?: 'localhost');
        }
        if (!$user) {
            $user = trim(readline("MySQL user [root]: ") ?: 'root');
        }
        if ($pass === null || $pass === '') {
            $pass = prompt_hidden_password("MySQL password: ");
        }
    }

    if (!$user) {
        fwrite(STDERR, "ERROR: MySQL user is required.\n");
        exit(2);
    }

    mysqli_report(MYSQLI_REPORT_OFF);
    $conn = @new mysqli($host, $user, $pass, $dbname);
    if ($conn->connect_errno || !$conn instanceof mysqli) {
        fwrite(STDERR, "ERROR: Could not connect to MySQL at {$host} (db: {$dbname}) as {$user}: " . ($conn->connect_error ?: 'unknown error') . "\n");
        exit(3);
    }
    $conn->set_charset('utf8mb4');
} else {
    // Web mode: use existing (gitignored) dbconfig.php
    require_once __DIR__ . '/dbconfig.php';
    if (!$conn instanceof mysqli) {
        // dbconfig.php already rendered an error page and exited.
        exit;
    }
}

// ── Output helpers ──────────────────────────────────────────────────────────
$use_color = $is_cli && function_exists('posix_isatty') && @posix_isatty(STDOUT);
$green  = $use_color ? "\033[32m" : '';
$red    = $use_color ? "\033[31m" : '';
$yellow = $use_color ? "\033[33m" : '';
$blue   = $use_color ? "\033[34m" : '';
$bold   = $use_color ? "\033[1m" : '';
$reset  = $use_color ? "\033[0m" : '';

function cli_line($msg) {
    fwrite(STDOUT, $msg . PHP_EOL);
}

/**
 * Prompt for a password without echoing input to the terminal.
 */
function prompt_hidden_password($prompt) {
    fwrite(STDOUT, $prompt);
    $password = '';
    if (function_exists('shell_exec') && stripos(PHP_OS, 'WIN') === false) {
        // On Unix-like systems, use stty to disable echo
        $stty_mode = trim((string)@shell_exec('stty -g'));
        @shell_exec('stty -echo');
        $password = trim((string)fgets(STDIN));
        @shell_exec('stty ' . $stty_mode);
        fwrite(STDOUT, "\n");
    } else {
        // Windows: fall back to plain readline (no stty available)
        $password = trim((string)readline(""));
    }
    return $password;
}

if ($is_cli) {
    cli_line("{$bold}{$blue}Attendance Database Migration Runner{$reset}");
    cli_line("Target DB: {$conn->host_info} → database `{$conn->query('SELECT DATABASE()')->fetch_row()[0]}`");
    cli_line(str_repeat('─', 60));
} else {
    echo "<h2>Attendance Database Migration Runner</h2>";
    echo "<p><strong>Connected to:</strong> {$conn->host_info} → database `{$conn->query('SELECT DATABASE()')->fetch_row()[0]}`</p>";
    echo "<hr>";
}

// ── Run migrations ──────────────────────────────────────────────────────────
$total_success = 0;
$total_failed  = 0;
$total_skipped = 0;

foreach ($migrations as $migration_file) {
    $project_root = dirname(__DIR__);
    $file_path = $project_root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $migration_file);

    if ($is_cli) {
        cli_line("{$bold}Running: {$migration_file}{$reset}");
    } else {
        echo "<h4>Running: {$migration_file}</h4>";
    }

    if (!file_exists($file_path)) {
        $msg = "⚠ Migration file not found: $file_path (skipped)";
        if ($is_cli) cli_line("  {$yellow}$msg{$reset}");
        else echo "<div style='color: orange;'>$msg</div>";
        $total_skipped++;
        continue;
    }

    $sql_content = file_get_contents($file_path);
    $statements = array_filter(array_map('trim', explode(';', $sql_content)));

    $migration_success = true;
    $statements_run = 0;

    foreach ($statements as $statement) {
        // Strip comment-only lines (starting with --) so the real SQL remains
        $stripped = '';
        foreach (explode("\n", $statement) as $line) {
            $trimmed_line = trim($line);
            if ($trimmed_line === '' || strpos($trimmed_line, '--') === 0) {
                continue;
            }
            $stripped .= $line . "\n";
        }
        $stripped = trim($stripped);
        if ($stripped === '') {
            continue;
        }
        $statements_run++;
        if ($conn->query($stripped)) {
            $preview = substr(preg_replace('/\s+/', ' ', $stripped), 0, 80);
            if ($is_cli) {
                cli_line("  {$green}✓{$reset} $preview...");
            } else {
                echo "<p style='color: green;'>✓ Executed: $preview...</p>";
            }
        } else {
            $err = $conn->error;
            if ($is_cli) {
                cli_line("  {$red}✗ Error:{$reset} $err");
            } else {
                echo "<p style='color: red;'>✗ Error: " . htmlspecialchars($err) . "</p>";
            }
            $migration_success = false;
            $total_failed++;
        }
    }

    if ($migration_success) {
        $total_success++;
        $summary = "✓ Migration completed ($statements_run statements)";
        if ($is_cli) {
            cli_line("  {$green}$summary{$reset}");
            cli_line(str_repeat('─', 60));
        } else {
            echo "<div style='color: green; padding: 8px; background: #d4edda; border: 1px solid green; border-radius: 4px;'>$summary</div>";
            echo "<hr>";
        }
    } else {
        if ($is_cli) {
            cli_line(str_repeat('─', 60));
        } else {
            echo "<hr>";
        }
    }
}

// ── Final summary ───────────────────────────────────────────────────────────
$summary_text = "Successful: $total_success | Failed: $total_failed | Skipped: $total_skipped";
if ($is_cli) {
    cli_line("{$bold}Migration Summary:{$reset}");
    cli_line("  $summary_text");
    if ($total_failed === 0) {
        cli_line("{$green}🎉 All attendance migrations applied successfully!{$reset}");
    } else {
        cli_line("{$red}⚠ Some migrations failed.{$reset}");
    }
} else {
    echo "<h3>Migration Summary</h3>";
    echo "<p>$summary_text</p>";
    if ($total_failed === 0) {
        echo "<div style='color: green; padding: 10px; background: #d4edda; border: 1px solid green; border-radius: 5px;'>🎉 All attendance migrations applied successfully!</div>";
    } else {
        echo "<div style='color: red; padding: 10px; background: #f8d7da; border: 1px solid red; border-radius: 5px;'>⚠ Some migrations failed. Check the errors above.</div>";
    }
    echo "<p><a href='home.php'>← Back to Home</a></p>";
}

// ── Cleanup ────────────────────────────────────────────────────────────────
$conn->close();
exit($total_failed === 0 ? 0 : 1);