# Beulah Coop

Beulah Coop is a lightweight Savings & Loans Management system for a cooperative society. It provides role-based dashboards for admins and members, an Excel ledger import workflow, and a clean dashboard UI.

## Features

- Admin and Member dashboards
- Member management (add/edit/delete)
- Transaction management with export (CSV/PDF) and date filtering
- Excel import (members + transactions)
- Ledger downloads (PDF/Excel) for members
- 2FA flow (opt-in per user)
- Audit logging
- Modern dashboard UI with sidebar and charts

## Tech Stack

- PHP 8+
- MySQL
- PhpSpreadsheet
- TCPDF
- PHPMailer
- Chart.js
- Bootstrap 5

## Folder Structure

```
admin/            Admin dashboard and management pages
auth/             Login and 2FA pages
api/              JSON endpoints
assets/           CSS/JS assets
config/           Configuration (DB)
includes/         Shared layout, auth, helpers
member/           Member dashboard and exports
uploads/          Excel uploads (ignored in git)
```

## Setup

1. **Clone the repo**
2. **Install PHP dependencies**

```
composer install
```

3. **Create database**

Create a MySQL database named `beulah_coop` and import your schema.

4. **Configure DB**

Update credentials in `config/db.php`.

5. **Start local server**

Use XAMPP or any PHP server.

## Excel Import

Use the admin import page:

```
admin/import.php
```

Your Excel file should contain:

- `SUMMARY` sheet (Names + Coop No)
- Member sheets named `NO 1`, `NO 2`, etc.

The importer will:
- Upsert members
- Clear and re-import member transactions

## 2FA (Optional)

2FA is opt-in per user. Add a column to `users`:

```
ALTER TABLE users ADD COLUMN twofa_enabled TINYINT(1) NOT NULL DEFAULT 0;
```

Set to 1 to enable 2FA for that user.

## Notes

- Default member password = Coop No.
- `uploads/` is ignored by git.
- `2025COOP LEDGERS.xlsx` and mock images are ignored.

## License

Internal use.

