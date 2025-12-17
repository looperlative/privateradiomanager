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

class MobileSupport
{
    private static ?bool $isMobile = null;

    public static function isMobileClient(): bool
    {
        if (self::$isMobile !== null) {
            return self::$isMobile;
        }

        if (isset($_GET['mobile'])) {
            $value = strtolower((string)$_GET['mobile']);
            if ($value === '1' || $value === 'true' || $value === 'yes' || $value === 'on') {
                self::$isMobile = true;
                return true;
            }
            if ($value === '0' || $value === 'false' || $value === 'no' || $value === 'off') {
                self::$isMobile = false;
                return false;
            }
        }

        $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
        if ($ua === '') {
            self::$isMobile = false;
            return false;
        }

        self::$isMobile = (bool)preg_match('/Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini|Mobile/i', $ua);
        return self::$isMobile;
    }

    public static function renderMobileMetaTags(): void
    {
        if (!self::isMobileClient()) {
            return;
        }

        $hasViewport = false;
        foreach (headers_list() as $header) {
            if (stripos($header, 'viewport') !== false) {
                $hasViewport = true;
                break;
            }
        }

        if (!$hasViewport) {
            echo '<meta name="viewport" content="width=device-width, initial-scale=1.0">' . "\n";
        }
    }

    public static function renderMobileStyles(): void
    {
        if (!self::isMobileClient()) {
            return;
        }

        echo '<link rel="stylesheet" href="/styles/mobile.css">' . "\n";
    }

    public static function renderMobileHead(): void
    {
        self::renderMobileMetaTags();
        self::renderMobileStyles();
    }
}
