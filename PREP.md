# Pre-Build Preparation Checklist

Everything needed before Phase 1 coding starts. Items marked **[YOU]** need your admin access or a decision; **[HR]** can be delegated to the HR team; **[ME]** I handle once credentials exist.

---

## A. Admin access & API credentials

Paste all secrets into `hr-onboarding/.env` (placeholders ready). Never into chat.

### 1. Google Workspace — needs Super Admin **[YOU]**
- [ ] Confirm you can log into admin.google.com as a Super Admin.
- [ ] In console.cloud.google.com: create project `cf-hr-automation`; enable APIs: **Admin SDK, Drive, Calendar, Gmail, Data Transfer**.
- [ ] Create a service account, download the JSON key, tick "Enable domain-wide delegation".
- [ ] In admin.google.com → Security → Access and data control → API controls → **Domain-wide delegation** → add the service account's client ID with these scopes:
  ```
  https://www.googleapis.com/auth/admin.directory.user
  https://www.googleapis.com/auth/admin.directory.group
  https://www.googleapis.com/auth/admin.datatransfer
  https://www.googleapis.com/auth/drive
  https://www.googleapis.com/auth/calendar
  https://www.googleapis.com/auth/gmail.settings.basic
  https://www.googleapis.com/auth/gmail.settings.sharing
  ```
- [ ] Note: reseller name + contact procedure + current seat count vs limit (needed for the "no seats left" pause step).

### 2. Zoho — you have self-client experience **[YOU]**
- [ ] Zoho One admin → enable **Zoho People**, run its setup wizard (org name, no config needed yet).
- [ ] api-console.zoho.com → self-client → generate refresh token with scopes:
  `ZohoPeople.employee.ALL, ZohoPeople.forms.ALL, ZohoPeople.leave.ALL, ZohoPeople.attendance.ALL, ZohoDesk.agents.ALL, ZohoDesk.settings.ALL, ZohoSign.documents.ALL, ZohoSign.templates.ALL`
- [ ] Check whether Zoho One Directory user-provisioning API is exposed for the org (Directory scope), or whether Zoho user-add stays a task card.

### 3. Slack — workspace admin on sai-coach **[YOU]**
- [ ] api.slack.com/apps → Create app "CF HR Bot" → Bot token scopes:
  `chat:write, chat:write.public, reactions:read, users:read, users:read.email, im:write, channels:read, groups:read`
- [ ] Install to workspace, copy the `xoxb-` token.
- [ ] Invite the bot to **#champions** and the HR/ops channel.
- [ ] Note channel IDs (right-click channel → View details → bottom of About tab).

### 4. Zoom — owner/admin on the mysaifai account **[YOU]**
- [ ] marketplace.zoom.us → Develop → Build App → **Server-to-Server OAuth**.
- [ ] Scopes: `user:read:admin, user:write:admin, meeting:read:admin, meeting:write:admin, recording:read:admin, account:read:admin`.
- [ ] Copy Account ID, Client ID, Client Secret.
- [ ] Note current license inventory: how many Workplace Pro seats, billing cycle renewal date.

### 5. Bitwarden self-hosted (vault.internal.example) **[YOU]**
- [ ] Identify the flavor: log into the web vault → does the footer/admin page say **Vaultwarden**? (External probe returned 403, so this needs your login.)
- [ ] If **official Bitwarden**: Admin Console → Settings → Organization info → **View API key** → copy client_id + client_secret.
- [ ] If **Vaultwarden**: member add/remove has no public org API → those steps become one-click task cards; nothing else changes. Note the admin token owner.

### 6. Typeform **[YOU or HR]**
- [ ] Login that owns form `r4773u` → Settings → Personal tokens → create token, OR just confirm we can add a webhook in the form's Connect panel.

### 7. n8n hub **[YOU]**
- [ ] Confirm my n8n access works (the old local API key is dead). Fresh API key or UI login to automation.internal.example.

### 8. hr@example.com **[YOU]**
- [ ] Decide send path for letters: alias on an existing account (Gmail API "send as") vs mailbox login. Note which account owns it today.

---

## B. Decisions needed (founders + HR)

- [ ] **Founder yes #1:** Zoho People is the official employee record.
- [ ] **Founder yes #2:** approve the Google service account + Slack bot creation above.
- [ ] **Founder yes #3:** approver allowlist —
  - License purchases: the licence approver / the licence approver / the HR lead (confirm exact set)
  - Offboarding trigger: who exactly? (suggest: HR lead + one founder)
- [ ] Weekly-nudge policy: keep the "file a week ahead" paragraph every week, or only in weeks where late filings actually happened?
- [ ] Welcome-post channel for new hires: #champions or #general?

---

## C. Data to collect **[HR — sheets are fine]**

- [ ] **Employee roster**: name, work email, personal email, department, manager, join date, birthday, city/timezone, employee vs freelancer. (Seeds Zoho People.)
- [ ] **Current leave balances + comp-offs**: share/export the `[NLSA] Leave Application Form Tracking` sheet.
- [ ] **Leave policy in writing**: leave types, annual quota per type, accrual cadence (monthly/yearly), comp-off expiry, birthday-leave rule.
- [ ] **Holiday list**: remaining 2026 + full 2027.
- [ ] **Template access**: confirm the Google Docs from the SOP open for my service account or are shared: appointment letter, relieving letter, experience letter, NDA, letter email template.
- [ ] **Migration destination addresses**: the actual emails behind "admin account", "product account", "services account" in the offboarding matrix.
- [ ] **Role template inputs** (one row per role: sales, cs-agent, finance, tech, product, marketing, freelancer):
  - Google groups + shared drives
  - Slack: member or guest + channel list
  - Zoom: Basic or Pro + S3 recording yes/no
  - Bitwarden collections
  - Zoho apps + Desk department/profile
- [ ] **Bitwarden collections inventory**: list of collections that exist today.

---

## D. People to brief

- [ ] **the automation owner**: leave-accrual logic is moving into Zoho People — coordinate so it's built once. Also S3 recording trigger touchpoint for onboarding.
- [ ] **the licence approver / the licence approver / the HR lead**: they'll start receiving Slack approval buttons instead of ad-hoc pings.
- [ ] **the HR lead / the HR lead**: daily users — walk through the role-template table with them (section C).

---

## E. Ready-made here

- [x] `.env` scaffold with placeholders → fill it as credentials are created.
- [x] `.gitignore` covering `.env` and key files.
- [x] PLAN.md, pitch.html.

**Fastest path to first value:** items A2 (Zoho), A3 (Slack), A6 (Typeform), A7 (n8n) + C leave data unlock Phase 1 (leaves & holidays in #champions). Google/Zoom/Bitwarden can trail in for Phases 2–3.
