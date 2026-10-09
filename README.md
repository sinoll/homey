# Homey (Yii Cloud)

Current release: **0.1.3** (`v0.1.3`).

Yii Cloud is a self-hosted file cloud built with Yii3, MySQL, and a responsive
Vue 3 progressive web app. It supports user accounts, private file storage,
folders, expiring read-only share links, and WebDAV access.

Licensed under the BSD 3-Clause License; see [LICENSE](./LICENSE).

## Requirements

- PHP 8.2 or later with PDO MySQL, and MySQL 8.0 or later.
- Composer plus Node.js/npm are needed on a build machine to prepare an upload
  package; neither is run by the browser installer.
- Upload the PHP `vendor/` dependencies and prebuilt `public/` frontend assets
  with the project files.
- PHP extensions required by Yii3, including PDO MySQL.

## Configure and initialize

1. Copy `.env.example` to `.env`. Configure the MySQL connection and
   `APP_STORAGE_PATH`; keep storage outside `public/` and writable by PHP.
2. Create the configured MySQL database.
3. Apply the SQL migrations in order, once each:

   ```sh
   mysql -u YOUR_DB_USER -p YOUR_DB_NAME < database/migrations/001_create_cloud_tables.sql
   mysql -u YOUR_DB_USER -p YOUR_DB_NAME < database/migrations/002_add_folders_and_shares.sql
   mysql -u YOUR_DB_USER -p YOUR_DB_NAME < database/migrations/003_add_file_trash.sql
   ```

   Migration 002 adds folders, shares, and file folder/updated-time metadata.
   Migration 003 adds soft deletion metadata used by the recycle bin. These
   migrations are additive and do not remove existing file rows. Back up the
   database before applying migrations to an existing installation.
4. Start the API with `composer serve`.
5. Start the web app in a separate terminal:

   ```sh
   cd frontend
   npm install
   npm run dev
   ```

   Vite proxies `/api` and `/dav` requests to `http://127.0.0.1:8080` by
   default. Set `YII_API_PROXY` if the API runs at a different local address.

For manual production deployment, run `npm run build` in `frontend/`, copy the
contents of `frontend/dist/` into `public/`, and use `public/` as the web root.
Route `/api/*` through `public/index.php`, `/dav/*` through `public/dav.php`,
and unknown browser paths (including `/share/*`) to the SPA `index.html`. The
included Apache rewrite rules do this when `mod_rewrite` is enabled. Use HTTPS
for all traffic; WebDAV uses Basic authentication with the account email and
password. Configure PHP and the web server request-body limit for uploads up
to 100 MB. The built-in `composer serve` command sets PHP's upload limit to
100 MB and request-body limit to 110 MB. In production, set
`upload_max_filesize=100M` and `post_max_size=110M`. `public/.user.ini` applies
those PHP limits with CGI/FastCGI, while `public/.htaccess` configures them for
mod_php. The service worker caches only app-shell files, never API responses or
private file content.

Registration is open in this MVP. Restrict access at the network or proxy layer
until an administrator/invitation workflow is implemented. Back up both the
database and the private storage directory.

## Browser installation on an existing Linux/Apache host

The project includes a one-time web installer at `public/install/index.php`.
It does not install Apache, MySQL, PHP, Composer, Node.js, or npm. Ask your
hosting provider to create an empty MySQL database and a dedicated database
user with permission to create and alter tables and indexes.

Prepare the upload as a complete application package:

1. Include the PHP dependency directory `vendor/`, the `database/migrations/`
   files, and the prebuilt web app in `public/` (`index.html`, `assets/`,
   `sw.js`, and the manifest). The browser installer does not run Composer or
   build the frontend on the server. If you change frontend source code, rebuild
   locally with `cd frontend && npm ci && npm run build` and copy `frontend/dist/`
   into `public/` before uploading.
2. Upload the project so the Apache document root points to its `public/`
   directory. Do not upload a local `.env`, `frontend/node_modules/`, or
   private storage files.

Visit `https://your-domain.example/install/`, enter the MySQL connection
details and, optionally, an absolute storage path outside `public/` (for example,
`/srv/homey/storage` on Linux or `D:\homey\storage` on Windows), and select **Test
database connection** before **Start installation**. If the storage path is left
blank, the installer uses `<application-root>/data/storage`. The PHP runtime must have
PDO MySQL enabled, and the Apache/PHP user needs read access to the application
and write access to `runtime/` and the storage directory. The installer creates
the database tables if the selected database is empty, or checks that migrations
001-003 are already applied; it refuses to modify an unrelated or partially
installed database. The submitted credentials are saved in
`runtime/installation.php` with owner-only permissions, outside Apache's
`public/` document root. Existing Apache environment variables take precedence
over this file.

After the installer reports success, delete `public/install/` using your
hosting file manager or FTP, open the site, and register the first account.
Use HTTPS before submitting database credentials. Registration is open in this
MVP, so restrict public access if needed. Back up the database and private
storage directory. The Apache document root must be `public/`, not the project
root; the included `.htaccess` routes API, WebDAV, and SPA requests.

## Features

- Register and sign in with an email and password.
- Upload, search, download, and move private files; deleted files can be
  restored from the recycle bin or permanently removed.
- Preview common images, PDF and DOCX documents, and text files in the browser;
  play supported audio and video formats with native browser controls. Public
  shared files of these types can also be previewed. DOCX rendering is performed
  locally in the browser; unsupported formats remain downloadable.
- Create, rename, move, and delete empty folders; nested folder trees are
  supported.
- Create download-only file or folder share links with optional expiration.
  Share tokens are random and only their hashes are stored. Folder links allow
  downloads of files in that folder tree; they do not grant upload or edit
  access. New shares default to expiring after seven days; clearing the expiry
  field creates a link that does not expire.
- Connect desktop or mobile WebDAV clients to `/dav/` with the account email
  and password. The endpoint supports standard file and directory operations
  through SabreDAV.
- Responsive web UI and installable PWA for desktop and mobile browsers.

## API

Successful API responses use a JSON envelope. Registration and login return a
bearer token that expires after 30 days. Send it as
`Authorization: Bearer <token>` for authenticated endpoints.

| Method | Path | Description |
| --- | --- | --- |
| `POST` | `/api/auth/register` | Register with `{"email":"...","password":"..."}` |
| `POST` | `/api/auth/login` | Sign in with the same JSON fields |
| `GET` | `/api/files?folderId={id}` | List files at root or in a folder |
| `GET` | `/api/files?search={term}` | Search file names across all directories and subdirectories; results include each file's folder path |
| `POST` | `/api/files` | Upload multipart field `file`; optional `folderId`; max 100 MB |
| `GET` | `/api/files/{id}` | Download an owned file; `?preview=1` serves supported preview types inline |
| `PATCH` | `/api/files/{id}` | Move an owned file with `{"folderId":"..."}` or `null` for root |
| `DELETE` | `/api/files/{id}` | Move an owned file to the recycle bin |
| `GET` | `/api/trash` | List the current user's deleted files |
| `POST` | `/api/trash/{id}/restore` | Restore a file to its original folder, or root if that folder no longer exists; returns `409` if the name is in use |
| `DELETE` | `/api/trash/{id}` | Permanently delete a trashed file and its stored content |
| `GET` | `/api/folders?parentId={id}` | List folders at root or under a parent |
| `POST` | `/api/folders` | Create with `{"name":"...","parentId":null}` |
| `PATCH` | `/api/folders/{id}` | Rename with `{"name":"..."}` or move with `{"parentId":null}` |
| `DELETE` | `/api/folders/{id}` | Delete an empty folder |
| `POST` | `/api/files/{id}/shares` | Create an optional-expiry file share |
| `POST` | `/api/folders/{id}/shares` | Create an optional-expiry folder share |
| `GET` | `/api/shares` | List the current user's shares |
| `DELETE` | `/api/shares/{id}` | Revoke a share |
| `GET` | `/api/shared/{token}` | Read public share details |
| `GET` | `/api/shared/{token}/files/{id}` | Download a shared file; `?preview=1` serves supported preview types inline |
| `*` | `/dav/` | WebDAV endpoint; use HTTP Basic authentication over HTTPS |

Client-provided MIME types are not trusted. Responses use a known content type
for supported filename extensions and fall back to `application/octet-stream`
for other downloads. Inline previews are restricted to supported images, PDF,
DOCX, text, audio, and video formats. A filename must be unique among files and
folders in its containing directory.
