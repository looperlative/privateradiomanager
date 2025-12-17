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

class Mailer
{
    public static function send(string $to, string $subject, string $body, array $headers = []): bool
    {
        $method = MAIL_METHOD;
        
        if ($method === 'gmail') {
            return self::sendViaGmail($to, $subject, $body, $headers);
        } else {
            return self::sendViaPhp($to, $subject, $body, $headers);
        }
    }
    
    private static function sendViaPhp(string $to, string $subject, string $body, array $headers = []): bool
    {
        $headerString = '';
        if (empty($headers)) {
            $messageId = '<' . time() . '.' . md5($to . FROM_EMAIL) . '@' . parse_url(SITE_URL, PHP_URL_HOST) . '>';
            
            $headerString = "From: " . FROM_NAME . " <" . FROM_EMAIL . ">\r\n";
            $headerString .= "Reply-To: " . FROM_EMAIL . "\r\n";
            $headerString .= "Return-Path: " . FROM_EMAIL . "\r\n";
            $headerString .= "Message-ID: " . $messageId . "\r\n";
            $headerString .= "X-Mailer: PHP/" . phpversion() . "\r\n";
            $headerString .= "X-Priority: 3\r\n";
            $headerString .= "MIME-Version: 1.0\r\n";
            $headerString .= "Content-Type: text/plain; charset=UTF-8\r\n";
            $headerString .= "Content-Transfer-Encoding: 8bit\r\n";
        } else {
            $headerString = implode("\r\n", $headers);
        }
        
        return mail($to, $subject, $body, $headerString);
    }
    
    private static function sendViaGmail(string $to, string $subject, string $body, array $headers = []): bool
    {
        $username = GMAIL_USERNAME;
        $password = GMAIL_APP_PASSWORD;
        
        if (empty($username) || empty($password)) {
            error_log('Gmail credentials not configured');
            return false;
        }
        
        $from = FROM_EMAIL;
        $fromName = FROM_NAME;
        
        $socket = @fsockopen('ssl://smtp.gmail.com', 465, $errno, $errstr, 30);
        if (!$socket) {
            error_log("Gmail SMTP connection failed: $errstr ($errno)");
            return false;
        }
        
        stream_set_timeout($socket, 30);
        
        $response = fgets($socket, 515);
        if (substr($response, 0, 3) !== '220') {
            error_log("Gmail SMTP initial response failed: $response");
            fclose($socket);
            return false;
        }
        
        fputs($socket, "EHLO " . $_SERVER['SERVER_NAME'] . "\r\n");
        $response = '';
        while ($line = fgets($socket, 515)) {
            $response .= $line;
            if (substr($line, 3, 1) === ' ') break;
        }
        
        fputs($socket, "AUTH LOGIN\r\n");
        $response = fgets($socket, 515);
        if (substr($response, 0, 3) !== '334') {
            error_log("Gmail AUTH LOGIN failed: $response");
            fclose($socket);
            return false;
        }
        
        fputs($socket, base64_encode($username) . "\r\n");
        $response = fgets($socket, 515);
        if (substr($response, 0, 3) !== '334') {
            error_log("Gmail username failed: $response");
            fclose($socket);
            return false;
        }
        
        fputs($socket, base64_encode($password) . "\r\n");
        $response = fgets($socket, 515);
        if (substr($response, 0, 3) !== '235') {
            error_log("Gmail authentication failed: $response");
            fclose($socket);
            return false;
        }
        
        fputs($socket, "MAIL FROM: <$from>\r\n");
        $response = fgets($socket, 515);
        if (substr($response, 0, 3) !== '250') {
            error_log("Gmail MAIL FROM failed: $response");
            fclose($socket);
            return false;
        }
        
        fputs($socket, "RCPT TO: <$to>\r\n");
        $response = fgets($socket, 515);
        if (substr($response, 0, 3) !== '250') {
            error_log("Gmail RCPT TO failed: $response");
            fclose($socket);
            return false;
        }
        
        fputs($socket, "DATA\r\n");
        $response = fgets($socket, 515);
        if (substr($response, 0, 3) !== '354') {
            error_log("Gmail DATA failed: $response");
            fclose($socket);
            return false;
        }
        
        $messageId = '<' . time() . '.' . md5($to . $from) . '@gmail.com>';
        $date = date('r');
        
        $message = "Date: $date\r\n";
        $message .= "From: $fromName <$from>\r\n";
        $message .= "To: <$to>\r\n";
        $message .= "Subject: $subject\r\n";
        $message .= "Reply-To: $from\r\n";
        $message .= "Return-Path: $from\r\n";
        $message .= "Message-ID: $messageId\r\n";
        $message .= "X-Mailer: PHP/" . phpversion() . "\r\n";
        $message .= "X-Priority: 3\r\n";
        $message .= "MIME-Version: 1.0\r\n";
        $message .= "Content-Type: text/plain; charset=UTF-8\r\n";
        $message .= "Content-Transfer-Encoding: 8bit\r\n";
        $message .= "\r\n";
        $message .= $body . "\r\n";
        $message .= ".\r\n";
        
        fputs($socket, $message);
        $response = fgets($socket, 515);
        if (substr($response, 0, 3) !== '250') {
            error_log("Gmail message send failed: $response");
            fclose($socket);
            return false;
        }
        
        fputs($socket, "QUIT\r\n");
        fclose($socket);
        
        return true;
    }
}
