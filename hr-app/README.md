# Coach Foundation HR App

Employee lifecycle system: employee records, per-app access matrix, onboarding and offboarding pipelines, and Typeform/Zoho Sign intake.

Specification: [`../APP.md`](../APP.md) · Current state: [`../STATUS.md`](../STATUS.md)

## Requirements

- PHP 8.4 or newer on PATH
- Composer 2.x
- SQLite for local dev; MySQL in production

## Setup from a fresh clone

```bash
composer install && cp .env.example .env && php artisan key:generate && php artisan migrate --seed
```

Then serve it:

```bash
php artisan serve --port=8140
```

Sign in at `http://localhost:8140/admin` with `admin@example.com` / `password` (created by the seeder in the `local` environment only).

## Tests

```bash
php artisan test
```

## Importing the historical Typeform exports

Put the CSV exports in `storage/imports/` (gitignored — they contain bank details and home addresses).

Always dry-run first:

```bash
php artisan hr:import-typeform paperwork storage/imports/paperwork.csv --dry-run
```

Then import for real. Run **paperwork before bank**: the paperwork form carries email addresses, so it creates the identities that bank rows are matched against.

```bash
php artisan hr:import-typeform paperwork storage/imports/paperwork.csv
```

```bash
php artisan hr:import-typeform bank storage/imports/bank.csv
```

Nothing is written to an employee record by the import. Rows land in `form_submissions` with a proposed match and per-row data-quality flags for review.

### Why bank rows always need a human

The bank form collects no email address, so a name is the only available key, and the two exports spell the same person differently. A bank row is therefore **never** auto-linked, not even on an exact name match, because two people can share a name and the cost of being wrong is paying the wrong person. Only a deterministic key (the hidden `employee_id` field, or an email) may link a payout record automatically.

**Fix this at the source:** add a hidden field named `employee_id` to both Typeforms so future submissions link with no ambiguity.

### Refreshing match proposals

Match confidence is calculated at import time. Because paperwork rows only create people once a human accepts them, bank rows imported first are scored against an empty directory. After accepting paperwork rows, refresh the proposals:

```bash
php artisan hr:rematch
```

The review modal recomputes candidate similarity live, so its dropdown is correct even before you run this.

## Screens

| Screen | What it is for |
|---|---|
| Dashboard | Headline stats, access held by leavers, upcoming birthdays and anniversaries |
| Employees | Directory with status tabs and risk filters |
| Employee detail | Profile, gated payout tab with audited reveal, per-app access |
| Access matrix | Everyone against every app, with wasted-seat and exposure counts |
| Import review | Staged submissions with data-quality flags and match confirmation |
| Role templates | What each role receives by default |
| App catalog | Provisioning mode and the task-card instructions for manual apps |
| Onboarding & exits | Pipeline runs with ordered, dependency-gated steps |
| Audit log | Append-only record, highlighting payout reveals |

## Pipelines

Start a run from a person's page. Steps are generated from data, and the ordering rules are enforced rather than remembered:

- **Onboarding** blocks every provisioning step behind a signature gate. Nothing is granted until the contract is signed, which is what the SOP requires but a paper checklist cannot enforce.
- **Offboarding** generates steps from the access the person actually holds, revocations first (Google leads), then credential rotation, then data migration to the destination resolved from the department matrix, then the +24h verify/delete/alias step, then letters.

Two rules the runner enforces:

- A blocked step cannot be completed.
- A **manual step cannot be completed without evidence** — an account ID, an email, or a note. A step you can tick without recording what you did is a checkbox that lies.

Steps labelled "Auto" cannot execute yet, because no integration credentials exist. They behave as manual steps until the adapters land; the pipeline shape does not change when they do.

## Security

- `APP_KEY` encrypts bank details, salary, and addresses. **Losing or rotating it makes that data unrecoverable.** Back it up in Bitwarden and never rotate without a re-encryption migration.
- Bank fields are masked to the last four digits by default. A full reveal requires the Finance or Super Admin role and writes an audit row with a reason.
- Never commit `.env`, `*.csv`, or anything in `storage/imports/`.

## Layout

| Path | Contents |
|---|---|
| `app/Enums` | Roles, statuses, provisioning modes |
| `app/Models` | Employee, payment details, apps, access matrix, process runs |
| `app/Policies` | Per-role and per-field authorisation |
| `app/Services/Import` | CSV importer, value normalizers, name matcher |
| `app/Services/AuditLogger.php` | Audit trail, including payment reveals |
| `database/seeders` | Departments, app catalog with task-card instructions, role templates |
