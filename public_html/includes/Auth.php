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

require_once 'Database.php';

class Auth {
    private $db;
    
    public function __construct() {
        $this->db = Database::getInstance();
    }
    
    public function login($username, $password, $recaptcha_response) {
        // Verify reCAPTCHA only if keys are configured
        if ($this->isRecaptchaEnabled() && !$this->verifyRecaptcha($recaptcha_response)) {
            return ['success' => false, 'message' => 'reCAPTCHA verification failed'];
        }
        
        // Check for too many failed attempts
        if ($this->isIpBlocked($_SERVER['REMOTE_ADDR'])) {
            return ['success' => false, 'message' => 'Too many failed attempts. Please try again later.'];
        }
        
        // Get user from database
        $stmt = $this->db->query(
            "SELECT id, username, password_hash, email, is_admin, is_blocked FROM users WHERE username = ?",
            [$username]
        );
        $user = $stmt->fetch();
        
        if (!$user || !password_verify($password, $user['password_hash'])) {
            $this->logLoginAttempt($_SERVER['REMOTE_ADDR'], $username, false);
            return ['success' => false, 'message' => 'Invalid username or password'];
        }
        
        if ($user['is_blocked']) {
            return ['success' => false, 'message' => 'Account is blocked'];
        }
        
        // Successful login
        $this->logLoginAttempt($_SERVER['REMOTE_ADDR'], $username, true);
        $this->updateLastLogin($user['id']);
        
        // Regenerate session ID to prevent session fixation
        session_regenerate_id(true);
        
        // Set session
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['is_admin'] = $user['is_admin'];
        $_SESSION['login_time'] = time();
        
        // Update .htpasswd file
        $this->updateHtpasswd($username, $password);
        
        return ['success' => true, 'is_admin' => $user['is_admin']];
    }
    
    public function logout() {
        $_SESSION = [];
        
        if (ini_get("session.use_cookies")) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $params["path"], $params["domain"],
                $params["secure"], $params["httponly"]
            );
        }
        
        session_destroy();
        session_regenerate_id(true);
        return true;
    }
    
    public function isLoggedIn() {
        if (!isset($_SESSION['user_id']) || !isset($_SESSION['login_time'])) {
            return false;
        }
        
        // Check session timeout
        if (time() - $_SESSION['login_time'] > SESSION_TIMEOUT) {
            $this->logout();
            return false;
        }
        
        return true;
    }
    
    public function isAdmin() {
        return $this->isLoggedIn() && isset($_SESSION['is_admin']) && $_SESSION['is_admin'];
    }
    
    public function requireLogin() {
        if (!$this->isLoggedIn()) {
            header('Location: /index.php');
            exit;
        }
    }
    
    public function requireAdmin() {
        $this->requireLogin();
        if (!$this->isAdmin()) {
            header('Location: /dashboard.php');
            exit;
        }
    }
    
    public function isRecaptchaEnabled() {
        return defined('RECAPTCHA_SITE_KEY') && defined('RECAPTCHA_SECRET_KEY') && 
               RECAPTCHA_SITE_KEY !== 'YOUR_RECAPTCHA_SITE_KEY' && 
               RECAPTCHA_SECRET_KEY !== 'YOUR_RECAPTCHA_SECRET_KEY' &&
               !empty(RECAPTCHA_SITE_KEY) && !empty(RECAPTCHA_SECRET_KEY);
    }
    
    private function verifyRecaptcha($response) {
        if (!$this->isRecaptchaEnabled()) return true;
        if (empty($response)) return false;
        
        $data = [
            'secret' => RECAPTCHA_SECRET_KEY,
            'response' => $response,
            'remoteip' => $_SERVER['REMOTE_ADDR']
        ];
        
        $options = [
            'http' => [
                'header' => "Content-type: application/x-www-form-urlencoded\r\n",
                'method' => 'POST',
                'content' => http_build_query($data)
            ]
        ];
        
        $context = stream_context_create($options);
        $result = file_get_contents('https://www.google.com/recaptcha/api/siteverify', false, $context);
        $json = json_decode($result, true);
        
        // For reCAPTCHA v3, check both success and score
        if (isset($json['success']) && $json['success'] === true) {
            // reCAPTCHA v3 returns a score between 0.0 and 1.0
            // Higher scores indicate more likely human interaction
            // You can adjust this threshold based on your needs
            $score_threshold = defined('RECAPTCHA_SCORE_THRESHOLD') ? RECAPTCHA_SCORE_THRESHOLD : 0.5;
            
            if (isset($json['score'])) {
                return $json['score'] >= $score_threshold;
            }
            
            // Fallback for reCAPTCHA v2 (no score field)
            return true;
        }
        
        return false;
    }
    
    private function isIpBlocked($ip) {
        $stmt = $this->db->query(
            "SELECT COUNT(*) as attempts FROM login_attempts 
             WHERE ip_address = ? AND success = 0 AND attempted_at > DATE_SUB(NOW(), INTERVAL ? SECOND)",
            [$ip, LOGIN_LOCKOUT_TIME]
        );
        $result = $stmt->fetch();
        
        return $result['attempts'] >= MAX_LOGIN_ATTEMPTS;
    }
    
    private function logLoginAttempt($ip, $username, $success) {
        $this->db->query(
            "INSERT INTO login_attempts (ip_address, username, success) VALUES (?, ?, ?)",
            [$ip, $username, $success ? 1 : 0]
        );
    }
    
    private function updateLastLogin($userId) {
        $this->db->query(
            "UPDATE users SET last_login = NOW() WHERE id = ?",
            [$userId]
        );
    }
    
    public function updateHtpasswd($username, $password) {
        $htpasswd_content = file_get_contents(HTPASSWD_FILE);
        $lines = explode("\n", $htpasswd_content);
        $updated = false;
        
        // Generate Apache MD5 hash
        $hash = $this->generateApacheMd5($password);
        $new_entry = $username . ':' . $hash;
        
        // Update existing user or add new one
        for ($i = 0; $i < count($lines); $i++) {
            if (strpos($lines[$i], $username . ':') === 0) {
                $lines[$i] = $new_entry;
                $updated = true;
                break;
            }
        }
        
        if (!$updated) {
            $lines[] = $new_entry;
        }
        
        file_put_contents(HTPASSWD_FILE, implode("\n", array_filter($lines)));
    }
    
    private function generateApacheMd5($password) {
        $salt = substr(str_shuffle('abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789./'), 0, 8);
        $ctx = $password . '$apr1$' . $salt;
        $final = md5($password . $salt . $password, true);
        
        for ($i = strlen($password); $i > 0; $i -= 16) {
            $ctx .= substr($final, 0, min(16, $i));
        }
        
        for ($i = strlen($password); $i > 0; $i >>= 1) {
            $ctx .= ($i & 1) ? chr(0) : $password[0];
        }
        
        $final = md5($ctx, true);
        
        for ($i = 0; $i < 1000; $i++) {
            $new_ctx = '';
            if ($i & 1) {
                $new_ctx .= $password;
            } else {
                $new_ctx .= $final;
            }
            if ($i % 3) {
                $new_ctx .= $salt;
            }
            if ($i % 7) {
                $new_ctx .= $password;
            }
            if ($i & 1) {
                $new_ctx .= $final;
            } else {
                $new_ctx .= $password;
            }
            $final = md5($new_ctx, true);
        }
        
        $hash = '';
        $hash .= $this->to64(ord($final[0]) << 16 | ord($final[6]) << 8 | ord($final[12]), 4);
        $hash .= $this->to64(ord($final[1]) << 16 | ord($final[7]) << 8 | ord($final[13]), 4);
        $hash .= $this->to64(ord($final[2]) << 16 | ord($final[8]) << 8 | ord($final[14]), 4);
        $hash .= $this->to64(ord($final[3]) << 16 | ord($final[9]) << 8 | ord($final[15]), 4);
        $hash .= $this->to64(ord($final[4]) << 16 | ord($final[10]) << 8 | ord($final[5]), 4);
        $hash .= $this->to64(ord($final[11]), 2);
        
        return '$apr1$' . $salt . '$' . $hash;
    }
    
    private function to64($v, $n) {
        $itoa64 = './0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';
        $ret = '';
        while (--$n >= 0) {
            $ret .= $itoa64[$v & 0x3f];
            $v >>= 6;
        }
        return $ret;
    }
    
    /**
     * Generate a secure password reset token
     */
    public function generatePasswordResetToken($userId) {
        $token = bin2hex(random_bytes(32));
        $expiresAt = date('Y-m-d H:i:s', strtotime('+1 hour'));
        
        // Clean up old tokens for this user
        $this->db->query("DELETE FROM password_reset_tokens WHERE user_id = ?", [$userId]);
        
        // Insert new token
        $this->db->query(
            "INSERT INTO password_reset_tokens (user_id, token, expires_at) VALUES (?, ?, ?)",
            [$userId, $token, $expiresAt]
        );
        
        return $token;
    }
    
    /**
     * Validate a password reset token
     */
    public function validatePasswordResetToken($token) {
        $stmt = $this->db->query(
            "SELECT prt.*, u.username, u.email FROM password_reset_tokens prt 
             JOIN users u ON prt.user_id = u.id 
             WHERE prt.token = ? AND prt.expires_at > NOW() AND prt.used_at IS NULL",
            [$token]
        );
        
        return $stmt->fetch();
    }
    
    /**
     * Use a password reset token to change password
     */
    public function resetPasswordWithToken($token, $newPassword) {
        $tokenData = $this->validatePasswordResetToken($token);
        
        if (!$tokenData) {
            return ['success' => false, 'message' => 'Invalid or expired reset token'];
        }
        
        if (strlen($newPassword) < 12) {
            return ['success' => false, 'message' => 'Password must be at least 12 characters long'];
        }
        
        if (!preg_match('/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[@$!%*?&#])[A-Za-z\d@$!%*?&#]/', $newPassword)) {
            return ['success' => false, 'message' => 'Password must contain at least one uppercase letter, one lowercase letter, one number, and one special character'];
        }
        
        // Update password
        $passwordHash = password_hash($newPassword, PASSWORD_DEFAULT);
        $this->db->query("UPDATE users SET password_hash = ? WHERE id = ?", [$passwordHash, $tokenData['user_id']]);
        
        // Mark token as used
        $this->db->query("UPDATE password_reset_tokens SET used_at = NOW() WHERE token = ?", [$token]);
        
        // Update .htpasswd file
        $this->updateHtpasswd($tokenData['username'], $newPassword);
        
        return ['success' => true, 'message' => 'Password reset successfully'];
    }
    
    /**
     * Clean up expired password reset tokens
     */
    public function cleanupExpiredTokens() {
        $this->db->query("DELETE FROM password_reset_tokens WHERE expires_at < NOW() AND used_at IS NULL");
    }
}
?>
