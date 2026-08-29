# CodeIgniter 4 Application Starter

## What is CodeIgniter?

CodeIgniter is a PHP full-stack web framework that is light, fast, flexible and secure.
More information can be found at the [official site](https://codeigniter.com).

This repository holds a composer-installable app starter.
It has been built from the
[development repository](https://github.com/codeigniter4/CodeIgniter4).

More information about the plans for version 4 can be found in [CodeIgniter 4](https://forum.codeigniter.com/forumdisplay.php?fid=28) on the forums.

You can read the [user guide](https://codeigniter.com/user_guide/)
corresponding to the latest version of the framework.

## Installation & updates

`composer create-project codeigniter4/appstarter` then `composer update` whenever
there is a new release of the framework.

When updating, check the release notes to see if there are any changes you might need to apply
to your `app` folder. The affected files can be copied or merged from
`vendor/codeigniter4/framework/app`.

## Setup

Copy `env` to `.env` and tailor for your app, specifically the baseURL
and any database settings.

## Production releases

An annotated Git release tag and its exact commit are the source of truth for application files. The retired `deploy-files.txt` manifest must not be used to assemble partial releases.

Production does not provide Node.js. Run `npm ci` and `npm run build` in a compatible local environment, then transfer the generated `public/build` directory with the release. On production, run `composer install --no-dev --optimize-autoloader` from the checked-out tag.

Before backup or migration, verify the configured database name from the protected production configuration without printing credentials. The expected database is `go808com_turofleet`; stop if the configured identity differs.

Store each production backup under `/home/go808com/ci4/deploy-backups/<timestamp>/`, retaining separate database and runtime artifacts. Record the deployed commit, verify the compressed database backup, and preserve checksums. Runtime backup and deployment must preserve `.env`, all `writable` data, the current `public/build`, and applicable runtime/error logs.

Apply application migrations with `php spark migrate -n App`. Never use `migrate:refresh` in production.

## Important Change with index.php

`index.php` is no longer in the root of the project! It has been moved inside the _public_ folder,
for better security and separation of components.

This means that you should configure your web server to "point" to your project's _public_ folder, and
not to the project root. A better practice would be to configure a virtual host to point there. A poor practice would be to point your web server to the project root and expect to enter _public/..._, as the rest of your logic and the
framework are exposed.

**Please** read the user guide for a better explanation of how CI4 works!

## Repository Management

We use GitHub issues, in our main repository, to track **BUGS** and to track approved **DEVELOPMENT** work packages.
We use our [forum](http://forum.codeigniter.com) to provide SUPPORT and to discuss
FEATURE REQUESTS.

This repository is a "distribution" one, built by our release preparation script.
Problems with it can be raised on our forum, or as issues in the main repository.

## Server Requirements

PHP version 8.2 or higher is required, with the following extensions installed:

- [intl](http://php.net/manual/en/intl.requirements.php)
- [mbstring](http://php.net/manual/en/mbstring.installation.php)

> [!WARNING]
>
> - The end of life date for PHP 7.4 was November 28, 2022.
> - The end of life date for PHP 8.0 was November 26, 2023.
> - The end of life date for PHP 8.1 was December 31, 2025.
> - If you are still using below PHP 8.2, you should upgrade immediately.
> - The end of life date for PHP 8.2 will be December 31, 2026.

Additionally, make sure that the following extensions are enabled in your PHP:

- json (enabled by default - don't turn it off)
- [mysqlnd](http://php.net/manual/en/mysqlnd.install.php) if you plan to use MySQL
- [libcurl](http://php.net/manual/en/curl.requirements.php) if you plan to use the HTTP\CURLRequest library

php spark migrate:refresh
php spark migrate --all
php spark db:seed DatabaseSeeder

php spark shield:user create
php spark shield:user addgroup

### FleetOS admin bootstrap after refresh

The default seeding flow now includes `AdminUserSeeder`, which can create/update your Shield admin account idempotently.

Set credentials locally using either:

1. `.env` variables (recommended):
   - `FLEETOS_ADMIN_EMAIL`
   - `FLEETOS_ADMIN_PASSWORD`
   - `FLEETOS_ADMIN_USERNAME` (optional)
   - `FLEETOS_ADMIN_GROUP` (optional, default `superadmin`)
2. `app/Config/Admin.php` copied from `app/Config/Admin.example.php` (gitignored).

Then run:

```bash
php spark db:seed DatabaseSeeder
```
