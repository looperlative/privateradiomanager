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
require_once 'includes/Auth.php';
require_once 'includes/CSRF.php';

$db = Database::getInstance();
$auth = new Auth();
$message = '';
$error = '';
$token = $_GET['token'] ?? '';
$validToken = false;
$user = null;

// Validate token
if (!empty($token)) {
    $stmt = $db->query(
        "SELECT prt.*, u.username, u.email FROM password_reset_tokens prt 
         JOIN users u ON prt.user_id = u.id 
         WHERE prt.token = ? AND prt.expires_at > NOW() AND prt.used_at IS NULL",
        [$token]
    );
    $tokenData = $stmt->fetch();
    
    if ($tokenData) {
        $validToken = true;
        $user = $tokenData;
    } else {
        $error = 'Invalid or expired reset token.';
    }
} else {
    $error = 'No reset token provided.';
}

// Handle password reset
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $validToken) {
    CSRF::validateRequest();
    
    $new_password = $_POST['new_password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    
    if (empty($new_password) || empty($confirm_password)) {
        $error = 'All fields are required.';
    } elseif ($new_password !== $confirm_password) {
        $error = 'Passwords do not match.';
    } elseif (strlen($new_password) < 12) {
        $error = 'Password must be at least 12 characters long.';
    } elseif (!preg_match('/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[@$!%*?&#])[A-Za-z\d@$!%*?&#]/', $new_password)) {
        $error = 'Password must contain at least one uppercase letter, one lowercase letter, one number, and one special character.';
    } else {
        // Update password in database
        $new_password_hash = password_hash($new_password, PASSWORD_DEFAULT);
        $db->query("UPDATE users SET password_hash = ? WHERE id = ?", [$new_password_hash, $user['user_id']]);
        
        // Mark token as used
        $db->query("UPDATE password_reset_tokens SET used_at = NOW() WHERE token = ?", [$token]);
        
        // Update .htpasswd file
        $auth->updateHtpasswd($user['username'], $new_password);
        
        $message = 'Password reset successfully! You can now log in with your new password.';
        $validToken = false; // Hide the form
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password - <?php echo SITE_NAME; ?></title>
    <link rel="stylesheet" href="/styles/main.css">
    <link rel="stylesheet" href="/styles/auth.css">
    <?php MobileSupport::renderMobileStyles(); ?>
</head>
<body>
    <div class="container">
        <div class="header">
            <div class="logo"><?php echo SITE_NAME; ?></div>
            <div class="subtitle">Reset Password</div>
        </div>
        
        <?php if ($message): ?>
            <div class="message success"><?php echo htmlspecialchars($message); ?></div>
        <?php endif; ?>
        
        <?php if ($error): ?>
            <div class="message error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        
        <?php if ($validToken && $user): ?>
            <div class="user-info">
                Resetting password for: <strong><?php echo htmlspecialchars($user['email']); ?></strong>
            </div>
            
            <form method="POST">
                <?php echo CSRF::getTokenInput(); ?>
                <div class="form-group">
                    <label for="new_password">New Password:</label>
                    <input type="password" id="new_password" name="new_password" required minlength="12">
                    <small>Minimum 12 characters (must include uppercase, lowercase, number, and special character)</small>
                </div>
                
                <div class="form-group">
                    <label for="confirm_password">Confirm New Password:</label>
                    <input type="password" id="confirm_password" name="confirm_password" required>
                </div>
                
                <button type="submit" class="btn">Reset Password</button>
            </form>
        <?php endif; ?>
        
        <div class="links">
            <a href="/index.php">← Back to Login</a>
            <?php if (!$validToken): ?>
                <a href="/forgot_password.php">Request New Reset</a>
            <?php endif; ?>
        </div>
    </div>
    
    <script>
        // Client-side password confirmation validation
        document.addEventListener('DOMContentLoaded', function() {
            const newPassword = document.getElementById('new_password');
            const confirmPassword = document.getElementById('confirm_password');
            
            function validatePasswords() {
                if (confirmPassword.value && newPassword.value !== confirmPassword.value) {
                    confirmPassword.setCustomValidity('Passwords do not match');
                } else {
                    confirmPassword.setCustomValidity('');
                }
            }
            
            if (newPassword && confirmPassword) {
                newPassword.addEventListener('input', validatePasswords);
                confirmPassword.addEventListener('input', validatePasswords);
            }
        });
    </script>
</body>
</html>
