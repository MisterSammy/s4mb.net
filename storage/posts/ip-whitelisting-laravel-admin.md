---
title: "IP Whitelisting for Laravel Admin Panels"
date: 2025-01-09
excerpt: "Not everyone needs access to your admin panel from everywhere. Here's how to restrict sensitive routes to known IP addresses using Laravel Firewall."
tags: [laravel, security, admin, firewall]
slug: ip-whitelisting-laravel-admin
---

Your admin panel is the keys to the kingdom. User management, data exports, configuration changes—everything sensitive lives there. Even with strong authentication, exposing it to the entire internet is unnecessary risk.

IP whitelisting adds a layer of defense. Only requests from known IP addresses reach your admin routes. Everyone else gets a 403 before they can even attempt a login.

The `pragmarx/firewall` package makes this easy in Laravel.

## Installation

```bash
composer require pragmarx/firewall
```

Publish the configuration:

```bash
php artisan vendor:publish --provider="PragmaRX\Firewall\Vendor\Laravel\ServiceProvider"
```

## Configuration

Edit `config/firewall.php`:

```php
return [
    'enabled' => env('FIREWALL_ENABLED', true),

    // IPs that are blocked
    'blacklist' => [],

    // IPs that are allowed (when whitelist mode is enabled)
    'whitelist' => [
        '192.0.2.10',        // Office IP
        '198.51.100.25',     // CEO home
        '203.0.113.50',      // VPN exit
        '192.0.2.100',       // Remote employee
    ],

    'responses' => [
        'whitelist' => [
            'code' => 403,
            'message' => null,
            'view' => null,
            'redirect_to' => null,
            'abort' => false,
        ],
    ],

    // CIDR range support
    'enable_range_search' => true,

    // Country-based blocking (requires GeoIP database)
    'enable_country_search' => false,

    // Cache settings
    'cache_expire_time' => 60,

    // Rate limiting for attack detection
    'attack_blocker' => [
        'enabled' => [
            'ip' => true,
            'country' => false,
        ],
        'allowed_frequency' => [
            'ip' => [
                'requests' => 50,
                'seconds' => 60,
            ],
        ],
    ],
];
```

## Applying to Routes

Create a route group for admin routes:

```php
// routes/web.php
Route::middleware(['auth', 'firewall.whitelist'])
    ->prefix('admin')
    ->group(function () {
        Route::get('/', [AdminController::class, 'index']);
        Route::resource('users', UserAdminController::class);
        Route::resource('settings', SettingsController::class);
    });
```

The `firewall.whitelist` middleware checks the request IP against your whitelist. If it's not listed, the request is rejected immediately.

## For Filament Admin Panels

If you're using Filament, add the middleware to its configuration:

```php
// config/filament.php
'middleware' => [
    'auth' => [
        Authenticate::class,
    ],
    'base' => [
        // ... existing middleware
        \PragmaRX\Firewall\Middleware\FirewallWhitelist::class,
    ],
],
```

Or in Filament 3, in a panel provider:

```php
public function panel(Panel $panel): Panel
{
    return $panel
        ->default()
        ->middleware([
            \PragmaRX\Firewall\Middleware\FirewallWhitelist::class,
        ]);
}
```

## Environment-Based Configuration

Don't whitelist in development:

```php
'enabled' => env('FIREWALL_ENABLED', true),
```

```env
# .env.local
FIREWALL_ENABLED=false

# .env.production
FIREWALL_ENABLED=true
```

Or whitelist localhost for development:

```php
'whitelist' => [
    '127.0.0.1',
    '::1',           // IPv6 localhost
    // Production IPs...
],
```

## Managing IPs

For small teams, editing the config file works. For larger organizations, consider:

**Database storage:**

```php
// Enable database mode
'use_database' => true,
```

Then manage IPs via Artisan:

```bash
php artisan firewall:whitelist 192.0.2.100
php artisan firewall:remove 192.0.2.100
php artisan firewall:list
```

**Admin interface:**

Build a simple CRUD for managing allowed IPs. Store them in a database table and load them in the config:

```php
'whitelist' => cache()->remember('firewall.whitelist', 3600, function () {
    return AllowedIp::pluck('ip')->toArray();
}),
```

## CIDR Ranges

Allow entire subnets with CIDR notation:

```php
'whitelist' => [
    '192.0.2.0/24',        // Office network (docs example range)
    '10.0.0.0/8',          // Internal private network
],
```

Useful for office networks where individual IPs vary.

## Logging and Monitoring

Enable logging to track blocked requests:

```php
'enable_log' => true,
'log_stack' => 'security',  // Custom log channel
```

Create a dedicated log channel:

```php
// config/logging.php
'channels' => [
    'security' => [
        'driver' => 'daily',
        'path' => storage_path('logs/security.log'),
        'days' => 30,
    ],
],
```

Review logs for:
- Unexpected blocked requests (legitimate users from new IPs)
- Attack patterns (repeated attempts from unknown IPs)
- Configuration issues (whitelisted IPs still being blocked)

## Notifications

Get alerted when attacks are detected:

```php
'notifications' => [
    'enabled' => true,
    'users' => [
        'emails' => [
            'security@example.com',
        ],
    ],
    'channels' => [
        'slack' => ['enabled' => true],
        'mail' => ['enabled' => true],
    ],
],
```

## Handling Dynamic IPs

If team members have dynamic home IPs, options include:

**VPN.** Everyone connects through a VPN with a static exit IP. Whitelist that one IP.

**Self-service updates.** Build a page (outside the firewall) where authenticated users can register their current IP:

```php
Route::middleware(['auth'])->get('/register-ip', function () {
    $ip = request()->ip();
    
    AllowedIp::updateOrCreate(
        ['user_id' => auth()->id()],
        ['ip' => $ip, 'expires_at' => now()->addDays(7)]
    );
    
    cache()->forget('firewall.whitelist');
    
    return redirect('/admin');
});
```

**Time-limited access.** Store IPs with expiration and clean up old ones daily.

## Defense in Depth

IP whitelisting is one layer. Combine it with:

- **Strong authentication** (2FA, Passkeys)
- **Rate limiting** on login endpoints
- **Audit logging** of admin actions
- **Least privilege** roles and permissions
- **Separate admin subdomain** for additional isolation

No single measure is bulletproof. Layers of defense mean attackers must bypass multiple protections.

## Conclusion

IP whitelisting dramatically reduces your admin panel's attack surface. Instead of defending against the entire internet, you're defending against known, trusted networks.

The `pragmarx/firewall` package makes implementation straightforward—configure your IPs, apply the middleware, and unauthorized requests never reach your application code.

For internal tools and admin panels, it's one of the simplest security wins available.
