# Campus Lost and Found Management System

PHP 8.3 + PDO SQLite application based on Aydelore Abaquita's Information Management 2 midterm, BSIT 3B. SQLite keeps the app self-contained; the original document used MySQL. All item fields and CRUD/search operations are retained.

## Features

- Account registration, password hashing, login/logout, regenerated PHP session IDs, 30-minute idle timeout, HttpOnly and SameSite cookies.
- Three database transactions: **add**, **update**, and **delete**. Each uses `beginTransaction()`, `commit()`, and rollback on failure, atomically saving both the item change and its activity log.
- Search by reference, name, category, type, location, status, or reporter.
- Open, Matched, and Claimed statuses; responsive forms and noticeboard.
- Prepared statements, CSRF tokens, output escaping, input validation, and ownership checks. Each account manages its own reports.

## Run locally

Requires PHP 8.3+ with PDO SQLite enabled:

```sh
php -S localhost:8080 -t public
```

Open http://localhost:8080 and create an account. The database is created automatically in `data/` outside the public document root. No default password or private credentials are shipped.

Or use Docker:

```sh
docker build -t campus-lost-found .
docker run --rm -p 8080:80 -v campus-data:/var/www/html/data campus-lost-found
```

## Deploy

The included Dockerfile and `render.yaml` support a Render Blueprint deployment. Sign in to Render, choose **New → Blueprint**, and select this public repository. It requests the free web-service plan. Set `APP_HTTPS=1` on HTTPS hosts.

**Free Render storage is ephemeral:** accounts and records reset on redeployment or service replacement. For durable use, choose a host with a persistent disk, mount it at `/var/www/html/data`, and ensure the web-server user can write to it. Do not enter sensitive personal information in a public classroom demo. GitHub Pages cannot execute PHP.

No deployed URL has been recorded yet. A repository URL is not a deployed system URL.

## Verification

Run `python tests/smoke.py http://localhost:8080` against a running development server. It checks registration, session access, add/search/update/delete, CSRF rejection, account isolation, and logout.
