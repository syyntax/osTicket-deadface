# DEADFACE CTF 2026 — Support Ticketing System Plan

## 1. Purpose

Stand up a competitor support ticketing system for **DEADFACE CTF 2026** ("Kingmaker") using our fork of osTicket ([syyntax/osTicket-deadface](https://github.com/syyntax/osTicket-deadface)), deployable via `docker-compose`. Competitors use it to reach Staff during the event for troubleshooting, infrastructure issues, general questions, and challenge support.

This document plans the work. **Nothing is implemented yet** — this is the scope, sequencing, and decision record to build from.

## 2. Guiding Constraints

- Base platform stays osTicket; we extend/skin it rather than rebuild it. Prefer osTicket's native extension points (custom fields, forms, plugins, themes) over forking core logic where practical.
- **Styling changes are cosmetic only.** No visible copy, labels, help text, or field names change as a side effect of theming work — content changes (e.g., "Full Name" → "DEADFACE CTF Username") are tracked as their own functional tasks, not folded into the theme pass.
- Deploy via `docker-compose`, standalone (published port, no proxy) for now. Must be trivial to later drop behind the existing `rev-proxy` nginx container by joining its shared Docker network and removing the published port — do not build anything that assumes a fixed host port or hardcoded external hostname.
- Keep the footprint small: ~12 Staff members (per `DEADFACE.md` §28), ~2,500 competitors. No need to over-engineer for scale.

## 3. Decisions Locked In

| Question | Decision |
|---|---|
| Email match to CTF registration | **UI copy only.** Signup form instructs players to use the same email they registered for DEADFACE CTF with. No live validation against the scoring platform — avoids a hard dependency on `ctf.deadface.io` (also listed as off-limits infra in `DEADFACE.md` §22, so we should not be integration-testing against it casually anyway). Revisit as a stretch goal only if a safe read-only API becomes available later. |
| "Send to All Staff" ticket routing | **Unassigned + notify all Staff.** Ticket lands in a shared queue with no agent pre-assigned; every Staff member gets notified and anyone can claim it. Matches osTicket's native department-queue/notification behavior — no custom round-robin logic needed. |
| Theme scope | **Both portal and staff panel, toned down for staff.** Competitor-facing pages get the full neon cyberpunk/vaporwave treatment. The Staff Control Panel (including the new Agent Dashboard) shares the same palette, type, and accent language but at lower visual intensity, optimized for legibility during live support work. |

## 4. Feature Plan

### 4.1 Agent Dashboard

**Goal:** A landing page for logged-in Staff showing tickets assigned to them.

- osTicket's stock Staff Control Panel already has a "My Tickets" queue, but it's just another ticket grid — not a dashboard. Build a dedicated `scp/dashboard.php` (or equivalent) that becomes the **default post-login page for agents**, containing:
  - Summary tiles: open / overdue / answered counts for *this agent's* assigned tickets.
  - Breakdown by the new ticket category field (§4.3) and by priority.
  - A compact "My Tickets" table (reuses existing ticket-listing components/queries, filtered to `staff_id = current agent`) with quick links into full ticket view.
- Respect existing osTicket role/permission checks (agents only see what they're otherwise permitted to see; no new permission bypass).
- Implementation note: this is additive UI/controller work on top of existing ticket query APIs (`Ticket::objects()` / `TicketsQueue` equivalents) — should not require schema changes.

### 4.2 Player Identity: Username + Email Messaging

**Goal:** Align account creation/login language with DEADFACE CTF identity instead of generic "Full Name."

- Client registration/account forms (`open.php`, `account.php`, login flow): relabel the "Full Name" field to **"DEADFACE CTF Username"** and store the same value in the existing `name` column — this is a label/copy change, not a schema change, provided we don't need to separately track "real name" anywhere. Confirm nothing else in the fork relies on `name` being a real full name (e.g. printed on transcripts/exports) before treating this as pure relabeling; flag if any such usage is found during implementation.
- Add explicit help text under the email field on signup: *"Use the same email address you registered for DEADFACE CTF with."* No backend enforcement (§3).
- No change to authentication mechanics — still email + password per osTicket norms.

### 4.3 Ticket Creation: Category + Staff Routing

Two related additions to the "Open a Ticket" form:

**a) Category selection**
- Implement as an osTicket **Help Topic** (native concept) with exactly four options: *General Inquiry*, *Troubleshooting*, *Infrastructure*, *Challenge Support*. This is largely **configuration, not code** — osTicket already supports multiple help topics with per-topic custom forms.
- Ship this pre-configured via a seed/migration step run at first boot (see §5) so the instance comes up with these four topics already in place, instead of requiring manual admin setup after every fresh deploy.
- Each topic can carry its own custom field set later if Challenge Support ever needs extra fields (e.g. a challenge ID) — out of scope for MVP, noted as a natural extension point.

**b) Staff routing (assign to a Discord handle, or "All Staff")**
- Add a **Discord Handle** custom field to the Staff/Agent profile (admin-managed, matches the roster in `DEADFACE.md` §28). Manually maintained by admins — no Discord API integration, given the small and slow-changing staff list.
- Add a routing field to the ticket-creation form: a dropdown populated from Staff members who have a Discord Handle set, plus a literal **"All Staff"** option.
- On submit:
  - If a specific Staff member is chosen → ticket is created and directly assigned to that agent's `staff_id`, bypassing normal auto-assignment.
  - If "All Staff" is chosen → ticket is created **unassigned**, routed into a shared/default queue, triggering osTicket's existing new-ticket notification to all Staff (§3).
- This needs targeted custom logic in the ticket-creation controller path (`class.ticket.php` / `client.inc.php` create flow) — it's the one feature here that isn't just configuration. Needs care to preserve normal SLA/department assignment behavior for the "All Staff" path.

### 4.4 DEADFACE Theming (Cyberpunk / Vaporwave)

**Goal:** Reskin look-and-feel only — palette, type, motion, iconography — never copy.

- Build a new osTicket **client theme** (not a fork of core templates where avoidable) covering:
  - Public portal: login, registration, ticket status/open pages, knowledge base if enabled.
  - Staff Control Panel: login, ticket queues, ticket view, and the new Agent Dashboard.
- Visual direction, informed by `DEADFACE.md` §17 (branding) and the current deadface.io site (dark, high-contrast, hacker/cyber-underworld, logo-heavy, "professional but menacing"):
  - Dark backgrounds, neon magenta/cyan/violet accents, subtle scanline/glitch flourishes on the player portal (kept restrained — no motion that hurts usability, e.g. on form inputs or error text).
  - Monospace/display hacker-style webfont for headings (e.g. a Google Fonts pairing in the Orbitron/Share Tech Mono/VT323 family), readable body font for ticket content.
  - Staff panel: same color tokens and font family, lower contrast/lower glow intensity, minimal motion — prioritize scanability for agents working tickets live during the event.
- Implementation as a set of CSS/SCSS assets plus osTicket theme/template overrides only where the stock templates don't expose enough hook points — avoid touching PHP logic for styling changes.
- Explicitly do not touch: field labels, button text, help text, error messages, email templates' copy (only their visual chrome, if templated).

## 5. Deployment: docker-compose

**Target for this phase:** standalone, directly reachable (published port), no `rev-proxy` involvement — easy to iterate on locally.

Planned services:
- **`app`** — PHP (8.2–8.4 per upstream osTicket requirements) + web server (Apache, matching upstream's default assumptions) serving the forked codebase. Custom `Dockerfile` installing required PHP extensions (`mysqli`, `imap`, `intl`, `gd`, `mbstring`, `opcache`, etc. per the fork's `README.md`).
- **`db`** — MariaDB/MySQL for the osTicket schema, with a named volume for persistent data.
- Named volumes for:
  - DB data.
  - `include/ost-config.php` (or the whole `include/` config state) and `attachments/`, so upgrades/rebuilds don't wipe an installed instance.
- **Entrypoint automation:** a container startup script that, on first run, drives osTicket's CLI installer (`php manage.php`) non-interactively using environment-supplied admin/DB credentials, then applies the help-topic seed data from §4.3a — goal is `docker-compose up` producing a fully configured instance with no manual setup wizard step.
- **Networking for now:** `app` publishes a host port directly (e.g. `8080:80`) for local testing.
- **Future `rev-proxy` integration (not built now, but designed for):** drop the published port, attach `app` to the existing shared external Docker network `rev-proxy` uses, and let nginx route to the service by container/service name. Document this as a one-line follow-up in the repo rather than building it speculatively now.

## 6. Suggested Build Order

1. Docker Compose skeleton + non-interactive installer bootstrap (get a stock, unthemed osTicket running and reachable).
2. Help Topic seed data (four categories) + Discord Handle staff custom field.
3. Ticket-creation routing logic (specific agent vs. All Staff).
4. Agent Dashboard page.
5. Username/email copy changes on registration & login.
6. DEADFACE cyberpunk/vaporwave theme pass (player portal, then staff panel).
7. End-to-end pass: create account → open ticket per category/routing path → confirm agent sees it on their dashboard → confirm "All Staff" notification fan-out works.

## 7. Open Items to Revisit Later

- Whether Challenge Support tickets should eventually carry a structured "Challenge ID/Category" custom field for triage.
- Whether live email validation against the scoring platform is ever worth the added dependency (currently: no).
- Exact `rev-proxy` network name/config, to be confirmed when this instance actually moves behind it.
