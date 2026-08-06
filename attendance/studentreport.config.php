<?php
/**
 * Master password for the public "Detailed Report" in the Student Corner
 * (attendance/index.php).
 *
 * Students enter the enrollment number to see percentages. To unlock the
 * detailed subject-wise breakdown (lecture / lab / tutorial counts) they must
 * also enter this master password, which the department shares with them.
 *
 * Resolution order (first non-empty wins):
 *   1. Environment variable ATTENDANCE_MASTER_PASSWORD_HASH  (a bcrypt hash)
 *   2. Environment variable ATTENDANCE_MASTER_PASSWORD       (plain text)
 *   3. attendance/studentreport.local.php                    (gitignored)
 *   4. The $default_master_password_hash below               (committed fallback)
 *
 * TO CHANGE THE PASSWORD, generate a fresh hash and paste it below:
 *   php -r "echo password_hash('YourNewPassword', PASSWORD_DEFAULT), PHP_EOL;"
 *
 * The committed fallback hash corresponds to: kdp@2026
 * Change it before or soon after going live.
 */

// bcrypt hash of "kdp@2026"
$default_master_password_hash = '$2y$12$LJL6YtNVjuz73tn03Q8gquCGm6mYqjJE2ap4AFE8A7.YVoAtCisiW';

if (!function_exists('attendance_master_password_verify')) {
    /**
     * Verify a submitted master password against the configured secret.
     */
    function attendance_master_password_verify($submitted)
    {
        $submitted = (string)$submitted;
        if ($submitted === '') {
            return false;
        }

        foreach (attendance_master_password_candidates() as $candidate) {
            list($type, $value) = $candidate;
            if ($value === '') {
                continue;
            }
            if ($type === 'hash') {
                if (password_verify($submitted, $value)) {
                    return true;
                }
            } elseif (hash_equals($value, $submitted)) {
                return true;
            }
        }

        return false;
    }
}

if (!function_exists('attendance_master_password_candidates')) {
    /**
     * Build the ordered list of configured secrets as [type, value] pairs,
     * where type is 'hash' (bcrypt) or 'plain'.
     */
    function attendance_master_password_candidates()
    {
        global $default_master_password_hash;

        $candidates = [];

        $env_hash = getenv('ATTENDANCE_MASTER_PASSWORD_HASH');
        if (is_string($env_hash) && trim($env_hash) !== '') {
            $candidates[] = ['hash', trim($env_hash)];
        }

        $env_plain = getenv('ATTENDANCE_MASTER_PASSWORD');
        if (is_string($env_plain) && trim($env_plain) !== '') {
            $candidates[] = ['plain', trim($env_plain)];
        }

        $local = attendance_master_password_local_read();
        if (!empty($local['hash'])) {
            $candidates[] = ['hash', trim((string)$local['hash'])];
        }
        if (!empty($local['password'])) {
            $candidates[] = ['plain', trim((string)$local['password'])];
        }

        if (empty($candidates) && !empty($default_master_password_hash)) {
            $candidates[] = ['hash', (string)$default_master_password_hash];
        }

        return $candidates;
    }
}

if (!function_exists('attendance_master_password_local_path')) {
    function attendance_master_password_local_path()
    {
        return __DIR__ . '/studentreport.local.php';
    }
}

if (!function_exists('attendance_master_password_local_read')) {
    /**
     * Read the local override file. Uses include (not require_once) so a value
     * written earlier in the same request is picked up rather than served from
     * the opcache/include cache.
     */
    function attendance_master_password_local_read()
    {
        $path = attendance_master_password_local_path();
        if (!file_exists($path)) {
            return [];
        }

        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($path, true);
        }
        clearstatcache(true, $path);

        $local = @include $path;

        return is_array($local) ? $local : [];
    }
}

if (!function_exists('attendance_master_password_is_customised')) {
    /**
     * True when the password has been changed away from the committed default.
     */
    function attendance_master_password_is_customised()
    {
        if (trim((string)getenv('ATTENDANCE_MASTER_PASSWORD_HASH')) !== ''
            || trim((string)getenv('ATTENDANCE_MASTER_PASSWORD')) !== '') {
            return true;
        }

        $local = attendance_master_password_local_read();

        return !empty($local['hash']) || !empty($local['password']);
    }
}

if (!function_exists('attendance_master_password_env_locked')) {
    /**
     * When an environment variable supplies the secret it always wins over the
     * local file, so saving from the UI would have no effect. Detect that so
     * the admin screen can say so instead of silently doing nothing.
     */
    function attendance_master_password_env_locked()
    {
        return trim((string)getenv('ATTENDANCE_MASTER_PASSWORD_HASH')) !== ''
            || trim((string)getenv('ATTENDANCE_MASTER_PASSWORD')) !== '';
    }
}

if (!function_exists('attendance_master_password_save')) {
    /**
     * Persist a new master password as a bcrypt hash in the local override
     * file. Returns [ok(bool), errorMessage(string)].
     */
    function attendance_master_password_save($newPassword)
    {
        $newPassword = (string)$newPassword;
        if ($newPassword === '') {
            return [false, 'The new master password cannot be empty.'];
        }

        $hash = password_hash($newPassword, PASSWORD_DEFAULT);
        if (!is_string($hash) || $hash === '') {
            return [false, 'Could not hash the new password on this server.'];
        }

        $path = attendance_master_password_local_path();
        $contents = "<?php\n"
            . "// Generated by attendance/masterPassword.php — do not edit by hand.\n"
            . "// Overrides the committed default in studentreport.config.php.\n"
            . "// This file is gitignored; keep a copy if you rebuild the server.\n"
            . "// Last updated: " . date('Y-m-d H:i:s') . "\n\n"
            . "return [\n"
            . "    'hash' => " . var_export($hash, true) . ",\n"
            . "];\n";

        // Write to a temp file then rename so a failed write cannot leave a
        // truncated file that would lock everyone out.
        $tmp = $path . '.tmp' . bin2hex(random_bytes(4));
        if (@file_put_contents($tmp, $contents, LOCK_EX) === false) {
            return [false, 'Could not write to ' . basename($path) . '. Check file permissions on the attendance folder.'];
        }

        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            return [false, 'Could not replace ' . basename($path) . '. Check file permissions on the attendance folder.'];
        }

        @chmod($path, 0640);

        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($path, true);
        }
        clearstatcache(true, $path);

        return [true, ''];
    }
}
