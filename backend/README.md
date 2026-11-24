<p align="center"><a href="https://laravel.com" target="_blank"><img src="https://raw.githubusercontent.com/laravel/art/master/logo-lockup/5%20SVG/2%20CMYK/1%20Full%20Color/laravel-logolockup-cmyk-red.svg" width="400" alt="Laravel Logo"></a></p>

<p align="center">
<a href="https://github.com/laravel/framework/actions"><img src="https://github.com/laravel/framework/workflows/tests/badge.svg" alt="Build Status"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/dt/laravel/framework" alt="Total Downloads"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/v/laravel/framework" alt="Latest Stable Version"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/l/laravel/framework" alt="License"></a>
</p>

## ClientHub API and authentication

The API uses Laravel's server-side session guard for the first-party React SPA. Authentication endpoints are under `/api/auth`; the browser must first request `/api/auth/csrf-cookie`, then send the `X-XSRF-TOKEN` header on state-changing requests. The frontend auth service handles this when `VITE_AUTH_MODE=api`. Public registration is disabled: only an authenticated administrator can create accounts.

Configure the backend `.env` with:

```env
APP_URL=http://localhost:8000
FRONTEND_URL=http://localhost:5173
SESSION_DRIVER=database
SESSION_SECURE_COOKIE=false
MAIL_MAILER=log
```

Copy `frontend/.env.example` to `frontend/.env.local` (it already contains these values) or set:

```env
VITE_API_URL=http://localhost:8000/api
VITE_AUTH_MODE=api
```

Run `php artisan migrate` before starting the application. In local development, password-reset links are written to `storage/logs/laravel.log` when `MAIL_MAILER=log`. Configure a production mail provider, HTTPS, secure cookies, a restricted `FRONTEND_URL`, and appropriate session domain settings before deployment.

There is no default admin account or public signup route. Bootstrap the first administrator from trusted local tooling with `php artisan app:create-admin "Administrator" admin@example.com`; the command prompts for a strong password without echoing it. For local-only seeding, set `LOCAL_ADMIN_NAME`, `LOCAL_ADMIN_EMAIL`, and `LOCAL_ADMIN_PASSWORD` in the local environment before running `php artisan db:seed`. Do not set these values in production.

Names: person and agency names are trimmed and whitespace-collapsed by the form requests, and title-cased only when clearly broken (all upper case, all lower case, or "mAHNOOR"-style capitals); "McDonald" or "iConnect" are left alone. To tidy names saved earlier, preview with `php artisan app:normalize-names --dry-run`, then run `php artisan app:normalize-names` to apply. Emails are never touched.

Plans and contracts: `plans`, `agency_contracts` (plan snapshot columns; status `trial`, `active`, `paused`, `expired`, `cancelled`; a unique `live_agency_id` allows at most one trial or active contract per agency), `contract_events`, `contract_documents` (private disk), `agency_profiles` and `owner_profiles`. `agencies.status` is now a string with the values `draft`, `active` and `suspended`. `App\Services\AgencyContracts` turns a contract into the array the dashboards read; an agency with no contract gets `null`, limits are `null` (meaning "no limit", never "reached") and the UI shows "No contract". An expired or cancelled contract shows its dates but sets no limits. Hours are non-cancelled `work_item_allocations`, the same rule as the scheduler. The logo and brand colour reuse `agency_branding` rather than duplicating columns on `agency_profiles`.

Available routes:

| Method | Path | Purpose |
|---|---|---|
| GET | `/api/auth/csrf-cookie` | Establish the CSRF/session cookies |
| PATCH | `/api/auth/profile` | Any signed-in, active user updates their own display name (trimmed, whitespace collapsed, max 120). `email`, `role`, `agency_id`, `is_active` and `password` are rejected with 422. The only self-service profile route; the former `/api/agency/profile` was removed |
| PUT | `/api/auth/password` | Change your own password: `current_password`, `password` (reset-flow policy, must differ) and `password_confirmation`. Ends every other session, rotates the remember token and the current session id. Throttled to 5 a minute per user |
| POST | `/api/auth/users` | Admin-only account invitation; role and agency assignment are validated |
| GET | `/api/admin/users` | Admin-only paginated directory of all roles (optionally filtered by `role`); supports `search` (name, email, employee code), `page`, and `per_page`. Employees carry a `profile` summary: `employee_code`, `department`, `availability`, `employment_status`, `completeness` |
| PATCH | `/api/admin/users/{user}` | Admin update of employee or agency-owner details and activation |
| DELETE | `/api/admin/users/{user}` | Admin permanent deletion of an employee or agency owner |
| POST | `/api/admin/agency-owners` | Admin only, throttled like other admin creation. The agency wizard: `mode` (`create` or `draft`), `owner` {name, email, phone, whatsapp, job_title, preferred_contact_method, timezone}, `agency` {name, legal_name, brand_name, website, industry, country, city, address, company_size, tax_number (encrypted), billing_email, billing_contact_name, account_manager_id (admin or team lead), internal_notes}, optional `contract` {plan_id, employees, hours_per_month, max_open_projects, max_client_accounts, price, currency, billing_cycle, overage_rate, start_date, end_date, auto_renew, notice_period_days, status (`trial`\|`active`), trial_end_date, payment_terms_days, discount_percent} and `setup` {employee_ids, logo (multipart file), brand_color, send_invite, welcome_message}. Only owner name, owner email and agency name are required. One transaction creates the agency, owner, profiles, contract snapshot (copied from the plan, overrides win), a `created` contract event and pre-assignments (employees re-checked under row locks: active, no holder, within the contract's seats). `create` with `send_invite` sends the existing invitation (plus the optional welcome line) and a failed send rolls everything back; `draft` makes the agency `draft` and the owner inactive and sends nothing. Returns `agency`, `owner`, `contract` and `warnings` (201) |
| POST | `/api/admin/agencies/{agency}/activate` | Admin only. Validates a draft agency, activates it and its owner and sends the invitation (with the saved welcome line); a failed send changes nothing |
| POST | `/api/admin/agencies/{agency}/logo` | Admin only. Sets the logo through the existing white-label (Cloudinary) pipeline; png/jpg/webp, 2 MB, content sniffed |
| POST | `/api/admin/agencies/{agency}/contract-documents` | Admin only. One signed contract file (pdf/jpg/png, max 10 MB, real MIME checked) on the agency's current contract, stored on the private disk as `contracts/{agency}/{uuid}.ext`. 422 when the agency has no contract yet |
| GET | `/api/admin/agencies/{agency}/contract-documents/{document}` | Admin only. Streamed download, audited |
| GET | `/api/agency/contract/document` | Agency owner only. Streams the current contract's latest document (404 when there is none) |
| GET / POST / PATCH | `/api/admin/plans`, `/api/admin/plans/{plan}` | Admin only. List (`?active=1` for active plans), create and edit plans. A contract copies a plan's numbers, so editing a plan never changes existing contracts |
| GET | `/api/admin/employees/available`, `/api/admin/account-managers` | Admin only. Active employees nobody holds (optional `search`) and the admins and team leads who can be an account manager, for the wizard |
| GET | `/api/admin/agencies` | Admin only. Searchable (`search`), paginated (`page`, `per_page` up to 100) agencies with the same row shape as the dashboard's `agency_rows` |
| GET | `/api/admin/agencies/{agency}` | Admin only. One agency for the detail tabs: owner, `contract` (null until contracts exist), `limits`, `counts`, `usage` (week and month hours, burn rate, last 14 days), held `employees` and recent `projects` |
| PATCH | `/api/admin/agencies/{agency}` | Admin activates or suspends an agency; suspension revokes its users' sessions |
| GET | `/api/agency/dashboard` | Agency owner and client (active agency only), scoped to the caller's own agency. Owner payload: `agency`, `contract` (null without one), `limits` (employees, hours, open_projects, client_accounts; each `used`, `allowed`, `remaining`, `reached`, `nearly`; a null `allowed` means no limit, never zero), hours `burn_rate_per_day` and `projected_run_out`, `weekly`, `usage`, held `employees` with the public profile summary and this week's hours on this agency's projects, `attention`, `recent_projects`. Client payload is reduced: only the employees they hold and projects they created, a redacted contract (plan, status, end/renewal dates, days remaining; never price, payment terms, discounts or notes), open projects against the per-client limit, and their own attention items |
| POST | `/api/agency/requests` | Agency owner and client. `type` (`more_hours`, `upgrade`, `renewal`) and optional `message` (max 500). Creates an in-app notification (and email, per preferences) for every active admin and returns `notified`. Throttled to 5 a minute per user |
| POST | `/api/admin/employees` | Admin creates an employee with a full profile. Only `name` and `email` are required. Optional: personal, public and employment fields, `skills`, `educations`, `experiences`, `certifications`, `checklist`, and multipart `documents[n][file\|type\|expires_at]`. One transaction creates the account, profile, lists and files, then sends the invitation; if the email fails everything (including stored files) is rolled back. Returns the masked admin profile (201) |
| GET | `/api/admin/employees/{id}` | Admin reads the full profile: personal, employment, finance, lists, documents and server-computed `completeness`. CNIC and bank account are masked (`34101-*******-1`, `****1234`). Writes a `viewed_sensitive` audit row |
| PATCH | `/api/admin/employees/{id}` | Admin partial update; absent keys are untouched, `null` clears a field, a list key replaces that list. Login email, role and `is_active` are rejected (use `PATCH /api/admin/users/{user}`). CNIC must be 13 digits (`12345-1234567-1` is normalised) and unique |
| POST | `/api/admin/employees/{id}/reveal-cnic` | Admin only, throttled to 10 a minute. Returns the full CNIC with `Cache-Control: no-store` and writes a `revealed_cnic` audit row. 404 when none is on file |
| POST / DELETE | `/api/admin/employees/{id}/photo` | Admin sets or removes the employee's photo through the existing avatar pipeline (jpg/png/webp, max 4 MB, Cloudinary) |
| POST | `/api/admin/employees/{id}/documents` | Admin uploads one document: multipart `file` (pdf/jpg/jpeg/png, max 5 MB, real MIME checked), `type` (`cnic_front`, `cnic_back`, `cv`, `degree`, `experience_letter`, `contract`, `nda`, `other`), optional `expires_at`. Stored on the private disk as `employees/{id}/{uuid}.ext`; storage paths are never returned. Max 40 per employee. Throttled like other uploads |
| GET | `/api/admin/employees/{id}/documents/{document}` | Admin only. Streams the file (`?inline=1` for images only), `nosniff`, `no-store`; writes a `downloaded_document` audit row. A document of another employee is 404 |
| PATCH | `/api/admin/employees/{id}/documents/{document}/verify` | Admin marks a document verified or not (`verified`: boolean); records who and when |
| DELETE | `/api/admin/employees/{id}/documents/{document}` | Admin deletes the row and the file |
| GET | `/api/agency/employees/{id}/profile` | Agency owner and client (active agency only). The public profile, from a strict whitelist: id, name, photo, headline, bio, department, skills, educations, experiences, certifications, english level, time zone, availability, preferred hours, portfolio links, weekly capacity. Never email, phone, CNIC, address, bank, pay rate, notes, checklist or documents. Only active employees that the agency could hold (its own or shared); everything else is 404 |
| GET | `/api/agency/users` | Agency-owner list of agency accounts; employee accounts are read-only |
| POST | `/api/agency/users` | Agency owners invite clients into their active agency |
| PATCH | `/api/agency/users/{user}` | Owners update same-agency client details and activation |
| DELETE | `/api/agency/users/{user}` | Owners delete same-agency clients |
| GET | `/api/agency/directory` | Agency-owner, legacy agency-member, and agency-client directory including shared admin-created employees |
| GET | `/api/agency/team` | Agency-owner/client roster of shared admin-created employees |
| GET | `/api/agency/employees/available` | Agency client/owner list of active same-agency and shared employees with no active holder. Each carries the public summary: `photo_url`, `headline`, `department`, `availability`, `top_skills` |
| POST | `/api/agency/assignments` | Hold an available employee exclusively for the current client or owner |
| GET | `/api/agency/assignments` | List the caller's own active assignments (holder only; `scope` is rejected with 422) |
| GET | `/api/agency/assignments/{assignment}/usage` | Read usage of an assignment the caller holds for a requested `?week=YYYY-MM-DD` (404 otherwise) |
| DELETE | `/api/agency/assignments/{assignment}` | Holder ends their own assignment (owners and clients alike; 404 for anyone else's) |
| GET | `/api/agency/projects` | Agency clients and owners page through projects in their agency. Query: `page`, `per_page` (default 25, max 100), `status` (`open`, `scheduled`, `in_progress`, `completed`, `cancelled`; `open` = scheduled + in progress), `assignment_id`, `search` (title, client or employee name). Returns `projects`, `pagination` and an agency-wide `summary` of counts by status that ignores filters and page |
| GET | `/api/agency/capacity?assignment_id=&from=&to=` | Agency client/owner read per-day availability of an employee they hold (same rules as the scheduler) for a date range of up to 366 days |
| GET | `/api/admin/reports/{overview\|employees\|agencies\|projects\|tickets\|assignments}` | Admin only. Report JSON, or a CSV with `?format=csv`. See "Reports" below |
| GET | `/api/agency/reports/{overview\|employees\|projects\|tickets}` | Agency owner and client (active agency only). An owner sees their agency; a client sees only their own projects and the assignments they hold |
| GET | `/api/agency/branding` | Owner, client and member read their own agency's branding (defaults when never customised). The same payload is `user.branding` in `/api/auth/me` for agency-scoped users (`null` for everyone else) |
| PUT | `/api/agency/branding` | Owner only. Partial update of `display_name`, `tagline`, `welcome_message`, `primary_color`, `accent_color` (hex, at least 4.5:1 against white), `theme_mode` (`light`/`dark`/`system`), `sidebar_style` (`light`/`brand`), `corner_style` (`soft`/`rounded`/`sharp`). `agency_id` is prohibited |
| POST | `/api/agency/branding/{logo\|logo-collapsed\|favicon}` | Owner only, multipart field `file`. Logos: png/jpg/webp, max 2 MB, 64 to 4096 px. Favicon: png/ico, max 512 KB, 16 to 1024 px |
| DELETE | `/api/agency/branding/{logo\|logo-collapsed\|favicon}` | Owner only. Removes one file |
| DELETE | `/api/agency/branding` | Owner only. Resets every field to the defaults and deletes all the agency's files |
| GET | `/api/agency/projects/{workItem}` | Agency clients and owners read one project in their agency (404 for other agencies) |
| POST | `/api/agency/projects` | Agency client/owner create a project using their active `assignment_id` |
| PATCH | `/api/agency/projects/{workItem}/cancel` | Agency clients and owners cancel any open project in their agency |
| GET | `/api/agency/projects/{workItem}/tickets` | Agency clients and owners read tickets for projects in their agency |
| POST | `/api/agency/projects/{workItem}/tickets` | Agency clients and owners create tickets and plan them against project capacity |
| PATCH | `/api/agency/projects/{workItem}/tickets/reorder` | Reorders a project's tickets and recalculates their dates |
| PATCH | `/api/agency/tickets/{ticket}` | Agency clients and owners update tickets on projects in their agency |
| DELETE | `/api/agency/tickets/{ticket}` | Agency owner/client cancels a ticket and replans remaining tickets |
| PATCH | `/api/agency/projects/{workItem}/brief` | `{ghl_brief}`: edit the project's GoHighLevel brief. Whole sections are replaced; sections not sent are kept; `null` clears one. Agency owner/client, own agency, open project, active assignment (same guards as editing a ticket). 422 keys are nested (`ghl_brief.client.email`) |
| POST | `/api/agency/projects/{workItem}/tickets/{ticket}/duplicate` | `{title?, estimated_hours?}`: copies a ticket and its work order (fresh undone checklist; delivery, blocked and review are not copied). Planned like any new ticket, so project hours and capacity apply |
| GET | `/api/agency/ticket-templates` | The work-type templates (`key`, `label`, `brief_section`, `recommended`, `acceptance`, `checklist`) from `config/ticket_templates.php` |
| PATCH | `/api/tickets/{ticket}/delivery` | Assigned employee only, open project: `{done, links[], ghl_changes, maintain, limitations, followups, time_note}`. Returns `{work_order}` |
| PATCH | `/api/tickets/{ticket}/blocked` | Assigned employee only: `{blocked, reason (required when blocked), waiting_on?}`. A note for the team; it never changes the status |
| PATCH | `/api/tickets/{ticket}/checklist/{item}` | `{done}`: the assigned employee or the agency ticks or unticks one step. Never changes status, hours or schedule |
| PATCH | `/api/admin/projects/{workItem}/brief/internal-notes` | Admin only: `{notes}`. Internal notes are read by admins and the assigned employee, never by agency users |
| PATCH | `/api/admin/tickets/{ticket}/internal-notes` | Admin only, same rule for a ticket |
| GET | `/api/work-items/mine/tickets` | Employee reads tickets for their assigned projects |
| PATCH | `/api/tickets/{ticket}/status` | Assigned employee advances a ticket through its status flow |
| GET | `/api/tickets/{ticket}/comments` | Same access as posting (admin, the ticket's assigned employee, agency owners/clients of its active agency; others 403). Returns `{ comments, total }`: the latest 200 comments oldest first, each with `user: {id, name, role}` |
| POST | `/api/tickets/{ticket}/comments` | Authorized project participants or admins add a ticket comment (same access rule as reading) |
| GET | `/api/tickets/key/{key}` | Open a ticket by its human key (`PREFIX-n`, parsed on the last hyphen). Returns `{ticket, project, timeline}` (first 25 events). Admin, the assigned employee, or an owner/client of the ticket's active agency; anyone else gets 404 |
| GET | `/api/tickets/{ticket}/timeline` | Cursor-paginated activity timeline, newest first (`events`, `next_cursor`). Same access as the key route; internal items are filtered in SQL for agency roles |
| POST | `/api/tickets/{ticket}/transitions` | `{action, comment?}`: run a workflow action from `TicketWorkflow` (start, send_to_review, mark_blocked, unblock, request_client_input, provide_input, approve, request_changes, restart_work). Required comments are saved as public comments linked to the `status_changed` event |
| GET | `/api/agency/attention` | Agency owner/client: up to 5 tickets waiting on the client (in review or asking a question), longest-waiting first, plus `total` |
| GET | `/api/admin/tickets` | Admin read-only paginated ticket directory |
| GET | `/api/work-items/mine` | Employee list of assigned projects |
| GET | `/api/admin/projects` | Admin read-only, searchable paginated view of all projects and schedules |
| GET | `/api/admin/dashboard` | Admin only (401 guest, 403 other roles). The whole dashboard in one call, from SQL aggregates (query count does not grow with data). Optional `?week=YYYY-MM-DD` (any date in the week; default current), `?employee_filter=all\|available\|held\|overloaded` (narrows `employee_capacity` only) and `?status=` (legacy recent-projects filter). Returns `week_start`/`week_end` (Monday to Saturday), `weekly_budget_hours` (36), `generated_at`; `employees` {total, active, inactive, available (active, no active assignment), held, overloaded (over 90% of the budget), avg_utilization_percent}; `agencies` {total, active, suspended}; `employee_capacity` (top 8 active employees by utilization: id, name, photo_url, department or null, status, holder {name, agency_id, agency_name} or null, used_hours, budget_hours, utilization_percent); `agency_rows` (top 8 by hours booked this week: id, name, owner_name, status, employees_held, open_projects, hours_booked_this_week); `attention` (most severe first: type, severity, count, label, link; overdue projects, expired documents, overloaded employees, projects due within 48 h, documents expiring within 30 days, employees available 7+ days, agencies with no held employees and no open projects); `work_summary` {projects {total, scheduled, in_progress, completed}, tickets {total}}. Used hours are non-cancelled `work_item_allocations` in the week, the same rule as the assignment usage endpoint. `agency_rows` also carry `owner_id`, `owner_email`, `plan`, `seats_allowed`, `hours_included`, `renewal_date` and `has_contract` (all null/false until contracts exist), and `contracts` {available, with_contract, without_contract, expiring}. The earlier project-centric keys (`projects` with `overdue`/`due_soon`, `tickets`, `scheduled_hours`, `recent_projects`, `attention_projects`) are still returned |
| GET | `/api/admin/assignments` | Admin read-only paginated employee-assignment directory |
| DELETE | `/api/admin/assignments/{assignment}` | Admin ends an assignment and releases its future work allocations |
| POST | `/api/auth/login` | Sign in |
| POST | `/api/auth/logout` | End the current session |
| GET | `/api/auth/me` | Return the authenticated user and role |
| POST | `/api/auth/forgot-password` | Request a reset link |
| POST | `/api/auth/reset-password` | Reset password with a time-limited token |
| GET | `/api/notifications?unread=1&cursor=` | The caller's own notifications, newest first, cursor-paginated (`notifications`, `next_cursor`, `unread_count`) |
| GET | `/api/notifications/unread-count` | `{count}` for the header bell |
| POST | `/api/notifications/{id}/read` | Mark one notification read (404 unless it belongs to the caller) |
| POST | `/api/notifications/read-all` | Mark every notification read |
| GET / PUT | `/api/notifications/preferences` | Per-type in-app and email switches (`needs_input` in-app is locked on) |
| GET | `/api/tickets/{ticket}/attachments` | Attachments the viewer may see (internal ones are hidden from agency roles), plus the upload limits |
| POST | `/api/tickets/{ticket}/attachments` | Multipart `files[]` (+ optional `comment`, `is_internal`) |
| GET | `/api/attachments/{attachment}/download` | Streams the file through Laravel after the ticket authorization check; `?inline=1` only for png/jpeg/gif/webp |
| DELETE | `/api/attachments/{attachment}` | Soft-deletes an attachment (the uploader, an admin, or the ticket's agency owner) |
| POST | `/api/tickets/{ticket}/time/start` | Assigned employee starts a timer (in progress or changes requested only); starting another stops the first |
| POST | `/api/time/stop` | Stop the employee's running timer and log the time |
| GET | `/api/time/active` | The employee's running timer plus `server_time` for clock correction |
| POST | `/api/tickets/{ticket}/time` | Assigned employee logs a manual entry `{entry_date, minutes, note?}` |
| PATCH / DELETE | `/api/time/{entry}` | Employee changes their own entry for 48 h; admins may always, but must send a `reason` |
| GET | `/api/tickets/{ticket}/time` | Entries (hidden from agency roles when `time.agency_sees_entries` is false) and totals |
| GET | `/api/time/week?week=YYYY-MM-DD` | Employee timesheet, Monday to Saturday: logged versus scheduled per day and per ticket |
| GET | `/api/admin/time-summary` | `from`, `to`, `group_by=employee\|agency\|project`, optional `format=csv` |
| GET | `/api/agency/time-summary` | Agency owner: same, for their own agency, `group_by=employee\|project` |

Roles are `admin`, `team_lead`, `employee`, `qa`, `agency_owner`, `agency_member` (legacy), and `agency_client`. Only admins can create or manage employee accounts. Employee accounts may be linked to an agency or remain shared with a null agency ID. Admins can also create and manage agency owners. When an admin creates an agency owner, the request includes the agency name and the backend creates the agency and owner together; the database generates the agency ID. Agency owners can manage clients in their own agency, but employee accounts are view-only. Agency owners and clients can hold multiple employees, but each employee can have only one active holder at a time. Assignments are private to their holder: owners and clients can only list, read usage or capacity for, and end the assignments they hold themselves (there is no agency-wide assignment view, and owners cannot release a client's employee); admins see and end every assignment through `/api/admin/assignments`, and projects stay visible to the whole agency. Deactivating or deleting a holder automatically ends the assignments they hold (cancelling the open projects on them and releasing their future hours), so an employee can never stay held by an account that cannot release them. Shared employees become unavailable to all other agencies while held. Agency roles and tenant IDs are assigned by the backend, never trusted from client input. Invited users receive a password setup email and set their own password through the existing password-reset flow. The `agency_id` and role returned by `/api/auth/me` are for frontend menus only; backend routes enforce authorization and tenant scoping. Agency-facing routes (team, directory, profile, agency users, employee holds, capacity and projects) run the `agency.active` middleware after `auth` and `role:`, which returns 403 when the caller has no agency or their agency is suspended. Ticket routes intentionally keep their own check and answer 404, and employee routes still allow a null agency.

Agency clients and owners create projects against an active employee assignment they hold. A project is limited to 180 estimated hours, and each client may have at most three open projects. The scheduler allocates earliest-first Monday through Saturday, skips Sundays and the `non_working_dates` configured in `config/scheduling.php`, and caps each employee at six hours per workday (36 hours per week). Today's capacity is available only before `today_cutoff_hour` (default 12) in the configured application timezone. Capacity checks and reservations are enforced transactionally; cancelled projects and ended assignments release allocations dated today or later. `GET /api/agency/capacity` returns the per-day availability the scheduler would use for a held employee (Sundays, non-working dates, today after the cutoff hour, the 6 h daily cap and bookings from every agency), so the UI never has to repeat that maths. Project payloads include `tickets_total`, `tickets_done`, `tickets_open` (cancelled tickets excluded) and `next_ticket` (the first non-done, non-cancelled ticket by position, or `null`). Clients and owners can see project details, employee selections, and statuses within their own agency; clients may create projects only against assignments they hold. Employees do not set project status directly: a project moves to in-progress when its first ticket starts and to completed when all of its non-cancelled tickets are done; admins can view all projects but cannot edit them.

Capacity rules live in `config/scheduling.php`: `daily_capacity_hours` (6), `weekly_capacity_hours` (36) and `working_days` (ISO weekdays 1 to 6, Monday to Saturday), next to `non_working_dates` and `today_cutoff_hour`. The scheduler, usage reports and capacity endpoint all read these keys, and assignment payloads (`capacity: {daily_hours, weekly_hours}`) and `GET /api/work-items/mine` expose them so the UI never hardcodes them. Weekly usage reports `used` (every non-cancelled hour booked on the assignment that week, completed work included), `remaining` (weekly capacity minus `used`) and `bookable_remaining` (what the scheduler would still accept for that employee that week: completed and cancelled bookings are freed, Sundays, non-working dates, past days and today after the cutoff are skipped, and every agency's bookings count). Ticket payloads include `creator: {id, name}` (or `null` when that account was deleted).

Every ticket has a human key (`PREFIX-n`, for example `NORT-42`): each agency gets an immutable `ticket_prefix` (generated from its name, locked once tickets exist) and a `ticket_sequence`, and numbers are allocated under a row lock inside the ticket-creation transaction. Every change is written to the append-only `ticket_events` timeline (created, updated, status_changed, rescheduled, reordered, cancelled, comment) with actor name and role snapshots, so history survives user deletion; system-triggered changes use a null actor named "System". Events can never be updated or deleted. Comments may be `is_internal` (admin and the assigned employee only; agency roles can neither create nor read them, filtered in SQL). When the planner moves a ticket's dates it records a `rescheduled` event with a plain-English reason, and the `update` and `reorder` responses include `rescheduled: [{key, from, to, reason}]`. Statuses are todo, in_progress, in_review, blocked, waiting_on_client, changes_requested, done and cancelled. `TicketWorkflow` is the single transition table: employees start, send to review, block, ask the client and rework; only the client (an owner or client of the ticket's agency) approves, requests changes or answers questions. With `scheduling.require_client_approval` (default true) the employee can no longer move a ticket from in_review to done (422); set it to false to restore the old behaviour. Ticket payloads carry `key`, `allowed_actions` (computed for the viewer), `waiting_on` (`client` for in_review and waiting_on_client, `employee` for todo, in_progress and changes_requested, `team` for blocked), `waiting_since` and `status_reason`. Blocked and waiting tickets keep their scheduled hours. The admin ticket list accepts `status`, `agency_id`, `employee_id`, `waiting_on` and `search` (key or title).
Tickets are hour slices of a project's existing schedule and inherit its employee assignment. Agency clients and owners may create, edit, reorder, and cancel tickets for any project in their own agency while its assignment is active and the project remains open. Tickets are planned earliest-first within the project's allocated dates, and their combined hours cannot exceed the project's estimated hours. The assigned employee advances tickets through `todo` → `in_progress` → `in_review` → `done`; ticket progress updates the project status. Project cancellation cancels its tickets and removes all ticket allocations.

### GoHighLevel brief and ticket work orders

Clients describe their GoHighLevel setup once per project (`work_item_briefs`, 1:1 with a project) and each ticket carries only what is specific to that task (`ticket_details`, 1:1, plus `ticket_checklist_items`). Everything is optional and additive: payloads without `ghl_brief` or `work_order` behave exactly as before. The nested keys are `ghl_brief` and `work_order` (not `brief`/`details`, which the older brief fields and the service catalog already use).

- **Create with it**: `POST /api/agency/projects` accepts `ghl_brief`; `POST /api/agency/projects/{id}/tickets` and `PATCH /api/agency/tickets/{id}` accept `work_order`. Both are saved inside the same transaction as the project or ticket (`ProjectBriefService`, `TicketDetailsService`), so a failed plan rolls everything back. `estimated_hours`, `position`, status transitions and `TicketPlanner` never read a work order.
- **Shape**: a brief has twelve sections (`client`, `ghl`, `scope`, `pipelines`, `data`, `automations`, `funnels`, `communications`, `calendars`, `payments`, `branding`, `handover`) plus `notes.client`; a work order has `work_type`, `brief_section`, `labels`, `requirements`, `ghl` (`location_id`, `refs[{type,name,ghl_id,url}]`), `inputs`, `dependencies` (same-project tickets only), `environment`, `testing`, `checklist`, `notes`, `review`. Caps: 50 checklist items, 30 `ghl_refs`, 50 stages, 100 custom fields. Rules live in `app/Support/GhlBrief.php` and `TicketWorkOrder.php`.
- **Server-owned fields** (`completeness`, `source`, `ghl_synced_at`, `delivery`, `blocked`, `notes.internal`) are `prohibited` in agency payloads.
- **Lists stay light**: projects carry `brief_summary`, tickets carry `details_summary` (work type, checklist x/y, completeness, blocked). The full objects are returned only by `GET /api/agency/projects/{id}` and `GET /api/admin/projects/{id}` (`project.ghl_brief`) and `GET /api/tickets/key/{key}` (`work_order`, and `project.ghl_brief`). Both are eager-loaded.
- **Who sees and writes what**: agency owner/client write the brief and work order (open project, active assignment). The assigned employee writes only delivery, the blocked note and ticks the checklist. Admin reads everything and writes only internal notes. Internal notes are never sent to agency users.
- **Review gate**: moving a ticket to `in_review` (status route or the `send_to_review` transition) needs `delivery.done` plus at least one link, but only when the ticket has a work order (a work type, checklist, requirements or GHL targets). Tickets without one are never held back.
- **Completeness** (0-100) is scored against the work type's recommended fields in `config/ticket_templates.php` (the brief's list is `GhlBrief::RECOMMENDED`). It only drives a gentle "Missing: ..." hint and never blocks saving.
- **No secrets**: briefs and tickets never store passwords or API keys. Unknown keys are dropped by validation, and the brief asks for "credentials shared via" instead. The older encrypted `access_details` is unchanged.
- **Not built yet**: GoHighLevel API prefill (`GhlGateway`, `ghl_connections`, `/api/agency/ghl/*`), file uploads for briefs and tickets (links only), and "field changed" activity entries.
### Support module (Digikon tickets)

One ticket entity with a `kind` (`support`, `fulfillment`, `change_request`). Tickets made through the module have a `type` and use the lifecycle statuses; the older hour-slice tickets have no `type`, keep their legacy statuses and the planner, and are shown with a mapped status (`todo` as Assigned, `in_review` as QA, `blocked` as Waiting for third party, `waiting_on_client` as Waiting for agency, `done` as Closed, `cancelled` as Rejected). Support and change-request tickets have no project and never touch `TicketPlanner`. Every ticket has a `ref` such as `DG-1048` (prefix `TICKET_ID_PREFIX`, race-safe counter in `ticket_sequences`); existing tickets were given refs in id order. The old `PREFIX-n` key still works.

- **Statuses**: new, triage, assigned, in_progress, waiting_agency, waiting_third_party, qa, resolved, closed, rejected. `App\Services\Support\SupportWorkflow` is the only place they change: one transaction, the transition table, the data each step needs (waiting needs a reason and a follow-up date, resolve and close need a summary and a category, rejecting and reopening need a reason, a QA fail needs a note), who may do it, the reopen window (14 days) and the rule that on a critical ticket the QA reviewer is not the builder (an admin may override with a reason).
- **Capabilities**: `users.staff_capabilities` (`triage`, `builder`, `qa`, `delivery_manager`, `finance`). Admins hold all. An employee with none keeps seeing only their own work. Set them with `PATCH /api/staff/people/{user}/capabilities` (admin only).
- **Visibility**: `TicketPresenter` builds the agency and staff payloads from allow-lists. Agency users never receive internal notes, internal events, internal files, SLA states, time, cost, scope or billing, QA notes or the secret flag; internal items are also filtered in SQL for comments, events, attachments, search and notifications.
- **SLA**: `config/support.php` holds the targets (business minutes per priority), support hours, `SUPPORT_TIMEZONE` (default `Asia/Karachi`), holidays, the 75% at-risk ratio, the reopen and auto-close windows. `SlaCalculator` does business-time arithmetic (holidays, timezone, daylight saving); `SlaService` runs a response and a resolution clock per ticket and pauses while a ticket waits for the agency. Agency users see only "We aim to respond by ...".
- **Scheduled**: `php artisan tickets:sla-sweep` every minute (see `routes/console.php`; needs `schedule:work` or a cron entry). It flags at-risk and breached clocks, escalates tickets nobody has picked up (2 business hours, 15 minutes for P1), reminds agencies after 2 business days and escalates after 5, and closes resolved tickets the agency never answered after 7 days. Each step writes an event once, so running twice never notifies twice.
- **Notifications**: `SupportNotificationPlanner` is the notification matrix (created, critical, assignment, agency reply, status changes, resolved, closed, reopened, SLA, escalation, reminders). Internal events only reach staff.
- **Secrets**: `SecretScanner` warns (never redacts) on text that looks like a password or key and flags the ticket for staff; `POST /api/support/scan-text` powers the live warning.
- **Attachments**: files use the existing private-disk upload (content-sniffed, 20 MB; video mp4, mov, webm up to 100 MB with `ATTACHMENTS_MAX_VIDEO_KB`; zip stays off unless `ATTACHMENTS_ALLOW_ARCHIVES=true`); links (Loom, Drive, any URL) are first-class (`POST /api/tickets/{ticket}/links`); each item is public or internal.
- **AI**: `TicketAssistant` is an interface with `NullTicketAssistant` bound. Nothing calls out.

| Method | Path | Notes |
|---|---|---|
| GET | `/api/agency/support/form` | Types and their questions, services, priorities, the agency's end clients and open projects |
| POST | `/api/agency/support/suggest-priority` | Priority from the impact answers |
| GET / POST | `/api/agency/support/tickets` | List (`tab=open\|waiting\|resolved\|all`, `q`, `status`, `priority`, `type`, `end_client_id`, `project_id`, `from`, `to`) with tab counts; submit |
| GET | `/api/agency/support/tickets/{ref}` | Agency view of one ticket |
| POST | `/api/agency/support/tickets/{ref}/reopen`, `/confirm`, `/feedback` | Reopen (reason, inside the window), confirm the resolution, 1 to 5 satisfaction |
| GET / POST | `/api/agency/support/end-clients` | The agency's own customers |
| POST | `/api/support/scan-text` | Secret warning while typing (agency and staff) |
| POST | `/api/tickets/{ticket}/comments` | Public reply, or internal note with `is_internal` (staff) |
| POST | `/api/tickets/{ticket}/links` | Add a link (internal allowed for staff) |
| GET | `/api/staff/tickets` | Queue: `view` (my, new, unassigned, critical, waiting_agency, waiting_third_party, qa, resolved, reopened, all), filters, `sort=updated\|sla\|priority\|created`, counts per view |
| GET / PATCH | `/api/staff/tickets/{ref}` | Staff view; priority, kind, service, estimate |
| POST | `/api/staff/tickets/{ref}/transition` | `{to, ...}` the data the step needs |
| POST | `/api/staff/tickets/{ref}/assign` | Owner, team, reviewer (triage or delivery manager) |
| POST | `/api/staff/tickets/{ref}/scope`, `/scope/approve` | Scope and billing: a builder proposes, a delivery manager decides |
| GET / POST | `/api/staff/tickets/{ref}/time` | Time entries and the estimate-versus-actual rollup |
| POST | `/api/staff/tickets/{ref}/convert` | `{to: project, assignment_id}` or `{to: change_request}`; idempotent, keeps the history, links both ways |
| GET | `/api/staff/dashboard?from&to` | The KPIs of the spec (managers, finance, admin) |
| GET / POST / PATCH / DELETE | `/api/staff/canned-responses` | Saved replies |
| GET | `/api/staff/people` | Staff, capabilities, resolution categories and teams for the pickers |

Not built yet: generic approvals, agency entitlements and the included-revision check, recurring tickets, custom saved views, the workload view, the settings screen (SLA numbers and holidays are edited in `config/support.php` for now), signed short-lived download URLs for attachments, per-type QA checklists and bug QA runs, and ticket relations and watchers.

### Running the queue worker and scheduler

Notifications and their emails are queued (`QUEUE_CONNECTION=database`, `afterCommit`), so **`php artisan queue:work` must be running** or nobody receives anything. `composer dev` already starts `queue:listen`. Time tracking needs the scheduler: the hourly `time:stop-stale-timers` command closes timers that ran past 12 hours. Locally run `php artisan schedule:work`; in production add the usual cron entry `* * * * * php artisan schedule:run`. Locally `MAIL_MAILER=log` writes emails to `storage/logs/laravel.log`.

### Notifications

Every ticket event is turned into notifications by one listener (`SendTicketNotifications` on `TicketEventRecorded`). The actor is never notified, inactive users get nothing, and internal comments only ever reach admins and the assigned employee (in-app, email and API alike). Payload: `{type, ticket_id, ticket_key, title, body, url_path, actor_name}`.

| Event | Recipients | Class |
|---|---|---|
| Public comment | the other side (employee when a client commented; the ticket creator and agency owner when the employee or an admin did) | `TicketCommented` |
| Internal comment | admins and the assigned employee | `TicketCommented` |
| Moved to in review | ticket creator and agency owner | `TicketNeedsYourInput` |
| Moved to waiting on client | ticket creator and agency owner | `TicketNeedsYourInput` |
| Changes requested | assigned employee | `TicketStatusChanged` |
| Blocked | agency owner and admins | `TicketStatusChanged` |
| Done | assigned employee and ticket creator | `TicketStatusChanged` |
| Rescheduled | ticket creator and agency owner, one notification per ticket per planner run | `TicketRescheduled` |
| Cancelled | ticket creator and assigned employee (unless they cancelled it) | `TicketCancelled` |
| Created | assigned employee | `TicketAssigned` |

Repeat notifications of the same type for the same ticket within 10 minutes are coalesced into one unread row with a `count`. Preferences default to in-app on for everything and email on only for "needs your input" and "rescheduled"; the in-app switch for "needs your input" cannot be turned off. Emails carry a `[KEY] Title` subject, a one-line summary, at most 200 characters of the public comment, a button to `FRONTEND_URL` + `url_path` and a footer link to `/settings/notifications`.

### Team queue and urgent requests

Each employee held by an agency has a **queue**: the ordered list of that assignment's open projects (`work_items.queue_position`, audit trail in `queue_events`). `EmployeeQueuePlanner` allocates the whole queue earliest-first in queue order (6 h/day, 36 h/week, Mon-Sat, `non_working_dates`, today only before `today_cutoff_hour`); hours already worked and today's hours once the cutoff has passed are frozen, and a project with a ticket in progress stays in front and keeps today's hours. A reorder (or a new project) that would push a project further past its due date than it already is fails as a whole with `422` (`errors.ordered_work_item_ids` plus a `conflicts` list) and saves nothing. Project creation appends to the end of the queue through the same planner. There is no auto-assignment: the owner assigns and orders by hand, helped by `capacity.free_from`.

| Endpoint | Who |
|---|---|
| `GET /api/agency/assignments/{id}/queue` (`now`, `up_next`, `capacity`) | agency owner, agency client |
| `POST .../queue/preview`, `PATCH .../queue/reorder` (`ordered_work_item_ids`, every open project once; 20/min) | agency owner |
| `GET /api/work-items/mine/queue` | the employee (read-only, no prices) |
| `GET /api/admin/employees/{id}/queue`, `GET /api/admin/rush-requests`, `POST .../{id}/quote`, `POST .../{id}/reject`, `GET /api/admin/charges` | admin |
| `GET/POST /api/agency/rush-requests`, `PATCH .../{id}/cancel`, `POST .../{id}/accept`, `POST .../{id}/decline` | owner and client raise/cancel; **only the owner accepts or declines** |

**Urgent ("rush") requests** come in two kinds. `priority_insert`: a project jumps the held employee's queue, right behind work already in progress (never interrupting it). `extra_employee`: the agency asks for another team member; the admin picks an available employee (never shown to the agency) and quotes; on acceptance a temporary assignment (`employee_assignments.expires_at`, `source = rush`) with the requested project is created. An admin quotes an amount in integer minor units (`config/billing.php`: currency, default validity); the owner accepts, and in one transaction the project is planned, an `agency_charges` row (`unbilled`) is written and the request becomes `fulfilled`. If the insert would push other projects past their due dates, the accept call returns `422` with `requires_confirmation` and the `impact`, and needs `confirm_impact: true`. There is no payment gateway: `AgencyCharge` is the invoice-ready ledger and the documented extension point. Rush work obeys capacity and the 180 h cap. An extra-employee rejection always reads "No team member is available for this request."

**Running it locally on Windows / XAMPP.** Two extra terminals next to `php artisan serve` (or use `composer dev`, which starts the worker):

```powershell
php artisan queue:work      # sends queue and rush notifications (QUEUE_CONNECTION=database)
php artisan schedule:work   # runs rush:expire-quotes and assignments:expire-temporary every 5 minutes
```

Both commands can also be run by hand (`php artisan rush:expire-quotes`, `php artisan assignments:expire-temporary`). Notifications are queued jobs (`NotifyRushRequest`, `NotifyQueueChange`); the replan itself always runs synchronously inside the request. In tests the queue is `sync`, and `Queue::fake()` / `Notification::fake()` make delivery assertable.

### More hours on a ticket

The assigned employee can ask the agency for extra hours on an open ticket (`POST /api/tickets/{ticket}/hours-requests` with `hours` 0.25 to 180 and a `reason`; one open request per ticket; 10 a minute) and withdraw it (`PATCH /api/hours-requests/{id}/cancel`). The agency's owner and clients see the open ones (`GET /api/agency/hours-requests`, also as `hours_request` on the ticket) and answer with `POST /api/agency/hours-requests/{id}/approve` or `/decline` (optional `note`). Approving adds the hours to the ticket and re-plans through the normal planner, so the project's hour cap still applies: if the project has no hours left the call returns 422 and nothing changes. The owner and the ticket's creator are notified of a request, the employee of the answer ("Hours requests" preference, email on by default). Requests are scoped to the caller's own ticket or agency; anything else is a 404.

### Work reminders

`app:send-work-reminders` runs every 30 minutes (`withoutOverlapping`, app timezone) and adds to the existing notifications: same bell, same `/api/notifications` routes, preference type "Work reminders" (in-app by default). It covers employees only (active accounts), for open projects and for `todo` / `in_progress` tickets on open projects: `project_start` / `ticket_start` on the start date, `deadline_soon` 3 days and 1 day before the due date, `deadline_today`, then `deadline_overdue` once a day until the work is closed. Each reminder stores a `dedupe_key` (`project:{id}:due:1`, `ticket:{id}:overdue:2026-12-09`, ...), so running the command again never repeats one, and a failed delivery is retried on the next run. Locally run `php artisan schedule:work` (or the command by hand); in production add the cron entry `* * * * * php artisan schedule:run`.

### Attachments

Files are stored on a private disk (`config/attachments.php`, never `public`) under UUID names and are only reachable through `GET /api/attachments/{id}/download`, which re-checks ticket access, sends `X-Content-Type-Options: nosniff` and `Content-Disposition: attachment` (inline only for png/jpeg/gif/webp). The file content is sniffed with `finfo` and must agree with the extension; svg, html, js, php and executables are rejected, zip only when `ATTACHMENTS_ALLOW_ARCHIVES=true`. Attachments are scanned through the `AttachmentScanner` contract (`NullScanner` by default; bind a real one such as ClamAV in `AppServiceProvider`). Adding or removing a file records an `attachment_added` / `attachment_removed` event (internal when the file is internal).

| Key (`config/attachments.php`) | Env | Default |
|---|---|---|
| `disk` | `ATTACHMENTS_DISK` | `local` |
| `max_file_kb` | `ATTACHMENTS_MAX_FILE_KB` | 10240 |
| `max_files_per_upload` | `ATTACHMENTS_MAX_FILES_PER_UPLOAD` | 10 |
| `max_files_per_ticket` | `ATTACHMENTS_MAX_FILES_PER_TICKET` | 50 |
| `allow_archives` | `ATTACHMENTS_ALLOW_ARCHIVES` | false |
| `temporary_urls`, `temporary_url_minutes` | `ATTACHMENTS_TEMPORARY_URLS`, `ATTACHMENTS_TEMPORARY_URL_MINUTES` | false, 5 (S3-style disks only) |

PHP limits apply before Laravel does: set `upload_max_filesize` and `post_max_size` in `php.ini` at least to `max_file_kb` times `max_files_per_upload` (for example `12M` and `110M`), and `max_file_uploads` to at least 10. A request over `post_max_size` arrives empty and fails with a validation error.

### Time tracking

Only the employee assigned to a ticket logs time; admins can correct or remove entries but must give a reason; agency users are read-only. Rules live in `config/time.php`:

| Key | Env | Default | Meaning |
|---|---|---|---|
| `timer_statuses` | | in_progress, changes_requested | statuses in which a timer can run |
| `max_timer_hours` | | 12 | the hourly command closes older timers at exactly this length and flags them `auto_stopped` |
| `min_entry_minutes`, `max_entry_minutes` | | 1, 720 | size of one manual entry |
| `max_days_back` | | 14 | oldest date an employee may log for (admins are exempt); never future dates |
| `max_daily_hours` | | 16 | total per employee per day |
| `edit_window_hours` | | 48 | how long an employee can edit or delete their own entry |
| `agency_sees_entries` | `TIME_AGENCY_SEES_ENTRIES` | true | when false agency users see totals but not the individual entries |

There is one running timer per employee: starting another stops the first. Timers also stop automatically when the ticket moves to blocked, waiting on client, in review or done, when it is cancelled, when the project is cancelled, when the assignment ends and when the employee is deactivated (the stop reason is stored). Each manual entry and each stopped timer records one public `time_logged` event; starting a timer records nothing. Overruns are only flagged (`over_estimate`); nothing is rescheduled automatically. Ticket payloads carry `logged_hours`, `estimated_hours`, `over_estimate` and `timer_running`. Summaries aggregate in SQL and return, per group, logged, scheduled and estimated hours plus utilization (logged divided by scheduled); `format=csv` streams a download with spreadsheet formula characters neutralised.

### Agency branding (white label)

One `agency_branding` row per agency. Everyone in the agency (owner, client, legacy member) is themed by it; employees and admins keep the default look. The agency is always the signed-in user's own, never taken from input, and suspended agencies get 403 like the other agency routes.

**Storage.** Uploads go to the `public` disk under `agency-branding/{agency_id}/` with random 40-character names (the client filename is ignored; the extension comes from the sniffed content). Replacing or removing a file deletes the old one. URLs are absolute, built from `APP_URL`. Run this once per environment so the files are served:

```bash
php artisan storage:link
```

**SVG is rejected, not sanitised.** An SVG can carry `<script>`, `on*` handlers, `javascript:` links and `foreignObject`, and a regex or DOM strip is easy to bypass and hard to prove complete; a stored file served from the app's origin would be stored XSS. Logos accept png, jpg and webp; the favicon accepts png and ico. Files are validated by content (`mimes` sniffs the real type) and by dimensions, which works without the GD extension. Revisit SVG only with a maintained sanitiser (for example `enshrined/svg-sanitize`) and a `Content-Security-Policy`/`nosniff` response for the storage path.

**Colours** must be `#RRGGBB`. The primary colour carries white button text and the accent colour may be used as text on white, so both must reach a WCAG contrast ratio of at least 4.5:1 against white (422 otherwise). The stock ClientHub teal `#0D9488` is only 3.7:1, so use `#0F766E` (5.5:1) for a teal brand. A colour left empty means "use the ClientHub default".

### Reports

Each report is one small class in `app/Services/Reports/` (`OverviewReport`, `EmployeeUtilizationReport`, `AgencySummaryReport`, `ProjectReport`, `TicketReport`, `AssignmentReport`); `ReportController` only validates (`ReportRequest`), builds a `ReportScope` and runs the report. Numbers are computed in SQL with grouped queries and per-row subqueries (the number of queries does not grow with the rows). The scope comes from the signed-in user, never from input: admin sees everything, an agency owner is limited to their `agency_id`, an agency client to projects where they are the client and assignments where they are the holder. Filters can only narrow it (a foreign `employee_id` or `client_id` simply returns nothing). Suspended agencies get 403.

Filters (all optional): `from`, `to` (`YYYY-MM-DD`, at most one year, default the last 30 days), `status` (per report: project statuses for employees and projects, ticket statuses for tickets, `active|ended` for assignments, `active|suspended` for agencies; ignored by the overview), `agency_id` (admin only), `employee_id`, `client_id` (admin and owner only), `page`, `per_page` (max 100), `sort` and `direction` (whitelisted per report, otherwise 422). The table reports return `rows`, `pagination`, `totals` and a `summary`; the overview returns counts, `series` (`{labels, values}`) and an `attention` block.

What the range means: employees count scheduled hours inside it; projects are those whose start-to-due window overlaps it; tickets are those created in it (throughput counts tickets that reached `done` in it, by ISO week of their last update, which is their completion time because `done` is final); assignments are those active at any time in it. Overview entity counts (agencies, users, employees) are a snapshot of now.

**Scheduled is not worked.** The employee report shows hours the scheduler booked (`work_item_allocations`) against capacity (working days in the range x 6 h, so 36 h for a Monday to Saturday week, minus `scheduling.non_working_dates`). Utilization is scheduled / capacity, and a non-admin sees only the hours booked on their own projects. Logged time is in the time summary. Bands: under 50% low, 50 to 90% healthy, over 90% high. Overdue = open and past due; at risk = open, due within two days and still has open tickets.

**CSV** (`?format=csv`): same filters and scope, streamed, UTF-8 with a BOM for Excel, a header row, and cells starting with `=`, `+`, `-`, `@`, tab or CR get a leading apostrophe. At most 10,000 rows are exported; the response headers `X-Export-Row-Limit` and `X-Export-Truncated` say so. The overview exports its key metrics as `Metric,Value`.

Indexes added for these queries are in `2026_11_18_200000_add_report_indexes.php`. The overview is not cached (it is a handful of grouped queries); add a 60 s cache keyed by scope and filters if it ever shows up in profiling.

### Assistant ("Ask" button)

`GET /api/assistant/questions` returns the signed-in role's preset questions as `{categories: [{name, questions: [{key, label, description, params}]}]}`; `POST /api/assistant/ask` with `{question_key, params}` returns `{question_key, title, generated_at, blocks, empty_message}`. Blocks are typed (`stats`, `list`, `progress`, `table`, `text`) and every answer is read from the database when it is asked; no language model is involved. Question sets live in `app/Services/Assistant/` (one class per role: admin, agency owner, agency client, agency member, employee, plus `my_account` for everyone, including team leads and QA). A role asking another role's question gets 404; a `select` parameter must be one of the options offered to that user (an owner can only ask about specialists their agency holds), otherwise 422. Data is scoped with the same `ReportScope` as the reports, so the same tenant rules apply. Suspended agencies and deactivated users get 403. Rate limit: `throttle:insights` (60 a minute per user).

### Employee activity (admin only)

Employee sign-ins, sign-outs, ticket status changes, comment events (never their text), project status changes and idle periods are written to `employee_activity_logs` by one `ActivityLogger` service; the server picks every event type and timestamp. A `work_sessions` row starts at login and ends at logout, or after `ACTIVITY_SESSION_TIMEOUT_MINUTES` (10) without a heartbeat (`php artisan activity:maintain`, scheduled every five minutes, also prunes rows older than `ACTIVITY_RETENTION_DAYS`). The employee UI sends `POST /api/employee/heartbeat` about once a minute while the tab is visible with only an `idle` flag (no interaction for `ACTIVITY_IDLE_AFTER_MINUTES`, default 5); the server measures the elapsed time itself and caps each beat at 120 s. No keystrokes, screenshots or page content are collected. `GET /api/admin/employees/{id}/activity` (paginated; `from`, `to`, `event_type`) and `GET /api/admin/employees/{id}/performance` (daily and weekly active, logged and scheduled hours, tickets completed, average logged hours per completed ticket, late and early first sign-ins against `ACTIVITY_WORKDAY_START`) are admin-only; every other role gets 403. Employees see a one-line notice at first sign-in and in the invite email.

### Project and ticket briefs

Projects accept optional `objective`, `deliverables`, `target_url`, `staging_url`, `repo_url`, `platform` (ghl, wordpress, custom, other), `tech_stack`, `brand_assets_url`, `access_details`, `reference_links`, `acceptance_criteria`, `budget`, `brief_priority`, `contact_name` and `contact_email`. Links must be http(s). `access_details` is encrypted at rest and returned only by single-project reads (agency, admin and the assigned employee), never in lists. Tickets accept `steps_to_reproduce`, `expected_result`, `actual_result`, `affected_url`, `environment`, `severity`, `due_preference` and `related_ticket_ids` (same project only); `due_preference` is a hint, the planner still schedules. Project files live in the polymorphic `attachments` table on the private disk (`GET/POST /api/projects/{id}/attachments`, `GET /api/project-attachments/{id}/download`, `DELETE /api/project-attachments/{id}`): the agency's owner and clients upload, the agency, the assigned employee and admins download, everyone else gets 404. Project briefs also accept zip (`ATTACHMENTS_BRIEF_ALLOW_ARCHIVES`, default true); ticket files keep the stricter `ticket_attachments` rules.
### Testing with Postman

Use the same host consistently throughout this flow (for example, `http://127.0.0.1:8000`). In Postman, enable its cookie jar for that host, and add these headers to JSON requests:

```text
Accept: application/json
Content-Type: application/json
X-XSRF-TOKEN: <URL-decoded value of the XSRF-TOKEN cookie>
```

1. Start Laravel with `php artisan serve`, and run `php artisan migrate`.
2. Create the first admin from a trusted terminal with `php artisan app:create-admin "Administrator" admin@example.com`.

3. In Postman, send `GET http://127.0.0.1:8000/api/auth/csrf-cookie`. Postman should retain the `laravel_session` and `XSRF-TOKEN` cookies. Copy the `XSRF-TOKEN` cookie value, URL-decode it, and use it in the `X-XSRF-TOKEN` header for the following POST requests. Keep Postman’s cookie jar enabled so the session cookie is sent too.
4. Send `POST http://127.0.0.1:8000/api/auth/login` with:

   ```json
   {
     "email": "admin@example.com",
     "password": "Your-Strong-Local-Password!"
   }
   ```

5. Confirm the session with `GET http://127.0.0.1:8000/api/auth/me`.
6. As the admin, invite an employee with `POST http://127.0.0.1:8000/api/auth/users`:

   ```json
   {
     "name": "Employee",
     "email": "employee@example.com",
     "role": "employee"
   }
   ```

   Valid roles are `admin`, `team_lead`, `employee`, `qa`, `agency_owner`, `agency_member`, and `agency_client`. Employee accounts are created by admins and are shared across agencies. Agency owners can invite clients through `/api/agency/users`; the backend always assigns the owner's agency. An unauthenticated request returns 401; a signed-in user without the required role returns 403.
7. Test `POST /api/auth/forgot-password` with `{"email":"admin@example.com"}`. With `MAIL_MAILER=log`, find the reset URL in `storage/logs/laravel.log`; submit its `token` and `email` with `password` and `password_confirmation` to `POST /api/auth/reset-password`.
8. Send `POST /api/auth/logout`, then verify `GET /api/auth/me` returns 401.

Refresh the CSRF-cookie request and update the token header if Postman reports a CSRF mismatch. Do not send real credentials to a shared Postman workspace or commit them into a collection.

## About Laravel

Laravel is a web application framework with expressive, elegant syntax. We believe development must be an enjoyable and creative experience to be truly fulfilling. Laravel takes the pain out of development by easing common tasks used in many web projects, such as:

- [Simple, fast routing engine](https://laravel.com/docs/routing).
- [Powerful dependency injection container](https://laravel.com/docs/container).
- Multiple back-ends for [session](https://laravel.com/docs/session) and [cache](https://laravel.com/docs/cache) storage.
- Expressive, intuitive [database ORM](https://laravel.com/docs/eloquent).
- Database agnostic [schema migrations](https://laravel.com/docs/migrations).
- [Robust background job processing](https://laravel.com/docs/queues).
- [Real-time event broadcasting](https://laravel.com/docs/broadcasting).

Laravel is accessible, powerful, and provides tools required for large, robust applications.

## Learning Laravel

Laravel has the most extensive and thorough [documentation](https://laravel.com/docs) and video tutorial library of all modern web application frameworks, making it a breeze to get started with the framework.

You may also try the [Laravel Bootcamp](https://bootcamp.laravel.com), where you will be guided through building a modern Laravel application from scratch.

If you don't feel like reading, [Laracasts](https://laracasts.com) can help. Laracasts contains thousands of video tutorials on a range of topics including Laravel, modern PHP, unit testing, and JavaScript. Boost your skills by digging into our comprehensive video library.

## Laravel Sponsors

We would like to extend our thanks to the following sponsors for funding Laravel development. If you are interested in becoming a sponsor, please visit the [Laravel Partners program](https://partners.laravel.com).

### Premium Partners

- **[Vehikl](https://vehikl.com/)**
- **[Tighten Co.](https://tighten.co)**
- **[WebReinvent](https://webreinvent.com/)**
- **[Kirschbaum Development Group](https://kirschbaumdevelopment.com)**
- **[64 Robots](https://64robots.com)**
- **[Curotec](https://www.curotec.com/services/technologies/laravel/)**
- **[Cyber-Duck](https://cyber-duck.co.uk)**
- **[DevSquad](https://devsquad.com/hire-laravel-developers)**
- **[Jump24](https://jump24.co.uk)**
- **[Redberry](https://redberry.international/laravel/)**
- **[Active Logic](https://activelogic.com)**
- **[byte5](https://byte5.de)**
- **[OP.GG](https://op.gg)**

## Contributing

Thank you for considering contributing to the Laravel framework! The contribution guide can be found in the [Laravel documentation](https://laravel.com/docs/contributions).

## Code of Conduct

In order to ensure that the Laravel community is welcoming to all, please review and abide by the [Code of Conduct](https://laravel.com/docs/contributions#code-of-conduct).

## Security Vulnerabilities

If you discover a security vulnerability within Laravel, please send an e-mail to Taylor Otwell via [taylor@laravel.com](mailto:taylor@laravel.com). All security vulnerabilities will be promptly addressed.

## License

The Laravel framework is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).
