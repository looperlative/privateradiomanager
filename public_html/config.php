<?php
/**
 * Copyright (c) 2025 Robert Amstadt
 * 
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 * 
 * The above copyright notice and this permission notice shall be included in all
 * copies or substantial portions of the Software.
 * 
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
 * SOFTWARE.
 */

function getPrivateConfig(): array
{
    static $cfg = null;
    if (is_array($cfg)) {
        return $cfg;
    }

    $home = getenv('HOME');
    if (!$home && function_exists('posix_getuid') && function_exists('posix_getpwuid')) {
        $pw = posix_getpwuid(posix_getuid());
        if (is_array($pw) && isset($pw['dir'])) {
            $home = $pw['dir'];
        }
    }

    if (!$home) {
        $cfg = [];
        return $cfg;
    }

    $path = rtrim($home, '/') . '/.privateradiomanager/config.php';
    if (!file_exists($path)) {
        $cfg = [];
        return $cfg;
    }

    $loaded = require $path;
    $cfg = is_array($loaded) ? $loaded : [];
    return $cfg;
}

$PRIVATE_CONFIG = getPrivateConfig();

// Database configuration
define('DB_HOST', $PRIVATE_CONFIG['DB_HOST'] ?? 'localhost');
define('DB_NAME', $PRIVATE_CONFIG['DB_NAME'] ?? '');
define('DB_USER', $PRIVATE_CONFIG['DB_USER'] ?? '');
define('DB_PASS', $PRIVATE_CONFIG['DB_PASS'] ?? '');

// Site configuration
define('SITE_NAME', $PRIVATE_CONFIG['SITE_NAME'] ?? '');
define('SITE_URL', $PRIVATE_CONFIG['SITE_URL'] ?? '');

// reCAPTCHA configuration
define('RECAPTCHA_SITE_KEY', $PRIVATE_CONFIG['RECAPTCHA_SITE_KEY'] ?? '');
define('RECAPTCHA_SECRET_KEY', $PRIVATE_CONFIG['RECAPTCHA_SECRET_KEY'] ?? '');
define('RECAPTCHA_SCORE_THRESHOLD', 0.5); // For reCAPTCHA v3: 0.0 (bot) to 1.0 (human)

// File paths
define('HTPASSWD_FILE', $PRIVATE_CONFIG['HTPASSWD_FILE'] ?? '');
define('PLAYBACK_LOG', $PRIVATE_CONFIG['PLAYBACK_LOG'] ?? '');
define('EMAIL_TEMPLATE', $PRIVATE_CONFIG['EMAIL_TEMPLATE'] ?? (__DIR__ . '/templates/invite_email.txt'));

define('RADIO_BASE_DIR', $PRIVATE_CONFIG['RADIO_BASE_DIR'] ?? '');
define('FIX_ID3_SCRIPT', $PRIVATE_CONFIG['FIX_ID3_SCRIPT'] ?? (__DIR__ . '/fix_id3_tags.sh'));
define('METADATA_CACHE_PATH', $PRIVATE_CONFIG['METADATA_CACHE_PATH'] ?? '');

// Email configuration
define('FROM_EMAIL', $PRIVATE_CONFIG['FROM_EMAIL'] ?? '');
define('FROM_NAME', $PRIVATE_CONFIG['FROM_NAME'] ?? '');
define('MAIL_METHOD', $PRIVATE_CONFIG['MAIL_METHOD'] ?? 'php'); // 'php' or 'gmail'
define('GMAIL_USERNAME', $PRIVATE_CONFIG['GMAIL_USERNAME'] ?? '');
define('GMAIL_APP_PASSWORD', $PRIVATE_CONFIG['GMAIL_APP_PASSWORD'] ?? '');

// Security settings
define('SESSION_TIMEOUT', 14400); // 4 hour
define('MAX_LOGIN_ATTEMPTS', 5);
define('LOGIN_LOCKOUT_TIME', 900); // 15 minutes
define('INVITE_EXPIRY_DAYS', 7);
define('MAX_DAILY_INVITES', 3);

// Stream URLs
define('STREAM_URL', '/stream');
define('BACKUP_URL', '/backup');
define('TEST_URL', '/test');

// Mobile support
require_once __DIR__ . '/includes/MobileSupport.php';

// Mail support
require_once __DIR__ . '/includes/Mailer.php';

// Configure secure session settings
session_set_cookie_params([
    'lifetime' => SESSION_TIMEOUT,
    'path' => '/',
    'domain' => '',
    'secure' => true,
    'httponly' => true,
    'samesite' => 'Strict'
]);

// Start session
session_start();
?>
