# Coach Foundation HR Application

An internal system that runs the employee lifecycle end to end: the employee record, a per-app access matrix, onboarding and offboarding pipelines, imported form intake, and Zoho Sign exit letters.

Built to replace a process that spanned eight admin consoles and lived in one Word document plus the memory of whoever did it last.

---

## Why it exists

| Problem today | What this does |
|---|---|
| A hire touches 8 separate admin consoles | One form drives the whole pipeline |
| Offboarding is "reverse everything, by hand" | Ordered pipeline, access revoked first |
| Nobody can answer "who still has access?" | An access matrix that answers it in one screen |
| Licences stay paid for after people leave | Flags paid seats held by leavers |
| Exit letters typed by hand per department | Zoho Sign templates filled from the record |

The measurable goal: a hire takes one form instead of eight consoles, an exit revokes access in minutes rather than days, and nobody pays for a licence held by someone who left.

---

## Stack

- **Laravel 13** on **PHP 8.4**
- **Filament 5** admin panel
- **SQLite** for local development, **MySQL** in production
- **Zoho Sign** for exit letters
- PHPUnit — **183 tests, 413 assertions**

---

## Quick start

```bash
cd hr-app
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate --seed
php artisan serve --port=8140
```

Sign in at `http://localhost:8140/admin` with `admin@example.com` / `password`. That account is created by the seeder in the `local` environment only.

Run the tests:

```bash
cd hr-app && php artisan test
```

---

## Documentation

| Document | Contents |
|---|---|
| [APP.md](APP.md) | Full specification: data model, roles, pipelines, screens |
| [STATUS.md](STATUS.md) | Current state, what is done, what is blocked |
| [DEPLOYMENT.md](DEPLOYMENT.md) | Deploying to Cloudways |
| [PLAN.md](PLAN.md) | Original automation plan and the SOP it came from |
| [PREP.md](PREP.md) | Credentials and access needed per integration |
| [hr-app/README.md](hr-app/README.md) | Developer notes for the Laravel app |

---

## What the app does

### Employee record

Profile, department, manager, role template, joining and exit dates, birthday, employment type. Bank details, salary and home addresses are **encrypted at rest** and masked by default.

### Access matrix

Every person against every app, in one grid. The view that matters is **"exited but still has access"** — the question manual offboarding cannot answer. The dashboard also counts **paid seats held by leavers**, which is money leaving the business every billing cycle.

### Onboarding pipeline

Paperwork and bank forms go out, the contract is sent, then a **blocking signature gate**. Every provisioning step depends on that gate, so a missing signature stops the chain rather than being a checklist line someone skips. Provisioning steps come from the role template, ordered so the identity backbone (Google) and the vault (Bitwarden) come first.

### Offboarding pipeline

Steps are generated from the access the person **actually holds**, not from their role template, because the two drift and only the former is a real exposure.

1. Revocations first, highest-priority app leading, marked "Do this first"
2. Credential rotation for shared logins they could read
3. Data migration, waiting on *every* revocation, to the destination resolved from the department matrix
4. A +24 hour verify / delete / alias step
5. Relieving and experience letters
6. Completion summary

### Import review queue

Historical Typeform exports are staged, never applied automatically. Every row carries data-quality flags, and a **bank record is never auto-linked to a person by name** — not even an exact match — because the bank form collects no email address and two people can share a name. Only a deterministic key (hidden `employee_id` field or an email) may link a payout record.

---

## Roles

| Role | Sees | Can do |
|---|---|---|
| Super Admin | Everything | Everything |
| HR Admin | All but salary and bank | Run onboarding and offboarding |
| Finance | Payout data and salary | Verify and export payout data |
| Manager | Own reports only | Nothing destructive |
| Employee | Own record only | Update own contact details |

Field visibility is enforced at the **model layer**, not in Blade, so a restricted field cannot leak through an export or an API response.

Revealing a full bank account number requires a written reason and writes an audit row naming the user, reason, subject and IP before the value is shown.

---

## Zoho Sign integration

Four exit-letter templates are registered and verified against the live API.

```bash
php artisan hr:zoho-sign ping                      # connection check
php artisan hr:zoho-sign templates                 # list templates in Zoho
php artisan hr:zoho-sign verify --template=<id>    # confirm the field map
php artisan hr:zoho-sign preview --employee="Name" # dry run, no API call
```

### Field mapping is per template, by necessity

The four templates share no common vocabulary:

| Logical value | Relieving tech | Relieving ops | Experience (both) |
|---|---|---|---|
| Position | `Job Title` | `Job Title` | `Role` |
| Join date | `Join Date` | `Join date` | `Joining date` |
| Last day | `Last Date` | `End Date` | `Leaving date` |
| Full name | **absent** | Zoho fills | Zoho fills |

**Field type decides who fills a field**, and the type is read from the API rather than guessed from the label:

- `Textfield` and `CustomDate` — supplied by this application
- `Name`, `Date`, `Signature` — filled by Zoho from the recipient or during signing

Nothing sends unless a template is both linked to a Zoho template id **and** verified against the live API.

### Known limitation

**Sending documents via the API requires a higher Zoho Sign plan.** Reading templates works on the current licence; creating or sending a document returns:

> Upgrade Zoho Sign license to send documents via API.

The integration is complete and tested up to that boundary. Once the plan is upgraded, sending works with no code change.

---

## Commands

| Command | Purpose |
|---|---|
| `hr:import-typeform {paperwork\|bank} {path} [--dry-run]` | Stage a Typeform CSV export for review |
| `hr:rematch [--form=]` | Recompute match proposals after accepting rows |
| `hr:zoho-sign {ping\|exchange\|templates\|verify\|preview}` | Zoho Sign connection and letter tooling |

---

## Security

- `APP_KEY` encrypts bank details, salary and addresses. **Losing or rotating it makes that data unrecoverable.** Back it up, and never rotate without a re-encryption migration.
- Never commit `.env`, `*.csv`, or `database/*.sqlite`. All are gitignored; the database and CSVs contain real bank account numbers and home addresses.
- The Zoho refresh token does not expire. Treat it as a permanent credential.
- Generate Zoho credentials from a **service account**, not a personal login: a Self Client token dies with the user who created it, and an offboarding tool that breaks when someone is offboarded is a trap.

---

## Repository layout

```
hr-onboarding/
├── README.md            this file
├── APP.md               specification
├── STATUS.md            current state and blockers
├── DEPLOYMENT.md        Cloudways deployment
├── PLAN.md              original plan and source SOP
├── PREP.md              credentials checklist
├── pitch.html           stakeholder summary page
├── scripts/             deployment tooling
└── hr-app/              the Laravel application
    ├── app/
    │   ├── Enums/           roles, statuses, provisioning modes
    │   ├── Filament/        admin screens, widgets, the access matrix
    │   ├── Models/          employee, access, templates, process runs
    │   ├── Policies/        per-role and per-field authorisation
    │   └── Services/
    │       ├── Import/      CSV importer, normalisers, name matcher
    │       ├── Process/     onboarding and offboarding builders, task runner
    │       └── Zoho/        Zoho Sign client and letter service
    ├── database/
    │   ├── migrations/
    │   └── seeders/         departments, app catalog, role templates, letters
    └── tests/
```
