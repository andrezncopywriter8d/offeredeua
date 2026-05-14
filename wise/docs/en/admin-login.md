# Admin Login

## Admin Path

The main admin entry point is:

- `/admin/`

![Admin login page](../assets/screenshots/admin-login-page.png)

## Password Source

The password is configured in:

- `settings.php`

Key:

- `adminPassword`

## Protection

Authentication stores a logged-in session and uses rate limiting for repeated failed attempts.
