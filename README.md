# Campus Lost and Found Management System

A browser-based web system for Aydelore Abaquita, BSIT 3B. PHP 8.3 renders the interface and maintains server-side sessions. Firebase Authentication handles email/password accounts; Cloud Firestore stores item reports and activity logs.

## Firebase project

- Project ID: `campus-lost-and-found-dfb32`
- Web app: Campus Lost and Found Web
- Database: Firestore Standard, default database, Singapore (`asia-southeast1`).
- Sign-in: Firebase email/password.
- Rules: `firestore.rules`, restricting each user to `/users/{their Firebase UID}/items` and `/users/{their Firebase UID}/activity`.

The web API key in `app/firebase-config.php` identifies the Firebase project. It does not grant administrator access; Firebase Authentication and Firestore rules control access. No service-account keys are required or stored in this repository.

## Sessions and transactions

PHP uses regenerated session IDs, HttpOnly/SameSite cookies, CSRF tokens, and a 30-minute idle timeout. Firebase ID and refresh tokens stay in the server session. Expiring Firebase ID tokens are refreshed automatically. Passwords are sent securely to Firebase Authentication and are never stored by the app.

Three transactions are implemented in `FirebaseLostFoundItem::change()` in `app/Firebase.php`: **add**, **update**, and **delete**. Each commits the item change and its activity log atomically through Firestore. Update/delete read the record first and include its update-time precondition, so a concurrent change cannot be silently overwritten. A failed commit saves neither the item change nor its log. These use atomic commits with optimistic concurrency, without administrator credentials.

Search supports reference, item name, category, Lost/Found type, location, status, and reporter. Reports can be Open, Matched, or Claimed. Account ownership is enforced by Firestore rules as well as the server's user-specific document paths.

## Run locally

Requires PHP 8.3+ with cURL and a valid system CA certificate bundle. No local database is needed.

```sh
php -S localhost:8080 -t public
```

Open http://localhost:8080 and create an account using an email address and an 8-character minimum password. Previously created SQLite accounts and records are not automatically migrated.

## Deploy the web system

The included Dockerfile and `render.yaml` support a free Render web service. Choose New > Blueprint in Render and select this repository. No payment plan is configured. `APP_HTTPS=1` ensures secure cookies on the HTTPS deployment.

Records and accounts persist in Firebase when the PHP host restarts. Active PHP sessions may end on a host restart; users can sign in again.

GitHub Pages and plain Firebase Hosting do not execute this PHP application. Use the PHP-compatible Docker deployment. A public repository URL is not a deployed system URL. Deployment is not complete until a live URL has been verified.

Deploy rules with an authenticated Firebase CLI:

```sh
firebase deploy --only firestore:rules --project campus-lost-and-found-dfb32
```

The `app/db.php` and `app/LostFoundItem.php` files retain the previous PDO SQLite implementation as reference only; the current entry point uses Firebase, not those files. The source document's PDO/MySQL version is therefore different from this Firebase-backed implementation.

## Test

Run `python tests/smoke.py http://localhost:8080` against a development server. This creates disposable Firebase accounts and records to test registration, login, sessions, CRUD/search, escaping, CSRF, and account isolation. It deletes its test item on completion. Clearly prefixed test accounts and their audit entries remain for inspection; run it only against a project authorized for testing.

Run `php tests/firebase.php` to verify automatic token refresh, direct cross-user access denial, and an atomic failed commit against the configured project.
