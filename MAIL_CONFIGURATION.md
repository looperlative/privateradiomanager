# Mail Configuration Guide

This application supports two methods for sending emails:

## 1. PHP Mail (Default)
Uses PHP's built-in `mail()` function. This is the default method and requires your server to have a properly configured mail transfer agent (MTA) like sendmail or postfix.

## 2. Gmail SMTP
Sends emails directly through Gmail's SMTP server using your Gmail account.

## Configuration

Edit your private configuration file at `~/.privateradiomanager/config.php` and add the following settings:

### For PHP Mail (Default)
```php
return [
    // ... other config options ...
    'FROM_EMAIL' => 'your-email@example.com',
    'FROM_NAME' => 'Your Radio Station',
    'MAIL_METHOD' => 'php', // or omit this line, 'php' is the default
];
```

### For Gmail SMTP
```php
return [
    // ... other config options ...
    'FROM_EMAIL' => 'your-email@gmail.com',
    'FROM_NAME' => 'Your Radio Station',
    'MAIL_METHOD' => 'gmail',
    'GMAIL_USERNAME' => 'your-email@gmail.com',
    'GMAIL_APP_PASSWORD' => 'your-app-specific-password',
];
```

## Setting Up Gmail App Password

To use Gmail SMTP, you need to create an App Password:

1. Go to your Google Account settings: https://myaccount.google.com/
2. Navigate to Security
3. Enable 2-Step Verification if not already enabled
4. Under "2-Step Verification", scroll down to "App passwords"
5. Click "App passwords"
6. Select "Mail" as the app and "Other" as the device
7. Enter a name like "Radio Manager" and click "Generate"
8. Copy the 16-character password (without spaces)
9. Use this password as the `GMAIL_APP_PASSWORD` in your config

**Important:** Never share your app password or commit it to version control.

## Testing

After configuring, test the mail functionality by:
- Using the "Forgot Password" feature
- Sending an invite from the dashboard
- Sending a password reset from the admin panel

Check your error logs if emails fail to send. For Gmail SMTP, errors will be logged with details about the connection or authentication failure.

## Troubleshooting

### PHP Mail Issues
- Ensure your server has a working MTA installed
- Check PHP's `mail()` function is not disabled
- Verify your server's hostname is properly configured

### Gmail SMTP Issues
- Verify 2-Step Verification is enabled on your Google account
- Ensure you're using an App Password, not your regular Gmail password
- Check that your server can connect to smtp.gmail.com on port 465
- Review error logs for specific connection or authentication errors
- Make sure your Gmail account is not blocking the connection (check Google's security alerts)

## Improving Email Deliverability (Avoiding Spam Filters)

Emails from automated systems are often flagged as spam. Here are steps to improve deliverability:

### 1. DNS Configuration (Critical for PHP Mail)

If using PHP mail, configure these DNS records for your domain:

**SPF Record** - Add a TXT record to authorize your server to send email:
```
v=spf1 ip4:YOUR_SERVER_IP ~all
```
Or if using Gmail:
```
v=spf1 include:_spf.google.com ~all
```

**DKIM Record** - Set up DomainKeys Identified Mail for email authentication:
- Generate DKIM keys on your server (use `opendkim` or similar)
- Add the public key as a TXT record in your DNS
- Configure your mail server to sign outgoing emails

**DMARC Record** - Add email authentication policy:
```
v=DMARC1; p=quarantine; rua=mailto:postmaster@yourdomain.com
```

### 2. Use a Proper From Address

- Use a real domain email address (e.g., `noreply@yourdomain.com`)
- Avoid free email providers (Gmail, Yahoo, etc.) as the FROM address when using PHP mail
- The FROM domain should match your server's domain or have proper SPF/DKIM setup

### 3. Gmail SMTP Advantages

Gmail SMTP automatically handles:
- SPF authentication (Google's servers are trusted)
- DKIM signing (Google signs all outgoing mail)
- IP reputation (Google's IPs have good reputation)

This is why Gmail SMTP often has better deliverability than PHP mail.

### 4. Content Best Practices

The email template has been optimized to avoid spam triggers:
- No excessive exclamation marks or ALL CAPS
- No misleading subject lines
- Clear, concise content without marketing language
- Proper unsubscribe/ignore instructions
- No URL shorteners or suspicious links

### 5. Warm Up Your Sending

- Start by sending a few emails per day
- Gradually increase volume over time
- Avoid sudden spikes in email volume

### 6. Monitor Your Reputation

- Check your domain/IP reputation at: https://mxtoolbox.com/blacklists.aspx
- Monitor bounce rates and spam complaints
- Set up a postmaster account to receive feedback

### 7. Ask Recipients to Whitelist

Instruct users to:
- Add your FROM address to their contacts
- Mark your emails as "Not Spam" if they land in junk
- Create a filter to always deliver your emails to inbox

### 8. Test Your Configuration

Use these tools to test email deliverability:
- https://www.mail-tester.com/ - Comprehensive spam score check
- https://mxtoolbox.com/emailhealth/ - Email health check
- Send test emails to multiple providers (Gmail, Outlook, Yahoo)

## Security Notes

- Always use HTTPS for your web application to protect credentials in transit
- Store sensitive configuration in `~/.privateradiomanager/config.php`, not in the web root
- Use App Passwords for Gmail, never your main account password
- Regularly rotate your App Passwords
