# Kraite Admin

Administration panel for the Kraite trading platform, built with Laravel 12 and Nova 5.

## Requirements

- PHP 8.4+
- MySQL
- Composer

## Setup

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate --seed
```

## Dependencies

- **kraitebot/core** — Core domain models and business logic (local path package)
- **laravel/nova** — Admin panel framework

## Access

Nova is available at `/nova`. Only users with `is_admin = 1` can log in.
