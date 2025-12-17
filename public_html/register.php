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

require_once 'config.php';
require_once 'includes/Database.php';
require_once 'includes/CSRF.php';

$db = Database::getInstance();
$error = '';
$success = false;

// Get token from URL
$token = $_GET['token'] ?? '';

if (empty($token)) {
    $error = 'Invalid invitation link.';
} else {
    // Verify token
    $stmt = $db->query(
        "SELECT * FROM invites WHERE invite_token = ? AND used_at IS NULL AND expires_at > NOW()",
        [$token]
    );
    $invite = $stmt->fetch();
    
    if (!$invite) {
        $error = 'This invitation has expired or is invalid.';
    }
}

// Handle registration
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$error) {
    CSRF::validateRequest();
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    
    if (empty($username) || empty($password)) {
        $error = 'Please fill in all fields.';
    } elseif ($password !== $confirm_password) {
        $error = 'Passwords do not match.';
    } elseif (strlen($password) < 12) {
        $error = 'Password must be at least 12 characters long.';
    } elseif (!preg_match('/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[@$!%*?&#])[A-Za-z\d@$!%*?&#]/', $password)) {
        $error = 'Password must contain at least one uppercase letter, one lowercase letter, one number, and one special character.';
    } else {
        // Check if username already exists
        $stmt = $db->query("SELECT id FROM users WHERE username = ?", [$username]);
        if ($stmt->fetch()) {
            $error = 'Username already exists.';
        } else {
            // Create user
            $passwordHash = password_hash($password, PASSWORD_DEFAULT);
            $db->query(
                "INSERT INTO users (username, email, password_hash) VALUES (?, ?, ?)",
                [$username, $invite['email'], $passwordHash]
            );
            
            // Mark invite as used
            $db->query(
                "UPDATE invites SET used_at = NOW() WHERE id = ?",
                [$invite['id']]
            );
            
            // Add to .htpasswd using Auth class method
            require_once 'includes/Auth.php';
            $auth = new Auth();
            $auth->updateHtpasswd($username, $password);
            
            $success = true;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create Account - <?php echo SITE_NAME; ?></title>
    <link rel="stylesheet" href="/styles/main.css">
    <link rel="stylesheet" href="/styles/auth.css">
    <?php MobileSupport::renderMobileStyles(); ?>
</head>
<body>
    <div class="container">
        <div class="logo"><?php echo SITE_NAME; ?></div>
        <div class="subtitle">Create Your Account</div>
        
        <?php if ($success): ?>
            <div class="success">
                <strong>Account created successfully!</strong><br>
                You can now log in with your username and password.
            </div>
            <div class="login-link">
                <a href="/index.php">Go to Login Page</a>
            </div>
        <?php elseif ($error): ?>
            <div class="error"><?php echo htmlspecialchars($error); ?></div>
            <?php if (strpos($error, 'invitation') !== false): ?>
                <div class="login-link">
                    <a href="/index.php">Back to Home</a>
                </div>
            <?php endif; ?>
        <?php else: ?>
            <div class="invite-info">
                You've been invited to join our private radio station community!<br>
                <strong>Email:</strong> <?php echo htmlspecialchars($invite['email']); ?>
            </div>
            
            <form method="POST">
                <?php echo CSRF::getTokenInput(); ?>
                <div class="form-group">
                    <label for="username">Choose a Username:</label>
                    <input type="text" id="username" name="username" required 
                           value="<?php echo htmlspecialchars($_POST['username'] ?? ''); ?>">
                </div>
                
                <div class="form-group">
                    <label for="password">Password:</label>
                    <input type="password" id="password" name="password" required minlength="12">
                    <small style="color: #666;">Minimum 12 characters (must include uppercase, lowercase, number, and special character)</small>
                </div>
                
                <div class="form-group">
                    <label for="confirm_password">Confirm Password:</label>
                    <input type="password" id="confirm_password" name="confirm_password" required>
                </div>
                
                <button type="submit" class="register-btn">Create Account</button>
            </form>
            
            <div class="login-link">
                Already have an account? <a href="/index.php">Login here</a>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>
