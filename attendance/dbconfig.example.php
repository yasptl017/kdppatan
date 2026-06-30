<?php
/**
 * Database Configuration Template
 *
 * Copy this file to `dbconfig.php` and fill in your real credentials.
 *
 *   cp attendance/dbconfig.example.php attendance/dbconfig.php
 *
 * The real `dbconfig.php` is gitignored so your credentials will NEVER
 * be committed to the repository.
 *
 * For CLI tools (e.g. the migration runner) prefer environment variables
 * or the --host / --user / --pass / --dbname CLI flags, so credentials
 * stay out of the file system entirely.
 */

if (!isset($attendance_db_configs)) {
    /**
     * Each entry is a fallback connection. The first one that works wins.
     * Delete the entries you don't need.
     */
    $attendance_db_configs = [
        // Production / hosted connection (no real secrets here — set in your local copy)
        [
            'label'    => 'hosted-attendance',
            'host'     => 'localhost',
            'dbname'   => 'CHANGE_ME_HOSTED_DB_NAME',
            'username' => 'CHANGE_ME_HOSTED_DB_USER',
            'password' => 'CHANGE_ME_HOSTED_DB_PASSWORD',
        ],
    ];

    if (function_exists('attendance_is_local_runtime') && attendance_is_local_runtime()) {
        $attendance_db_configs[] = [
            'label'    => 'local-attendance',
            'host'     => 'localhost',
            'dbname'   => 'kdpatt',
            'username' => 'root',
            'password' => '',
        ];
    }
}
