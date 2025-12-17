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

/**
 * Installation Script for Private Radio Manager
 * Run this once to set up the database and initial configuration
 */

// Security check - remove this file after installation
$home = getenv('HOME');
if (!$home && function_exists('posix_getuid') && function_exists('posix_getpwuid')) {
    $pw = posix_getpwuid(posix_getuid());
    if (is_array($pw) && isset($pw['dir'])) {
        $home = $pw['dir'];
    }
}

$privateConfigPath = $home ? (rtrim($home, '/') . '/.privateradiomanager/config.php') : '';
if ($privateConfigPath !== '' && file_exists($privateConfigPath)) {
    die('Installation already completed (private home config exists). Remove or rename ~/.privateradiomanager/config.php if you need to reinstall.');
}

$errors = [];
$success = [];

// Check if this is a POST request (form submission)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $recaptcha_site_key = trim($_POST['recaptcha_site_key'] ?? '');
    $recaptcha_secret_key = trim($_POST['recaptcha_secret_key'] ?? '');
    $admin_username = trim($_POST['admin_username'] ?? '');
    $admin_email = trim($_POST['admin_email'] ?? '');
    $admin_password = $_POST['admin_password'] ?? '';
    
    // Validate inputs
    if (empty($recaptcha_site_key) || empty($recaptcha_secret_key)) {
        $errors[] = 'reCAPTCHA keys are required';
    }
    if (empty($admin_username) || empty($admin_email) || empty($admin_password)) {
        $errors[] = 'Admin account details are required';
    }
    if (strlen($admin_password) < 6) {
        $errors[] = 'Admin password must be at least 6 characters';
    }
    
    if (empty($errors)) {
        try {
            // Persist reCAPTCHA keys to private home configuration
            $home = getenv('HOME');
            if (!$home && function_exists('posix_getuid') && function_exists('posix_getpwuid')) {
                $pw = posix_getpwuid(posix_getuid());
                if (is_array($pw) && isset($pw['dir'])) {
                    $home = $pw['dir'];
                }
            }

            if (!$home) {
                throw new Exception('Unable to determine HOME directory to write private config');
            }

            $privateDir = rtrim($home, '/') . '/.privateradiomanager';
            $privateConfigPath = $privateDir . '/config.php';
            if (!is_dir($privateDir)) {
                if (!mkdir($privateDir, 0700, true) && !is_dir($privateDir)) {
                    throw new Exception('Failed to create private config directory: ' . $privateDir);
                }
            }

            $privateConfig = [];
            if (file_exists($privateConfigPath)) {
                $loaded = require $privateConfigPath;
                $privateConfig = is_array($loaded) ? $loaded : [];
            }

            $privateConfig['RECAPTCHA_SITE_KEY'] = $recaptcha_site_key;
            $privateConfig['RECAPTCHA_SECRET_KEY'] = $recaptcha_secret_key;

            $export = "<?php\nreturn " . var_export($privateConfig, true) . ";\n";
            if (file_put_contents($privateConfigPath, $export) === false) {
                throw new Exception('Failed to write private config: ' . $privateConfigPath);
            }
            @chmod($privateConfigPath, 0600);

            $success[] = 'Private configuration updated with reCAPTCHA keys';
            
            // Test database connection
            require_once 'config.php';
            require_once 'includes/Database.php';
            
            $db = Database::getInstance();
            $success[] = 'Database connection successful';
            
            // Run database setup
            $sql_content = file_get_contents('setup_database.sql');
            $statements = explode(';', $sql_content);
            
            foreach ($statements as $statement) {
                $statement = trim($statement);
                if (!empty($statement) && !preg_match('/^--/', $statement)) {
                    $db->query($statement);
                }
            }
            $success[] = 'Database tables created';
            
            // Create admin user
            $password_hash = password_hash($admin_password, PASSWORD_DEFAULT);
            $db->query(
                "INSERT INTO users (username, email, password_hash, is_admin) VALUES (?, ?, ?, 1) 
                 ON DUPLICATE KEY UPDATE username = VALUES(username), email = VALUES(email), password_hash = VALUES(password_hash)",
                [$admin_username, $admin_email, $password_hash]
            );
            $success[] = 'Admin user created/updated';
            
            // Add admin to .htpasswd
            if (file_exists(HTPASSWD_FILE)) {
                $htpasswd_content = file_get_contents(HTPASSWD_FILE);
                $lines = explode("\n", $htpasswd_content);
                
                // Remove existing admin entry
                $lines = array_filter($lines, function($line) use ($admin_username) {
                    return strpos($line, $admin_username . ':') !== 0;
                });
                
                // Generate Apache MD5 hash (simplified version)
                $salt = substr(str_shuffle('abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789'), 0, 8);
                $hash = crypt($admin_password, '$apr1$' . $salt . '$');
                $lines[] = $admin_username . ':' . $hash;
                
                file_put_contents(HTPASSWD_FILE, implode("\n", array_filter($lines)));
                $success[] = 'Admin added to .htpasswd file';
            } else {
                $errors[] = '.htpasswd file not found at ' . HTPASSWD_FILE;
            }
            
            // Create installation marker
            if (empty($errors)) {
                file_put_contents('INSTALLED', date('Y-m-d H:i:s') . "\nInstallation completed successfully\n");
                $success[] = 'Installation completed! You can now delete install.php';
            }
            
        } catch (Exception $e) {
            $errors[] = 'Installation error: ' . $e->getMessage();
        }
    }
}

// Check system requirements
$requirements = [
    'PHP Version >= 7.4' => version_compare(PHP_VERSION, '7.4.0', '>='),
    'PDO Extension' => extension_loaded('pdo'),
    'PDO MySQL Extension' => extension_loaded('pdo_mysql'),
    'Mail Function' => function_exists('mail'),
    'Templates Directory' => is_dir('templates') && is_writable('templates'),
    'Includes Directory' => is_dir('includes') && is_readable('includes'),
];

if (is_string(HTPASSWD_FILE) && HTPASSWD_FILE !== '' && file_exists(HTPASSWD_FILE)) {
    $requirements['.htpasswd File Exists'] = true;
    $requirements['.htpasswd File Writable'] = is_writable(HTPASSWD_FILE);
} else {
    $requirements['.htpasswd File Exists'] = false;
}

if (is_string(PLAYBACK_LOG) && PLAYBACK_LOG !== '' && file_exists(PLAYBACK_LOG)) {
    $requirements['Playback Log Readable'] = is_readable(PLAYBACK_LOG);
} else {
    $requirements['Playback Log Exists'] = false;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Install - Private Radio Manager</title>
    <link rel="stylesheet" href="/styles/main.css">
    <link rel="stylesheet" href="/styles/auth.css">
    <?php MobileSupport::renderMobileStyles(); ?>
</head>
<body>
    <div class="container">
        <div class="logo">Private Radio Manager</div>
        <div class="subtitle">Installation Setup</div>
        
        <?php if (!empty($success)): ?>
            <div class="message success">
                <strong>Success!</strong><br>
                <?php foreach ($success as $msg): ?>
                    • <?php echo htmlspecialchars($msg); ?><br>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        
        <?php if (!empty($errors)): ?>
            <div class="message error">
                <strong>Errors:</strong><br>
                <?php foreach ($errors as $error): ?>
                    • <?php echo htmlspecialchars($error); ?><br>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        
        <div class="section">
            <h2>System Requirements</h2>
            <div class="requirements">
                <?php foreach ($requirements as $name => $status): ?>
                    <div class="req-item">
                        <div class="req-name"><?php echo htmlspecialchars($name); ?></div>
                        <div class="req-status <?php echo $status ? 'req-pass' : 'req-fail'; ?>">
                            <?php echo $status ? '✓ PASS' : '✗ FAIL'; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        
        <?php if (!file_exists('INSTALLED')): ?>
            <div class="section">
                <h2>Installation Configuration</h2>
                <form method="POST">
                    <div class="form-group">
                        <label for="recaptcha_site_key">reCAPTCHA Site Key:</label>
                        <input type="text" id="recaptcha_site_key" name="recaptcha_site_key" required
                               value="<?php echo htmlspecialchars($_POST['recaptcha_site_key'] ?? ''); ?>">
                        <div class="help-text">Get this from <a href="https://www.google.com/recaptcha/admin/create" target="_blank">Google reCAPTCHA</a></div>
                    </div>
                    
                    <div class="form-group">
                        <label for="recaptcha_secret_key">reCAPTCHA Secret Key:</label>
                        <input type="text" id="recaptcha_secret_key" name="recaptcha_secret_key" required
                               value="<?php echo htmlspecialchars($_POST['recaptcha_secret_key'] ?? ''); ?>">
                        <div class="help-text">Secret key from the same reCAPTCHA site</div>
                    </div>
                    
                    <div class="form-group">
                        <label for="admin_username">Admin Username:</label>
                        <input type="text" id="admin_username" name="admin_username" required
                               value="<?php echo htmlspecialchars($_POST['admin_username'] ?? 'admin'); ?>">
                    </div>
                    
                    <div class="form-group">
                        <label for="admin_email">Admin Email:</label>
                        <input type="email" id="admin_email" name="admin_email" required
                               value="<?php echo htmlspecialchars($_POST['admin_email'] ?? 'admin@example.com'); ?>">
                    </div>
                    
                    <div class="form-group">
                        <label for="admin_password">Admin Password:</label>
                        <input type="password" id="admin_password" name="admin_password" required minlength="6">
                        <div class="help-text">Minimum 6 characters</div>
                    </div>
                    
                    <button type="submit" class="btn" 
                            <?php echo array_search(false, $requirements) !== false ? 'disabled' : ''; ?>>
                        Install Private Radio Manager
                    </button>
                </form>
            </div>
        <?php else: ?>
            <div class="message success">
                <strong>Installation Complete!</strong><br>
                Private Radio Manager has been successfully installed. You can now:
                <ul>
                    <li><a href="index.php">Go to the home page</a></li>
                    <li>Delete this install.php file for security</li>
                    <li>Read the README.md for additional configuration</li>
                </ul>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>
