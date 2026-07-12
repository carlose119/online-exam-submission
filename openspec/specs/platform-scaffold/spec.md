# platform-scaffold Specification

## Purpose

Runnable Laravel 13 + Filament v5 + Livewire v3 skeleton with MariaDB, public storage, role-gated admin panel, seeded admin, and smoke gate. Foundation for all subsequent changes.

## Requirements

### Requirement: Runnable Laravel Skeleton

The system MUST boot from a fresh `composer create-project laravel/laravel` install on PHP 8.4.

#### Scenario: Skeleton boots

- GIVEN Laravel 13 skeleton installed
- WHEN `php artisan serve` runs
- THEN HTTP 200 at root URL

### Requirement: Filament v5 with Livewire v3 Stack

The system MUST have Filament v5 installed with panels enabled. Livewire v3 and Alpine.js MUST be present as Filament v5 dependencies.

#### Scenario: Filament panel installs

- GIVEN Laravel 13 skeleton
- WHEN `composer require filament/filament` and `filament:install --panels` run
- THEN `app/Providers/Filament/` panel provider exists and `/admin` route registered

### Requirement: MariaDB Connection

The system MUST connect to MariaDB 10.11 via `DB_CONNECTION=mysql`. Compatibility delta with MySQL MUST be documented.

#### Scenario: Database connects

- GIVEN MariaDB 10.11 with database `online_exam_submission`
- WHEN `php artisan migrate` runs
- THEN migrations execute without connection errors

### Requirement: Public Storage Disk

The system MUST configure `config/filesystems.php` `public` disk targeting `storage/app/public`. `php artisan storage:link` MUST create the symlink.

#### Scenario: Storage link accessible

- GIVEN public disk configured
- WHEN `php artisan storage:link` runs
- THEN files on the public disk are reachable via `/storage/<file>`

### Requirement: Users Database Schema

The `users` migration MUST define: `name`, `email` (unique), `password`, `role` (enum: ADMIN, TEACHER, STUDENT), `suspended_at` (nullable timestamp), timestamps.

#### Scenario: Users table created

- GIVEN empty database
- WHEN `php artisan migrate` runs
- THEN `users` table exists with all columns and unique email index

### Requirement: Role-Gated Admin Panel

The system MUST expose a single Filament panel at `/admin` gated to ADMIN and TEACHER roles. Unauthenticated requests MUST redirect to login.

#### Scenario: Admin panel access control

- GIVEN no session
- WHEN `/admin` is requested
- THEN Filament login renders
- AND a user with `role = 'ADMIN'` reaches the dashboard after login

### Requirement: Admin Seeder

`AdminUserSeeder` MUST create one admin with role ADMIN, known credentials, and hashed password. The seeder MUST be idempotent when re-run.

#### Scenario: Seeder produces reachable admin

- GIVEN empty `users` table
- WHEN `php artisan db:seed --class=AdminUserSeeder` runs
- THEN one ADMIN exists and authenticates at `/admin/login`

### Requirement: Stack Compatibility Verification

Laravel 13, Filament v5, and Livewire v3 version constraints MUST be pinned in `composer.json` and verified conflict-free at install.

#### Scenario: Composer install resolves

- GIVEN `composer.json` requires `laravel/laravel ^13` and `filament/filament ^5`
- WHEN `composer install` runs
- THEN all packages install without version conflicts

### Requirement: Smoke Boot Verification

The seeded admin MUST log in and reach the Filament dashboard after `php artisan serve` starts.

#### Scenario: Admin login smoke test

- GIVEN seeder completed and server running
- WHEN seeded credentials are submitted at `/admin/login`
- THEN user is redirected to the Filament dashboard
