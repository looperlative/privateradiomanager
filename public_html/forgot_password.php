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
$message = '';
$error = '';

// Handle forgot password request
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    CSRF::validateRequest();
    $email = filter_var($_POST['email'], FILTER_VALIDATE_EMAIL);
    
    if (!$email) {
        $error = 'Please enter a valid email address.';
    } else {
        // Check if user exists
        $stmt = $db->query("SELECT id, username FROM users WHERE email = ?", [$email]);
        $user = $stmt->fetch();
        
        if ($user) {
            // Generate reset token
            $token = bin2hex(random_bytes(32));
            $expiresAt = date('Y-m-d H:i:s', strtotime('+1 hour')); // Token expires in 1 hour
            
            // Store token in database
            $db->query(
                "INSERT INTO password_reset_tokens (user_id, token, expires_at) VALUES (?, ?, ?)",
                [$user['id'], $token, $expiresAt]
            );
            
            // Send email
            $resetUrl = SITE_URL . '/reset_password.php?token=' . $token;
            $subject = 'Password Reset Request - ' . SITE_NAME;
            
            $body = "Hello " . $user['username'] . ",\n\n";
            $body .= "You have requested to reset your password for " . SITE_NAME . ".\n\n";
            $body .= "Please click the following link to reset your password:\n";
            $body .= $resetUrl . "\n\n";
            $body .= "This link will expire in 1 hour.\n\n";
            $body .= "If you did not request this password reset, please ignore this email.\n\n";
            $body .= "Best regards,\n";
            $body .= FROM_NAME;
            
            if (Mailer::send($email, $subject, $body)) {
                $message = 'Password reset instructions have been sent to your email address.';
            } else {
                $error = 'Failed to send password reset email. Please try again later.';
            }
        } else {
            // Don't reveal if email exists or not for security
            $message = 'If an account with that email exists, password reset instructions have been sent.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password - <?php echo SITE_NAME; ?></title>
    <link rel="stylesheet" href="/styles/main.css">
    <link rel="stylesheet" href="/styles/auth.css">
    <?php MobileSupport::renderMobileStyles(); ?>
</head>
<body>
    <div class="container">
        <div class="header">
            <div class="logo"><?php echo SITE_NAME; ?></div>
            <div class="subtitle">Forgot Password</div>
            <div class="description">
                Enter your email address and we'll send you instructions to reset your password.
            </div>
        </div>
        
        <?php if ($message): ?>
            <div class="message success"><?php echo htmlspecialchars($message); ?></div>
        <?php endif; ?>
        
        <?php if ($error): ?>
            <div class="message error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        
        <form method="POST">
            <?php echo CSRF::getTokenInput(); ?>
            <div class="form-group">
                <label for="email">Email Address:</label>
                <input type="email" id="email" name="email" required value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>">
            </div>
            
            <button type="submit" class="btn">Send Reset Instructions</button>
        </form>
        
        <div class="links">
            <a href="/index.php">← Back to Login</a>
        </div>
    </div>
</body>
</html>
