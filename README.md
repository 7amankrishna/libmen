# libmen — Library Management System (PHP + MySQL)

A complete library management web app that runs on **InfinityFree** (or any PHP 7.4+ /
MySQL shared hosting): Dashboard, Books, Borrowers, Issue/Return, Reports/Analytics,
staff login, CSV & PDF export. **No dummy data** — all tables start empty and are
filled through the UI.

![stack](https://img.shields.io/badge/PHP-7.4%2B-777BB4) ![mysql](https://img.shields.io/badge/MySQL-5.7%2B%2F8.x-4479A1)

## Features

| Section | What it does |
| --- | --- |
| **Dashboard** | Live totals (books, borrowers, issued, overdue), quick actions, recent issue records, books-by-status donut, audit log |
| **Books** | Search / filter / sort the collection, add & edit titles with cover upload, availability badges, collection-health donut, distribution bars, low-stock watchlist, per-book loan history |
| **Borrowers** | Register/edit members (auto `LIB-0001` IDs), directory with search/filter/pagination, member profile with reading activity, current loans, suspend/reactivate |
| **Issue / Return** | Lend a book (validates membership status, loan quota, copy availability, computes due date), return books (computes days-late fine), active-loan list with search |
| **Reports / Analytics** | Date-range selector, circulation / returns / overdue-rate / members stat cards with period-over-period deltas, daily loans-vs-returns chart, most-read subjects chart, overdue follow-ups with "send reminder", computed actionable insights, **Export CSV** and **Download PDF** (built-in dependency-free PDF writer) |
| **Auth** | Staff login (session + `password_hash`), audit log of every action, CSRF protection on all forms |

## Project structure

```
config/database.example.php   DB credentials template (copy to database.php)
database/schema.sql           Table definitions — STRUCTURE ONLY, no dummy data
includes/                     bootstrap, PDO connection, helpers, MiniPdf writer, layout
assets/                       CSS, JS, vendored Chart.js (works offline)
uploads/covers/               Uploaded book covers (make writable: chmod 777)
actions/                      POST endpoints (save/delete/issue/return/reminder/export)
index.php                     Dashboard
books.php / book_form.php / book_details.php
borrowers.php / member_form.php / member_details.php
issue_return.php
reports.php
install.php                   One-time setup wizard (DELETE after use)
login.php / logout.php
```

## Deploying on InfinityFree (step by step)

1. **Create the database.** Log in to your InfinityFree **hPanel** → **MySQL Databases** →
   create a database (e.g. `library`) and a user, then **assign the user to the database
   with ALL PRIVILEGES**. Note down:
   - Hostname (e.g. `sql310.epizy.com` — shown in the MySQL Databases page)
   - Database name (e.g. `epizy_12345678_library`)
   - Username (e.g. `epizy_12345678`)
   - Password
2. **Upload the files.** Upload everything in this folder to your `htdocs/` (or a
   subfolder) using the hPanel **File Manager** or an FTP client.
3. **Make uploads writable.** In the File Manager, set `uploads/covers/` to `777`
   (or `755`) so book covers can be saved.
4. **Run the installer.** Open `https://your-site.epizy.com/install.php` in your browser:
   - Step 1: paste the MySQL credentials from step 1 (it tests them, writes
     `config/database.php`, and creates the tables — all `IF NOT EXISTS`, still no data).
   - Step 2: create your admin account.
   - **Then delete `install.php`** from the server.
   - *Alternative:* skip the credential form — import `database/schema.sql` via
     **phpMyAdmin** (hPanel → phpMyAdmin → your DB → Import), manually copy
     `config/database.example.php` → `config/database.php` and fill it in, then open
     `install.php` just to create the admin account.
5. **Sign in** at `https://your-site.epizy.com/login.php` and start adding books and
   members. That's it — every number, chart and report is computed live from your data.

> Tip: `config/database.php` is protected by `.htaccess` and listed in `.gitignore`,
> so your real password is never exposed or committed. You can also set the
> `LIBMEN_DB_HOST/PORT/NAME/USER/PASS` environment variables instead of the file.

## Requirements

- PHP 7.4+ with `pdo_mysql` (InfinityFree provides this; pick PHP 8.x in hPanel → Select PHP Version)
- MySQL 5.7+ / 8.x (InnoDB, utf8mb4)
- No Composer, no npm build step — Chart.js is vendored in `assets/js/`

## Tunables

In `includes/functions.php`:

```php
const LOAN_DAYS_DEFAULT = 14;   // default loan period
const FINE_PER_DAY      = 10.00; // ₹ per day after due date
const LOW_STOCK_LIMIT   = 2;    // low-stock threshold for the watchlist
```

## Security notes

- `config/`, `database/`, `includes/`, `actions/` are blocked from direct web access via `.htaccess`
- All forms carry a CSRF token; all output is escaped (`htmlspecialchars`)
- Passwords stored with `password_hash()`; sessions regenerated on login
- Uploads are restricted to images ≤ 2 MB, and PHP execution inside `uploads/` is disabled
