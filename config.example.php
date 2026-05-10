<?php
/**
 * Copy this file to config.php and fill in the values.
 * config.php is gitignored — never commit real credentials.
 *
 * Local XAMPP defaults: host=127.0.0.1, user=root, pass='' (empty)
 * Production cPanel: use the user/db you created in MySQL Databases,
 * AND your Anthropic API key (for the AI draw-import feature).
 */

return [
    'db' => [
        'host' => '127.0.0.1',
        'port' => 3306,
        'name' => 'scorecan_db',          // local DB you create in phpMyAdmin
        'user' => 'root',                 // XAMPP default
        'pass' => '',                     // XAMPP default
        'charset' => 'utf8mb4',
    ],
    'tournament' => [
        'first_match_time' => '08:00',
        'slot_minutes'     => 45,
        'grounds'          => 4,
    ],
    // If set, login.php will refuse logins from any other IP. Leave null to allow all.
    'admin_ip_allowlist' => null,

    // Anthropic API key — used by /admin/import-draw.php to extract matches
    // from a draw image. Leave empty to disable the AI import feature.
    // Get one at https://console.anthropic.com/settings/keys
    'anthropic_api_key' => '',
];
