# STATUS — Coach Foundation HR App

**Updated:** 2026-08-12

---

## End goal (north star)

One system that runs the whole employee lifecycle: every hire, exit, access grant, leave and #champions announcement executed from a single source of truth, using tools the company already pays for. Humans keep every decision that involves money or judgment; the system does the mechanical work and remembers who has access to what.

The measurable payoff: a hire takes one form instead of eight consoles, an exit revokes access in minutes instead of days, and no one ever pays for a licence held by someone who left.

---

## Done

**Phase 0 — Foundations (complete)**
- Laravel 13.25 + Filament 5.7.6 scaffolded at `hr-app/`, SQLite for dev.
- Login verified end to end in the browser: seeded admin signs in, dashboard renders, no console or server errors.
- Panel access gated by role: the `employee` role cannot open the admin panel.

**Phase 1 — Data layer and security (complete)**
- 13 migrations: employees, payment details, apps, access matrix, role templates, process runs and tasks, documents, form submissions, audit logs.
- Bank fields, salary, and home addresses encrypted at rest, verified against the raw DB columns (not just via the model).
- `account_last4` derived on save so the UI masks without decrypting.
- Secrets excluded from array/JSON serialisation.
- Five roles with policies: Super Admin, HR Admin, Finance, Manager, Employee.
- Model-layer redaction (`toVisibleArray`) so restricted fields cannot leak through an export or API response.
- `AuditLogger` service; payment reveals require a reason.

**Phase 1 — Seed data (complete)**
- 8 departments carrying the offboarding data-migration matrix from the SOP.
- 9 apps with real click-path instructions for the manual cards (Google and Bitwarden), and offboarding priority ordering (Google first).
- 9 role templates with per-app licence tiers and scopes. Seeders are idempotent.

**Phase 1 — CSV importer (complete)**
- `hr:import-typeform {paperwork|bank} {path} [--dry-run]`.
- Normalizers for every dirty-data shape found in the real exports.
- Staging into `form_submissions` with per-row issue flags and a review queue; nothing is applied to a person automatically.

**Run against the real files:**

| File | Read | Staged | Quarantined | Flagged |
|---|---|---|---|---|
| Paperwork | 36 | 32 | 4 test rows | 3 |
| Bank | 72 | 69 | 3 test rows | 22 |

Flags raised: 12 bank names in the country column, 5 IBAN cells repeating the account number, 4 company payees, 2 routing numbers in the IBAN column, 1 duplicated SWIFT, 1 currency cell containing an amount, 2 dates missing a year, 1 job title in a date field.

**Phase 2 — Screens (complete)**
- **Dashboard** — five headline stats plus two live tables: access held by people who have left (with an inline "mark revoked"), and birthdays/anniversaries in the next 30 days.
- **Employee directory** — status tabs (Current / Joining / Leaving / Exited / All), filters including "exited but still has access" and "no work account yet", and a live-access count that turns red for leavers.
- **Employee detail** — Profile and Payout tabs, salary and contact sections that disappear entirely for roles that may not see them, and an **audited reveal**: a reason of at least 10 characters is mandatory, and the account number is only decrypted after the audit row is written.
- **Access relation manager** — grant, revoke, apply role template, and a "How to" task card rendering the real console click-path from the SOP. Manual apps require an account ID as evidence before they can be marked granted.
- **Access matrix** — employees × apps grid with scope switching, search, sticky name column, and three headline numbers: exited-with-access, paid seats held by leavers, and total paid seats.
- **Import review queue** — tabs for Pending / Flagged / Payout details / Quarantined / Accepted, per-row data-quality flags, and a review modal that lists candidates ranked by live-recomputed name similarity. Bank rows preselect nothing.
- **Role templates** — editable per-role app lists with tiers and scopes, so HR changes policy without a developer.
- **App catalog** and **Audit log** (append-only; cannot be created, edited or deleted from the UI).

**Phase 3 — Pipelines (complete)**

Runs are built from data, not from a written checklist, and the ordering rules from the SOP are enforced structurally rather than remembered.

- **Onboarding** — paperwork and bank forms out, contract sent, then a **blocking signature gate**. Every provisioning step depends on that gate, so a missing signature stops the chain instead of being a line someone skips. Provisioning steps are generated from the role template, ordered so Google and the vault come first.
- **Offboarding** — steps are generated from the access the person **actually holds**, not their role template, because those two drift and only the former is a real exposure. Revocations lead (Google first, marked "Do this first"), a credential-rotation step hangs off the vault revocation, data migration waits for *every* revocation and carries the destination resolved from the department matrix, then the +24h verify/delete/alias step, and letters last.
- **Task runner** — resolves dependencies, refuses to complete a blocked step, refuses to complete a manual step without evidence, and keeps the access matrix in step with what the tasks say happened. Completing a run moves the person to Active or Exited automatically.
- **Screens** — run list with tabs, a pipeline view with per-step Details / Mark done / Skip, a signature-gate override for documents signed outside the system, and Start onboarding / Start offboarding actions on the person.

**Tests: 156 passing, 342 assertions.** Covers encryption at rest, per-role policies, field redaction, the access matrix, every importer edge case, screen-level authorization, the audit trail, and both pipelines end to end.

All admin routes verified returning 200. Two flows were driven in a real browser rather than only asserted: the payment reveal (blocked without a reason, then revealed and logged with user, reason, subject and IP) and the signature gate (provisioning steps flipping from Blocked to Pending on confirmation).

---

## Zoho Sign — CONNECTED (2026-09-08)

Authenticated via a Self Client refresh token (scopes `ZohoSign.documents.ALL`, `ZohoSign.templates.ALL`). 94 templates visible. The refresh token does not expire.

`php artisan hr:zoho-sign exchange --code=<grant code>` performs the whole handshake: it swaps the code, writes the refresh token into `.env` without printing it, and verifies the connection.

### All four exit letters are linked and verified against the live API

| | Relieving tech | Relieving ops | Experience tech | Experience ops |
|---|---|---|---|---|
| Template ID | `...1546030` | `...1546003` | `...1548001` | `...1548050` |
| Employee ID | text | text | absent | absent |
| Position | `Job Title` | `Job Title` | `Role` | `Role` |
| Report to | text | absent | absent | absent |
| Join date | `Join Date` | `Join date` | `Joining date` | `Joining date` |
| Last day | `Last Date` | `End Date` | `Leaving date` | `Leaving date` |
| HR name | absent | absent | text | text |
| Full name | **absent** | Zoho fills | Zoho fills | Zoho fills |
| Sign date | Zoho fills | Zoho fills | absent | absent |
| Signature | absent | absent | Zoho fills | Zoho fills |

**Field type drives how each field is filled**, and the type is read from the API rather than guessed from the label:

- `Textfield` and `CustomDate` — supplied by this app.
- `Name`, `Date`, `Signature` — filled by Zoho from the recipient or during signing. Sending values for these would be wrong, so they are excluded from the payload and never counted as missing data.

That distinction was only visible from the API. The earlier screenshot-derived mapping treated `Full name` as a text field, which verification caught.

### What the templates revealed

1. **The tech relieving letter has no name field of any kind.** It cannot state who it is about unless the name is written into the body text. Worth checking on the Zoho side.
2. **The same value carries four different labels** across the letters (`Job Title` vs `Role`, and three date-label pairs). Handled per template, but standardising them in Zoho would remove a class of breakage.
3. **The two experience letters are identical**, unlike the relieving pair. Registering the operations one blank rather than copying the tech one was the right call at the time — the relieving templates prove copying is not safe — and the API has now filled it in.

Nothing sends unless a template is both linked and verified: `sendTemplate()` refuses otherwise. All four now pass.


---

## In progress

Nothing mid-flight. The build is at a clean checkpoint.

---

## Next steps

1. **Integrations** — Zoho Sign send + signature webhook (which will satisfy the gate automatically), Typeform webhooks with hidden `employee_id`, Zoom and Zoho provisioning behind the existing task hooks, Slack announcements. The pipelines already have the slots; the adapters are what is missing.
2. **Google OAuth login** to replace password auth before anyone else uses it.
3. **Leave and announcements** (Phase 4) — the 12:00 and Friday digests, holiday calendar, comp-off crediting.
4. **Licence guard** — the billing-cycle-minus-3-days reminder for freed Zoom seats.

### Honest limitation

Steps marked "Auto" cannot run automatically yet, because no integration credentials exist. They behave as manual steps for now: HR completes them and the run advances. Once the adapters land, the same steps execute themselves without any change to the pipeline shape.

### Note on import ordering

Match confidence is computed at import time against whoever existed then. Because paperwork rows only create people once a human accepts them, bank rows imported first score against an empty directory and sit at 0%. Run `php artisan hr:rematch` after accepting paperwork rows to refresh the proposals. The review modal recomputes candidate similarity live, so the dropdown is always correct even when the stored column is stale.

---

## Blockers and decisions needed

| # | Item | Impact |
|---|---|---|
| 1 | `SLACK_CLIENT_ID` and `SLACK_CLIENT_SECRET` | Without these the rotating Slack token cannot be refreshed and dies after ~12 hours |
| 2 | Zoho **self-client refresh token** | The token in `.env` is a 1-hour access token, unusable for a real integration |
| 3 | Zoho Sign **template id** | The link supplied is a signature *request*; the API needs a template. Convert it in Zoho Sign |
| 4 | Zoom Server-to-Server OAuth credentials | Blocks Zoom provisioning |
| 5 | Typeform token + **hidden `employee_id` field on both forms** | Without the hidden field, future submissions still cannot be linked reliably |
| 6 | Confirmed current roster | The CSVs are a 2022-23 archive, not a roster. Import cannot be reconciled without a list of who works here today |
| 7 | Real destination mailboxes | The offboarding matrix uses placeholder addresses for "admin", "product", "services" |
| 8 | Role template contents | Channels, groups and Bitwarden collections are placeholders pending HR input |

**Decisions already made**
- The app is the system of record; Zoho People is dropped (it cannot model the per-app access matrix).
- n8n stays out of HR to avoid split-brain logic.
- Google Workspace and Bitwarden provisioning are manual, delivered as guided task cards with evidence capture.
- Bank records are never auto-linked by name, not even an exact match, because the bank form collects no email and two people can share a name.

---

## Security notes

- `APP_KEY` encrypts every sensitive column. **Rotating or losing it makes bank details unrecoverable.** Back it up in Bitwarden; never rotate without a re-encryption migration.
- The Slack tokens currently in `.env` passed through a chat transcript and should be rotated before go-live.
- `.gitignore` blocks `.env`, `*.csv`, and `storage/imports/`. The real CSVs live at `hr-app/storage/imports/` and must never be committed.
