<?php
/*
 * TDC Maintenance Tracker – settings
 *
 * Copy this file to config.php (in the same folder) and fill it in.
 * config.php is never committed to GitHub, so your passwords stay on the server.
 */

// The one password the whole office uses to sign in.
// Changing it later signs every phone and computer out.
define('OFFICE_PASSWORD', 'change-me');

// MySQL database from Hostinger hPanel → Databases → Management.
define('DB_HOST', 'localhost');
define('DB_NAME', 'u000000000_tracker');
define('DB_USER', 'u000000000_tracker');
define('DB_PASS', 'database-password-here');

// Dental Dashboard tiles (optional). Easiest: leave this empty and use Setup › Dashboard tiles.
// A value here (at least 32 random characters) wins over the one made in Setup.
define('TILE_TOKEN', '');
// The tracker's own web address, used for the links on the tiles (optional).
// define('APP_URL', 'https://your-tracker-site.example');
