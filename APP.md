# Coach Foundation HR App — Build Specification

**Version:** 1.0 · 2026-08-12
**Supersedes:** the Zoho People layer in [PLAN.md](PLAN.md) (see §1.1)
**Related:** [PREP.md](PREP.md) · [pitch.html](pitch.html)

---

## 1. What changed from the original plan

| Original plan | Now |
|---|---|
| Zoho People = system of record | **This app** = system of record |
| n8n = automation engine | **This app** (queued jobs + scheduler). n8n stays out of HR to avoid split-brain logic |
| Google Workspace provisioning automated | **Manual** — task card with instructions, HR confirms completion |
| Bitwarden provisioning automated | **Manual** — task card, HR confirms |
| Contract letters drafted from Google Docs | **Zoho Sign templates** (first: Coach LLC Basic Freelance Contract; more to come) |
| Onboarding data typed by HR | **Two Typeform forms** feed the profile automatically |

### 1.1 Why drop Zoho People — and what it costs us

You asked for employee management with per-app access control inside your own app. Zoho People cannot model "who has a Zoom Pro license and which Bitwarden collections" — that matrix is the core of what you want, so the app has to own the employee record. Keeping Zoho People alongside would create two records that drift, which is exactly the disease we are curing.

**What we give up:** Zoho People's ready-made leave engine (accrual rules, balances, approval chains) and its mobile app. We rebuild the parts we actually use in Phase 4 — leave records, balances, comp-off crediting — which is a smaller job than it sounds because the request intake (Typeform) and the announcements (Slack) already exist and the app only needs to store and total records.

**What we gain:** one source of truth, the access matrix, bank details under our own encryption, and no dependency on a Zoho module nobody currently uses.

---

## 2. Product summary

An internal web app, accessible only to staff, that:

1. **Holds every employee record** — profile, joining date, birthday, department, manager, employment type, and (encrypted, hidden by default) bank and payment details.
2. **Tracks per-app access** for every person: which of Google, Zoho One, Zoho Desk, Slack, Zoom, Bitwarden, S3, n8n they have, at what license tier, granted when and by whom.
3. **Runs onboarding as a pipeline** — send forms, send contract, wait for signature, then provision (automatically where the API allows, as a guided task card where it does not).
4. **Runs offboarding in the safe order** — revoke first, migrate data second, letters last, with the department migration matrix enforced.
5. **Seeds itself from the existing Typeform CSV exports** through a reviewed import, not a blind one (§7).

---

## 3. Stack

| Layer | Choice | Why |
|---|---|---|
| Framework | **Laravel** (latest LTS-current) | Encrypted attribute casts for bank fields, policies for field-level visibility, queues for provisioning jobs, scheduler for the Slack digests, and you already run `recurring-command-center` on Laravel |
| Admin UI | **Filament** | Gives resource CRUD, RBAC, table filters, and a form builder out of the box. The access matrix is a custom page on top |
| DB | MySQL (Cloudways, alongside RCC) | Same server and ops you already operate |
| Auth | Google OAuth (sign in with the sai.coach account) + role gate | No new passwords; access dies when the Google account is suspended |
| Queue | Database driver, Horizon optional | Provisioning is low-volume |

**Before writing integration code, check current docs via Context7 for:** Laravel version-specific encryption casts, Filament major version (v3 vs v4 APIs differ substantially), Slack `oauth.v2.exchange` token rotation, Zoho Sign Templates API, Typeform webhooks and hidden fields, Zoom Server-to-Server OAuth. Do not code any of these from memory.

**Deployment:** Cloudways, subdomain (e.g. `hr.coachfoundation.com`), forced HTTPS, IP-agnostic but Google-OAuth gated. Nightly DB backup — this database holds bank details, so backups must be encrypted and access-restricted.

---

## 4. Data model

### 4.1 Core tables

**`employees`** — the record
```
id, employee_code, full_name, preferred_name
work_email (nullable until Google account is made manually)
personal_email, phone
department_id, manager_id (self-ref), position
employment_type          enum: full_time, part_time, commission, freelancer, contractor
status                   enum: pre_onboarding, active, on_notice, offboarding, exited
date_of_joining (date), date_of_exit (date, nullable)
birthday (date)          — day/month shown company-wide, year HR-only
country, timezone
salary_amount (decimal, encrypted), salary_currency, salary_period
role_template_id
notes (text, HR-only)
timestamps, soft deletes
```

**`employee_payment_details`** — separate table so access is separately gated
```
id, employee_id
payout_currency          enum, normalized (USD/INR/PHP/EUR/GBP/PKR/...)
bank_country
account_number   (encrypted)
iban             (encrypted, nullable)
swift_code       (encrypted, nullable)
routing_number   (encrypted, nullable)   -- see §7.3, the IBAN column is polluted with these
address_line1, address_line2, city, state, postcode, country   (encrypted)
source                   enum: typeform_import, typeform_live, manual
verified_at, verified_by
timestamps
```
Rationale for a separate table: a Finance role can be granted read access to payment rows without gaining access to the rest of the HR record, and every read is logged at the table level.

**`apps`** — the catalog
```
id, key, name, provisioning_mode (automated|manual|semi), console_url,
supports_license_tiers (bool), instructions_md (for manual task cards), active
```
Seeded: `google_workspace` (manual), `bitwarden` (manual), `zoho_one` (automated), `zoho_desk` (automated), `slack` (semi — see §6.3), `zoom` (automated), `aws_s3` (manual), `n8n` (manual), `typeform` (manual).

**`app_accesses`** — the matrix, one row per employee × app
```
id, employee_id, app_id
status            enum: none, requested, pending_manual, active, revoke_pending, revoked, failed
license_tier      string, nullable   (e.g. zoom: basic|pro; slack: member|multi_channel_guest|single_channel_guest)
external_id       string, nullable   (Zoom user id, Slack member id, Zoho user id, Google primary email)
scopes            json, nullable     (Bitwarden collections, Desk departments, Google groups)
granted_at, granted_by, revoked_at, revoked_by
last_synced_at, last_error (text)
timestamps
```
Unique index on `(employee_id, app_id)`.

**`role_templates`** + **`role_template_apps`** — the defaults per role
```
role_templates:      id, key, name, description, active
role_template_apps:  role_template_id, app_id, license_tier, scopes (json), required (bool)
```
Seeded from the roles in the SOP: `sales`, `cs_agent` (client manager), `finance`, `tech`, `product`, `marketing`, `seo_outreach`, `freelancer`, `enrolment_coach` (commission-based, minimal access).

### 4.2 Process tables

**`onboarding_runs`** / **`offboarding_runs`**
```
id, employee_id, type, status (draft|in_progress|blocked|completed|cancelled)
initiated_by, started_at, completed_at, cancellation_reason
```

**`process_tasks`** — the steps inside a run
```
id, run_id, run_type (morph), key, title, description_md
mode              enum: automatic, manual, approval
status            enum: pending, blocked, in_progress, done, skipped, failed
app_id (nullable), assignee_user_id (nullable)
depends_on (json of task keys)
payload (json), result (json), error (text)
completed_at, completed_by, evidence (text)   -- e.g. pasted account id for manual steps
```

**`documents`** — Zoho Sign envelopes
```
id, employee_id, type (contract|nda|appointment|relieving|experience)
zoho_request_id, zoho_template_id, status (draft|sent|viewed|signed|declined|expired)
sent_at, signed_at, signed_pdf_path, timestamps
```

**`form_submissions`** — raw intake, never mutated
```
id, form_key (paperwork|bank|leave), typeform_response_id (unique), employee_id (nullable)
raw_payload (json, encrypted), received_at, processed_at, match_confidence, match_method
```

**`audit_logs`**
```
id, user_id, action, auditable_type, auditable_id, changes (json),
ip, user_agent, created_at
```
Every payment-detail read gets its own row with action `payment_details.viewed` or `.revealed`.

### 4.3 Later phases (Phase 4)
`leave_types`, `leave_requests`, `leave_balances`, `comp_offs`, `holidays`, `announcements`.

---

## 5. Access control inside the app

### 5.1 Roles

| Role | Sees | Can do |
|---|---|---|
| **Super Admin** | Everything | Everything, including role assignment and app catalog |
| **HR Admin** | All employee fields except salary/bank by default; can request reveal | Create/edit employees, run onboarding and offboarding, send documents |
| **Finance** | Payment details + salary; minimal profile (name, code, status, currency) | Verify and export payout data |
| **Manager** | Own reports only: name, work email, position, department, joining date, birthday (day/month), leave status | Nothing destructive; approve own team's leave (Phase 4) |
| **Employee** | Own record only; own bank details masked with self-reveal | Update own contact details, submit leave |

Enforced with Laravel Policies plus a `FieldVisibility` trait, so a field is filtered at the model layer rather than hidden in Blade only. Salary and bank fields are stripped from API/JSON responses unless the viewer passes the policy.

### 5.2 Sensitive-field rules

- **Bank account / IBAN / SWIFT:** encrypted at rest. Rendered as `•••• 3675` by default. Full value requires an explicit "Reveal" action → password re-confirmation → written to the audit log with a reason field.
- **Salary:** encrypted, visible to Super Admin and Finance only.
- **Birthday:** day and month visible company-wide (needed for the celebration posts); birth year HR-only.
- **Address:** encrypted, HR and Finance only.
- **Exports:** any CSV export containing payment fields is Super Admin only, watermarked with the exporter's name, and logged.

**Encryption warning to document in the README:** `APP_KEY` encrypts every sensitive column. Rotating or losing it makes bank details unrecoverable. Back it up in Bitwarden and never rotate without a re-encryption migration.

---

## 6. Onboarding pipeline

Trigger: HR creates the employee and picks a role template.

| # | Step | Mode | Detail |
|---|---|---|---|
| 1 | Create record | manual | Name, personal email, position, department, manager, employment type, role template, intended start date |
| 2 | **Send HR Paperwork form** | automatic | Typeform `WcuWNmoI` link, emailed with `?employee_id={uuid}` as a **hidden field** so the response links deterministically (§7.2). Webhook fills position, contact, employment type, salary, joining date |
| 3 | **Send Bank Details form** | automatic | Typeform `jmmVWSoj`, same hidden-field trick. Webhook writes the encrypted payment row |
| 4 | **Send contract** | automatic | Zoho Sign, template chosen by employment type. Freelancers get the Coach LLC Basic Freelance Contract; more templates plug in as you build them |
| 5 | **Signature gate** | blocking | Nothing below runs until the Zoho Sign webhook reports `completed`. This enforces the SOP rule: freelancers get access only after signing |
| 6 | Google Workspace account | **manual card** | Instructions + reseller note + "paste the created work email here" to confirm. Writes `work_email` and marks `app_accesses.google_workspace` active |
| 7 | Zoho One + Desk | automatic | Create user, assign apps per template; Desk agent with department and ticket access. New-department creation stays a manual card (assignment rules are UI-only) |
| 8 | Slack | semi | See §6.3 |
| 9 | Zoom | automatic | Create user at the template's tier; **set timezone and location from the employee record** (fixes the auto-recording problem in the SOP). Reuse a license freed by a pending exit before requesting a new one |
| 10 | Bitwarden | **manual card** | Console link, collection list from the template, confirm when the member is approved |
| 11 | S3 recording access | manual card | Only if the template requires it |
| 12 | Welcome announcement | automatic | Post to #champions; DM the manager the day-one checklist |
| 13 | Close run | automatic | Employee status → `active` when every required task is `done` |

### 6.1 Manual task cards
A manual card shows: the console URL, step-by-step instructions from `apps.instructions_md`, the exact values to enter (name, personal email, license tier, collections), a copy button per value, and a completion form that captures evidence (the created account id or email). Until the card is completed the run shows as blocked, and the dashboard shows what HR still owes.

### 6.2 Offboarding order (unchanged from PLAN.md, minus automation)
1. Google suspend — **manual card, marked URGENT**, first in the list, because it is now the human's job and it is the step that actually cuts access.
2. Bitwarden remove — manual card + auto-generated shared-credential rotation list from the collections they held.
3. Zoho deactivate (automatic) + reassign open Desk tickets.
4. Slack deactivate (semi).
5. Zoom deactivate (automatic), transfer meetings and recordings, flag the freed license.
6. Data migration per the department matrix — manual card carrying the correct destination address, pre-resolved by the app.
7. +24h verification card → delete user → add old address as alias.
8. Relieving and experience letters via Zoho Sign, sent from hr@example.com.
9. Audit summary posted to the HR channel.

### 6.3 The Slack invite question — test before assuming
You are on **Pro**, where the documented `admin.users.invite` is Enterprise-only. However, the token you supplied is a **user token with rotation enabled** (`xoxe.xoxp-`), and Slack's undocumented `users.admin.invite` endpoint historically works with an owner/admin user token on paid plans.

**Phase 0 task:** call `users.admin.invite` once against a test address. If it works, step 8 becomes fully automatic; if it returns `not_allowed_token_type` or similar, it degrades to a manual card. Do not build on the assumption either way.

**Token rotation is mandatory to implement:** the access token expires roughly every 12 hours. The app must store both tokens, call `oauth.v2.exchange` with the refresh token before expiry, and persist the new pair. This needs `SLACK_CLIENT_ID` and `SLACK_CLIENT_SECRET`, which are not yet in `.env`. Without this the integration dies silently after half a day.

### 6.4 Zoho Sign — convert the request into a template
The link you gave (`.../request/details/<template-request-id>`) is a **signature request**, not a template. The API creates documents from `template_id`. In Zoho Sign, open that document → save as Template → note the template id → put it in `ZOHO_SIGN_TEMPLATE_FREELANCE`. Same for every future template. Also: the token in `.env` is a 1-hour access token; the app needs a self-client **refresh** token (same flow you have used for Zoho CRM).

---

## 7. CSV import — findings and design

I read both exports. They are **an archive of form submissions, not a roster**, and they need a reviewed import rather than a direct load.

### 7.1 What is in them
- **HR Paperwork (`WcuWNmoI`)** — ~36 submissions, all from Oct 2022 to Feb 2023. Fields: name, personal email, contact number, position, employment type, salary offered, date of joining.
- **Bank details (`jmmVWSoj`)** — ~72 submissions, mostly Mar 2023 plus a few in 2026. Fields: name, full address, payout currency, bank country, account number, IBAN, SWIFT.

Both are stale relative to your current team: most people who appear in the #champions history are not in these files, and many people in these files have long since left.

### 7.2 The linking problem (most important finding)
**The bank form does not collect an email address.** The only shared column between the two files is the name, and names do not match across them: full-versus-short forms, all-caps versus title case, maiden and middle names appearing in one file but not the other. `Network ID` is a Typeform respondent fingerprint and is not stable across two different forms, so it cannot be used as a join key either.

**Fixes:**
1. **Going forward:** add a hidden field to both Typeforms (Typeform supports hidden fields passed as URL parameters) carrying `employee_id`. The app always sends the personalized link, so every future submission links with zero ambiguity. This is a 5-minute change in the Typeform builder and it must happen before Phase 2.
2. **Also add an email question to the bank form** as a human-readable backstop.
3. **For the historical rows:** import into a staging table, auto-match on normalized name (lowercase, strip punctuation, sort tokens, compare with trigram similarity), and present anything below high confidence in a **review queue** where HR confirms, reassigns, or discards. Never auto-merge a bank record on a fuzzy name match — the failure mode is paying the wrong person.

### 7.3 Data quality issues found (the importer must handle these)

| Issue | Evidence in the files | Import handling |
|---|---|---|
| **Salary is free text** | Values appear as `$400 USD`, `$500`, `$ 350 USD`, `$1200`, and blank | Parse amount + currency with a regex; anything unparsed goes to review, never guessed |
| **Joining date is free text** | Formats include `9th February 2023`, `Jan 9`, `5 Jan`, `5-Dec-2022`, `23-Dec-2022`; **one row contains a job title in the date field** | Multi-format parser; unparseable → review queue, flagged |
| **Payout currency is free text** | Besides USD/INR/PHP the column contains `EURO`, `Pound Sterling`, and `300 USD` (an amount in a currency field) | Normalize to ISO 4217; unknown → review |
| **Bank country column holds bank names** | Several rows carry a bank name instead of a country | Two fields on import: keep the raw string, and map to a country where confident |
| **IBAN column is polluted** | Contains `N/A`, the SWIFT code repeated, the account number repeated, and in at least two rows a **routing number** (a 9-digit US one and a PH equivalent) | Validate IBAN by checksum. If it fails, test for SWIFT/routing shape and move it to the right column; otherwise null it and flag |
| **Duplicate submissions** | At least 4 people submitted the bank form twice; one person submitted the paperwork form three times | Deduplicate on best-match identity, keep the latest by submit date, retain all raw rows in `form_submissions` |
| **Test records** | Several rows are obvious test entries with placeholder names and junk account numbers | Auto-quarantine on a test-pattern match; never import silently |
| **Non-person payees** | Some rows are companies or trading names rather than individuals, and one name field spans two lines with different legal names for two countries | Import as `employment_type = contractor` with an `is_entity` flag, or exclude — HR decides in review |
| **Country field inconsistency** | Same country written as `India`, `IN`, `INDIA`, `Indian` | Normalize to ISO country codes |

### 7.4 Import command
```
php artisan hr:import-typeform {paperwork|bank} {path} [--dry-run]
```
Loads into staging, runs normalization and matching, prints a summary (imported / needs review / quarantined), and populates the review queue. `--dry-run` is the default posture for the first pass. The CSV files stay outside the repo (`storage/imports/`, gitignored) because they contain bank details and home addresses.

**Recommended sequence:** import paperwork first (it has emails, so it creates identities), then bank (matched against those identities), then have HR reconcile against the real current roster and mark everyone else `exited`.

---

## 8. Screens

1. **Dashboard** — active onboarding/offboarding runs, blocked manual cards, licenses freed but not yet removed, upcoming birthdays and anniversaries, who is out today.
2. **Employee directory** — searchable table, filters by department/status/employment type; sensitive columns absent unless the viewer has the policy.
3. **Employee detail** — tabs: Profile · Access · Documents · Payment (gated) · Timeline (audit) · Leave (Phase 4).
4. **Access matrix** — the headline screen. Rows = employees, columns = apps, cells = status chips with license tier. Click a cell to grant, change tier, or revoke; bulk-select a column to audit one app. Filter to "has access but exited" — that view is the security payoff.
5. **Onboarding run** — the pipeline with per-task status, manual cards inline, blocked reasons visible.
6. **Offboarding run** — same, with the urgent revocation steps pinned at the top.
7. **Import review queue** — staged rows with suggested matches, confidence, and accept/reassign/discard actions.
8. **Role templates** — editable per-role defaults so HR changes access policy without a developer.
9. **Audit log** — filterable, with payment-reveal events highlighted.

---

## 9. Where Claude fits

Your original ask was "an app **or** Claude Coworker to bring multiple apps together." The app won that job — deterministic provisioning, audit trails, and a permanent access matrix need a database and a permissions model, not a chat session. But that only answers *integration*. It leaves three jobs where Claude is genuinely the better tool, and one where it is decoration.

### 9.1 Two different integration modes

**Mode A — Claude inside the app (recommended, build it).**
The app calls the Claude API for specific features. Deterministic, auditable, no separate tool for HR to learn, output lands directly in the right database field.

| Feature | Where | Why Claude and not code |
|---|---|---|
| **Import match suggestions** | Review queue (§7.2) | Deciding whether two differently-spelled names are the same person is judgment. Claude proposes the match **with its reasoning shown**; HR confirms. Never auto-applies to a payment record |
| **Dirty-field parsing** | Importer (§7.3) | The free-text salary, join-date, and currency columns. Regex handles the clean 80%; Claude handles the rest and flags what it is unsure about |
| **Contract R&R drafting** | Document send step | The pain point named in the SOP. Draft from role template + position, HR edits, then Zoho Sign |
| **Relieving / experience letters** | Offboarding step 8 | Same pattern: draft from the record, HR approves, send from hr@ |
| **Celebration post drafts** | Announcements (Phase 4) | Birthday, work-anniversary, promotion posts in your existing #champions voice. Drafted, never auto-posted |
| **Offboarding handover summary** | Offboarding run | "This person owns 3 open Desk tickets and these Drive folders — here is a proposed reassignment." Gathers and proposes; HR decides |

Every one of these produces a **draft or a suggestion that a human commits.** Claude writes nothing to Google, Zoom, Zoho, Slack, or the payment table on its own.

**Mode B — Claude Cowork over the app (optional, Phase 5).**
For the ad-hoc questions that never fit a screen: *"Who's out next week?"*, *"Which exited people still hold Zoom licenses?"*, *"How many leaves does X have left?"*, *"Draft the holiday announcement for next month."*

This works by exposing the HR app as an **MCP server** that Cowork connects to, with two tiers of tools:
- **Read tools** — directory, access matrix, leave balances, run status. Sensitive fields (bank, salary) are simply not exposed to MCP at all, regardless of who is asking.
- **Draft tools** — generate a letter, propose a match, compose an announcement. Returns text; commits nothing.
- **No write tools.** Anything that provisions, revokes, pays, or posts stays in the app behind the approval gates.

That boundary is the whole safety design: even a perfectly-worded instruction reaching Claude from a Typeform field or an email cannot cause an account to be created or an announcement to be published, because no such tool exists on the connection.

### 9.2 Where Claude is decoration — and I'd skip it

A conversational front door for *running onboarding* ("Onboard Rahul, sales, starts Aug 1"), which I put in the original PLAN.md as Layer 3. Once the app has a proper form with role templates, that sentence is slower to type than the form is to fill, and it loses the validation and the audit trail. I'd drop it. If HR later says the form is friction, it's an afternoon's work to add.

### 9.3 Build sequencing

Mode A features ride along inside the phases that need them: parsing and matching in **Phase 1**, R&R drafting in **Phase 3**, letters and celebration drafts in **Phase 4**. No separate Claude phase. Mode B (MCP server for Cowork) is a **Phase 5** add-on once there is real data worth asking questions about — roughly 2 days, and pointless before the directory is populated.

---

## 10. Build phases

| Phase | Scope | Ships |
|---|---|---|
| **0 — Foundations** (3–4 days) | Laravel + Filament skeleton, Google OAuth login, roles, employees + departments, audit logging, encrypted payment table. Slack invite spike (§6.3). Convert Zoho Sign request to a template | Team can log in and see an empty directory |
| **1 — Directory + import** (1 week) | Full employee CRUD, field visibility policies, payment vault with reveal + logging, CSV importer with staging and review queue | Real roster in the system, bank details secured |
| **2 — Access matrix** (1 week) | Apps catalog, role templates, `app_accesses`, the matrix screen, manual task cards, Zoho/Zoom/Slack API adapters | Every person's access visible in one grid |
| **3 — Pipelines** (1.5 weeks) | Onboarding and offboarding runs, Typeform hidden-field intake + webhooks, Zoho Sign send + signature gate, migration matrix, license guard | One-form hiring, safe-order exits |
| **4 — Announcements + leave** (1.5 weeks) | Scheduler for the 12:00 and Friday 14:00 posts, holiday calendar and comp-off crediting, leave records and balances, birthday/anniversary drafts | #champions on autopilot |
| **5 — Claude Cowork access** (2 days, optional) | MCP server exposing read + draft tools only (§9.1 Mode B). No write tools, no sensitive fields | HR can ask questions and get drafts conversationally |

Claude API features (§9.1 Mode A) are built inside Phases 1, 3, and 4 rather than as a separate phase.

Phases 1 and 2 are independently valuable: even with no automation at all, a correct directory plus a truthful access matrix fixes the "who still has access?" problem that costs you money and risk today.

---

## 11. Open items

1. **`SLACK_CLIENT_ID` / `SLACK_CLIENT_SECRET`** — required for token refresh, not yet supplied. Without them the Slack integration stops working after ~12 hours.
2. **Zoho self-client refresh token** for Sign (the current one is a 1-hour access token).
3. **Zoho Sign template ids** — after converting the freelance contract and any further templates.
4. **Zoom Server-to-Server OAuth credentials** — not yet created (PREP.md §A4).
5. **Typeform token + hidden-field change** on both forms.
6. **Current roster** — the CSVs cannot tell us who works here today. A confirmed list of active people is needed before the import is meaningful.
7. **Role template contents** — the per-role app/tier/scope table still needs HR's input.
8. **Rotate the Slack tokens** after build, since they passed through a chat transcript.
9. **Subdomain + Cloudways app** for deployment.
