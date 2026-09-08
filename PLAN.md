# HR Onboarding / Offboarding Automation — Plan

**Company:** Coach Foundation
**Date:** 2026-07-17
**Status:** Draft v2 — grounded in `HR Tasks.docx` (current manual SOP)
**Source SOP:** the internal HR Tasks document (not included in this repository)

---

## 1. Goal

One system that runs the employee lifecycle end-to-end:

1. **Onboarding** — appointment letter → signed → accounts across Google Workspace, Zoho One (incl. Desk), Slack, Zoom, Bitwarden → announced. One action instead of a 2-hour manual checklist.
2. **Offboarding** — one action: revoke everything in the right order, migrate data per the department matrix, issue relieving/experience letters, with an audit trail.
3. **Daily HR ops** — leaves/comp-offs → approvals → Slack announcements (daily 12pm + Friday-noon week-ahead), automatic company-holiday messages.
4. **Freelancers** — NDA-gated, scoped-access variant of the same flows.

---

## 2. Current state (from HR Tasks.docx)

### The manual onboarding chain today
1. Draft appointment letter from Google Doc template → PDF → Zoho Sign (R&R section is the drafting pain point).
2. Gmail: create user on admin account **or via reseller** if license limit hit; sometimes rename a deleted user's replacement; migrate old data first.
3. Zoho One: admin panel → add user, choose app accesses, OTP first-login. License cost sign-off: **the licence approvers**.
4. Zoho Desk: create dept if missing → assignment rules (**the Desk administrators**) → agent profile, all-department + all-ticket access → email auto-forwarding setup (multi-step Gmail↔Desk dance with activation link).
5. Slack (`your-workspace.slack.com/admin`): invite as member or single/multi-channel guest → channels → 2FA.
6. Zoom (`mysaifai` account): free Basic, or Workplace Pro license — reuse a license mid-cycle if someone is being deactivated; new license purchase via **the licence approver**. Timezone/location must be corrected (auto-recording breaks otherwise). S3 call-recording automation: **the automation owner**.
7. Bitwarden (self-hosted at `vault.internal.example`): admin console → add member → user installs extension → admin confirms/approves. Admins: the HR lead/the HR lead/the licence approver/a department head/admin@cf.
8. Optional onboarding call.

### The manual offboarding chain today
- Reverse of everything, plus:
- **Gmail data-migration matrix** (destination by department):

  | Department | Data goes to |
  |---|---|
  | General | admin email |
  | Product | product email |
  | Marketing | manager if below manager; admin if manager |
  | Sales | admin email |
  | Finance | admin email |
  | Tech | services email |

- Delete user → migrate → verify after 24h → add departed address as **alias/alternate** on the destination account so stray inbound mail still lands.
- Zoho: deactivate + reduce license (ask **the licence approvers**).
- Zoom: deactivate; **must remove license before next billing cycle or get billed**; license removal requires plan-management + CVV re-verify.
- Slack: deactivate; if a same-name replacement is joining, free up the email on Slack.
- Bitwarden: remove member.
- Relieving + experience letters from Google Doc templates, sent via **hr@example.com** (email template exists).

### Leaves today
- Leave form: Typeform (`your-account.typeform.com/to/r4773u`) → sheet `[NLSA] Leave Application Form Tracking`.
- Comp-off form + leave-balance lookup: existing **Slack workflows** (shortcuts).
- Leave crediting/deduction automation: being handed from the previous owner to **the automation owner**.
- Wanted (explicitly in the doc): automatic company-holiday messages; "on leave next week" post Friday noon; "on leave today" post daily 12pm; Bitwarden password-change notifications.

### Freelancers
- NDA (Google Doc template) signed **before** any access. Software access is situation-specific. Offboard = revoke exactly what was granted.

**Root problems:** no system of record for employees; ~8 consoles touched per hire; license cost decisions embedded mid-flow with named humans; data-migration and license-removal steps are the ones that get missed and cost money/security.

---

## 3. Recommended architecture (hybrid, not a custom app)

**Don't build a custom app.** This is deterministic checklist execution + a few human approval gates — exactly n8n's shape, and the n8n hub (`automation.internal.example`) already runs company automation. A custom app adds hosting/auth/UI maintenance for no gain.

```
┌─────────────────────────────────────────────────────────┐
│ LAYER 1 — System of record: Zoho People (free in Zoho One)│
│ Employee profile, dept, manager, join/exit dates, leave  │
│ balances, comp-offs, holiday calendar, checklists        │
└──────────────────────┬──────────────────────────────────┘
                       │ webhooks / schedules
┌──────────────────────▼──────────────────────────────────┐
│ LAYER 2 — Automation engine: n8n (existing hub)          │
│ [HR][ONBOARD], [HR][OFFBOARD], [HR][LEAVES],             │
│ [HR][LETTERS], [HR][LICENSE-GUARD]                       │
└──────────────────────┬──────────────────────────────────┘
                       │ approvals / exceptions / Q&A
┌──────────────────────▼──────────────────────────────────┐
│ LAYER 3 — Claude Coworker                                │
│ Conversational front door, letter drafting (esp. R&R     │
│ section), exception handling, offboarding judgment calls │
└─────────────────────────────────────────────────────────┘
```

**Why Zoho People:** already in the Zoho One license; native leave types/balances/comp-offs, holiday calendar with yearly feed, onboarding/exit checklists, REST API + webhooks; same Zoho Directory as CRM/Desk/Sign. It replaces the `[NLSA]` sheet as the ledger (the sheet can be kept as a synced mirror during transition).

**Identity backbone:** Google Workspace as IdP (SSO) for Zoho, Slack, Zoom where plan tiers allow. Then suspending the Google account instantly kills most access — the single highest-leverage security move.

**Human-in-the-loop stays where money moves:** license purchases (Zoho/Zoom) become Slack approval buttons routed to the licence approvers rather than "go ask X" — the workflow waits on the click.

---

## 4. Onboarding flow (target)

**Trigger:** HR (or Claude Coworker on HR's instruction) creates the employee in Zoho People with: name, personal email, department, role template, manager, start date, employment type (employee/freelancer).

### Stage A — Paperwork (n8n `[HR][LETTERS]` + Coworker)
1. Coworker drafts the appointment letter from the template — **it fills the Roles & Responsibilities section from the role template + job description** (the current pain point), HR reviews the draft.
2. n8n sends it for signature via **Zoho Sign API** directly (no manual PDF download/upload).
3. Freelancer path: NDA via Zoho Sign instead; **provisioning stage is blocked until signed-webhook fires.**

### Stage B — Provisioning (n8n `[HR][ONBOARD]`, fires on signature-complete)

| # | System | Automated action | Human gate |
|---|---|---|---|
| 1 | License check | Count free Google/Zoom/Zoho seats via APIs. If short → Slack approval card to the licence approvers with cost; workflow waits | Approve purchase |
| 2 | Google Workspace | Create `first@example.com`, temp password + forced reset, dept groups, shared drives, holiday calendar subscription | Reseller edge case: if seat must come from reseller, workflow posts the exact request text to the HR channel and pauses |
| 3 | Zoho One | Add user via Directory, assign apps per role template, OTP flow | — |
| 4 | Zoho Desk | Create agent, dept access + ticket access per template. Dept creation & assignment rules stay manual (API doesn't expose assignment rules) → task card to the Desk administrators only when a *new* dept is needed | Rare |
| 5 | Desk auto-forwarding | Generate the Desk support address via API; the Gmail-side forwarding confirmation is scripted as a step-by-step DM to the new hire (or done by admin via Gmail API forwarding settings — feasibility check in Phase 0) | Maybe |
| 6 | Slack | Invite (member vs guest per template), auto-join channels | Plan-tier dependent — see §8; fallback is a one-click task card |
| 7 | Zoom | Create user on `mysaifai`; Basic vs Workplace Pro per template; **prefer reusing a license freed by a pending offboard** (workflow checks); set timezone/location from employee record (fixes the auto-recording issue); trigger the automation owner's S3 recording automation if role needs it | Purchase gate as in #1 |
| 8 | Bitwarden (self-hosted) | Invite via org API, assign collections per role template; instruction doc DM'd to hire; admin confirm step becomes a Slack button | Confirm member |
| 9 | Zoho People | Tick checklist items automatically as each step's API call succeeds; log run | — |
| 10 | Slack | Welcome post (#general/#hr) + manager DM with day-1 checklist; optional onboarding call scheduling link | — |

### Role templates
Small config objects (`sales`, `cs-agent`, `finance`, `tech`, `product`, `marketing`, `freelancer`) stored where HR can edit (Zoho People custom module or n8n data table), declaring: Google groups + drives, Zoho apps + Desk profile, Slack member/guest + channels, Zoom tier, Bitwarden collections, S3-recording yes/no, **and the offboarding data-destination** from the §2 matrix.

---

## 5. Offboarding flow (target)

**Trigger:** exit initiated in Zoho People (planned, runs end of last day) or Coworker/HR emergency trigger (immediate).

n8n `[HR][OFFBOARD]` — access first, data second, letters last:

| # | System | Action |
|---|---|---|
| 1 | Google | **Suspend user, reset password, revoke OAuth tokens + app passwords, sign out all sessions.** Minutes-fast for terminations |
| 2 | Bitwarden | Remove member via API; generate **shared-credential rotation task list** from the collections they could read (the doc's "notification of pw being changed" item, done properly) |
| 3 | Zoho | Deactivate user; reassign open Desk tickets + CRM records to manager; license-reduction approval card to the licence approvers |
| 4 | Slack | Deactivate; if a same-name hire is queued, free the email on the old profile |
| 5 | Zoom | Deactivate; transfer upcoming meetings + recordings to manager; **flag the freed license for reuse; if unused at billing-cycle-minus-3-days, `[HR][LICENSE-GUARD]` posts a "remove license now" reminder** (removal itself stays manual — needs CVV) |
| 6 | Google data | Transfer Drive ownership + migrate mail to the **department-matrix destination** (general→admin, product→product, marketing→manager-or-admin, sales→admin, finance→admin, tech→services) via Data Transfer API |
| 7 | Google, +24h | Scheduled verify step: migration complete? → delete user → **add departed address as alias on destination account** (preserves stray inbound mail, exactly per current SOP) |
| 8 | Letters | Coworker drafts relieving + experience letters from templates; HR approves; sent from **hr@example.com** using the existing email template; optionally routed through Zoho Sign |
| 9 | Audit | Zoho People exit checklist auto-ticked per step; full run log (who triggered, timestamps) |
| 10 | Slack | #hr summary — posted only after access revocation is complete |

**Freelancers:** the workflow revokes exactly the set recorded at grant time (the onboarding run stores a per-person access manifest — this is what makes "situation specific" auditable).

---

## 6. Leaves, comp-offs & holidays

Keep what works, automate the announcements the doc explicitly asks for.

### Requests (Phase 1 — no user-facing change)
- Typeform leave form + existing Slack comp-off workflow keep working.
- n8n webhook writes each submission into **Zoho People** (leave/comp-off record) and mirrors to the `[NLSA]` sheet during transition.
- Manager approval via Slack button; on approve → balance updated, team Google Calendar entry created, employee gets confirmation DM.
- Balance crediting/deduction logic (the previous owner → the automation owner handover) moves into Zoho People's native accrual rules — coordinate with the automation owner so it's built once, not twice.
- Existing leave-balance Slack shortcut re-pointed to query Zoho People.

### Announcements (n8n scheduled, exactly as the doc requests)
| When | Post |
|---|---|
| Daily 12:00pm | "Out today: …" (skip if nobody) |
| Friday 12:00pm | "On leave next week: …" + any public holiday next week |
| Holiday-eve + morning | Automatic company-holiday message |
| Monthly 1st (optional) | Month view: holidays + long leaves |

### Public holidays
- Year's list loaded once into Zoho People holiday calendar + shared "CF Holidays" Google Calendar (everyone auto-subscribed at onboarding).
- Next year: Coworker drafts the list for approval, then loads it — the yearly chore becomes a 2-minute review.

### Phase 3 option
Retire Typeform in favor of Zoho People's native request flow (mobile app, balances, approval chains). Digests unchanged since n8n reads Zoho People either way.

### 6b. Champions channel automation (from observed channel history)

The **#champions** channel is where all of this actually lands today, posted manually by the HR lead / Coach Foundation Admin / HR. Observed recurring patterns → automation targets:

| Today (manual) | Target (automated) |
|---|---|
| Daily 12:00pm "@channel on Full-Day leave @X" posts | n8n reads approved leaves from Zoho People, posts the same format (incl. half-day distinction), **skips when nobody is out** |
| "OUT-OF-OFFICE STATUS FOR UPCOMING WEEK" at 2:00pm with day-by-day list + @dept-managers/@hods + the file-a-week-ahead nudge | Fully generated from Zoho People; the nudge paragraph included only occasionally (it's currently pasted verbatim every week — rotating/conditional copy keeps it from becoming wallpaper) |
| Holiday announcements (e.g. Labour Day, Eid): dept heads (@a department head @a department head @a department head @Bhaskar) told to notify members; thread collects who works vs. takes it; workers fill Comp-Off form; worked holiday → leave balance credit; client-facing owners (@Alex @Jordan @Keith @a department head) check client announcements | n8n auto-posts N days before each calendar holiday with the same structure + Comp-Off form link; comp-off submissions flow into Zoho People and auto-credit balances; a follow-up post tallies the worked/off split from the thread + form data |
| Comp-off crediting from form → sheet | Zoho People comp-off records, credited automatically on approval |
| Scheduled compliance reminders (already Slack workflows): Zoom recordings → S3 (10:30am), sai.coach-only file ownership + no local files (2:00pm), contact-detail updates to HR/@the HR lead, Glossary CEO-approval | **Keep as-is** — they work. Optional upgrade: acknowledgment tracking (below) |
| Acknowledgment-emoji culture (:100:, :headphones:, :floppy_disk:, :done:, :memo:, :truck:) with @ops chasing stragglers | Slack bot reads reactions on tracked posts; after 24h, DMs @ops a non-responder list instead of manual chasing. Works on **Pro plan** (bot `reactions.get` — no Enterprise needed) |
| Celebrations: birthdays, work anniversaries, promotions — hand-written posts + images | Zoho People birthday/join-date data → n8n reminds HR the day before → Claude Coworker drafts the message (+ optional card image) → HR approves → posts. Promotions stay human-initiated, Coworker-drafted |

Key point for scoping: **announcement automation is 100% possible on Slack Pro** — posting, reading reactions, DMs all work with a normal bot token. Only user *provisioning* (invite/deactivate) is Enterprise-gated.

---

## 7. Where Claude Coworker fits

n8n does the deterministic 95%; Coworker adds:

1. **Front door:** "Onboard Rahul, sales, starts Aug 1, reports to Meera" → validates, asks for gaps, creates the Zoho People record, streams per-step status.
2. **Letter drafting:** appointment letters (the R&R section specifically), relieving/experience letters, NDAs — draft → human review → Zoho Sign.
3. **Exceptions:** email collision, no free Zoom license ("Priya's offboard on Friday frees one — wait or buy?"), reseller seat requests.
4. **Offboarding judgment:** "She had 3 open Desk tickets and owns the Q3 tracker — reassign to whom?" — gathers, proposes, human confirms.
5. **HR Q&A:** balances, who's-out-when, headcount — read-only over Zoho People.

**Guardrails:** Coworker holds no destructive credentials — it calls n8n webhooks (scoped auth tokens); n8n owns all API keys. The offboard webhook only executes for an allowlist of approver identities (HR + founder), enforced **in n8n**, not in prompts.

---

## 8. Prerequisites & verifications (Phase 0)

| Item | Verify |
|---|---|
| Slack plan tier of `sai-coach` | **CONFIRMED: Pro plan.** No admin API for invite/deactivate → those steps become one-click task cards with prefilled details. Announcements, DMs, approval buttons, reaction-tracking all fully automatable via bot token |
| Google reseller arrangement | Which reseller, how seats are added, whether Admin SDK user-creation works within current seat count (it should — reseller only matters at the seat limit) |
| Google service account | Super Admin creates it with domain-wide delegation: Directory, Drive, Calendar, Data Transfer, Gmail settings scopes |
| Gmail forwarding via API | Test whether Gmail API `forwardingAddresses` can automate the Desk auto-forward dance (likely yes with DWD — would kill the worst manual step) |
| Bitwarden self-hosted (`vault.internal.example`) | Confirm it's Vaultwarden vs official; whether the org Public API / Directory Connector is available for member add/remove. If not → one-click task cards |
| Zoom | Server-to-Server OAuth app on `mysaifai`; confirm user create/deactivate/transfer scopes; license count endpoint for the reuse check |
| Zoho | Enable Zoho People; self-client tokens for People + Directory + Desk + Sign; confirm Desk assignment rules are UI-only (known — plan around it) |
| Typeform | Webhook on current plan (yes on paid) |
| Approvers | Confirm the gates: licenses = the licence approvers; Zoho reduction = the licence approvers; offboard trigger allowlist = HR + founder |

---

## 9. Build phases

**Phase 0 — Access & decisions (½–1 wk):** everything in §8; role templates defined with HR; `.env`/credential scaffolding (placeholders first, keys pasted in after).

**Phase 1 — Leaves & holidays (1 wk)** ← fastest visible win, zero risk
Typeform→Zoho People pipe, approval buttons, the three announcement schedules, holiday calendar load, balance-shortcut repoint. Coordinate with the automation owner on accrual logic.

**Phase 2 — Onboarding (1–1.5 wks)**
`[HR][LETTERS]` (Zoho Sign API) + `[HR][ONBOARD]` chain with license-check gate; role templates live; test on a dummy hire, then run the next real hire through it.

**Phase 3 — Offboarding (1 wk)** ← highest security + cost value
Suspend-first chain, department-matrix data migration, +24h verify/delete/alias step, license-guard billing reminders, credential-rotation list, letters via hr@, audit log.

**Phase 4 — Coworker layer + cleanup (1 wk)**
Conversational flows with confirmation guardrails, letter drafting, HR Q&A; optionally retire Typeform; retire the `[NLSA]` sheet mirror.

**Total ≈ 4–5 weeks part-time.** Phases 1–3 are pure n8n and each stands alone.

---

## 10. Risks & open questions

1. **Slack Pro confirmed** → invite/deactivate stay as one-click task cards (everything else automates). **Bitwarden self-hosted confirmed** → remaining check is whether the instance (Vaultwarden vs official) exposes the org member API; if not, same task-card fallback.
2. **Zoho People adoption** — it only works as system of record if hires/exits are entered there. Mitigation: Coworker and Typeform both *write into* it, so nobody has to open the People UI initially.
3. **Reseller dependency** for Google seats — can't be fully automated; keep it as a pause-and-request step with prefilled text.
4. **Zoom license removal needs CVV** — inherently manual; the license-guard reminder (billing-cycle-minus-3-days) prevents the real cost leak.
5. **Credential blast radius** — the Google service account is domain god-mode; lives only in n8n credentials, never in repo/dev machines. Offboard webhooks auth-gated + approver-allowlisted in n8n.
6. **Desk assignment rules are UI-only** (confirmed limitation) — new-department onboarding keeps one manual card; existing-department hires are fully automatic.
7. **Same-name email reuse** (Slack quirk in the SOP) — handled by the offboard workflow freeing the email; onboard workflow checks for collisions before creating.
