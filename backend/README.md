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

Set the frontend `.env.local` to:

```env
VITE_API_URL=http://localhost:8000/api
VITE_AUTH_MODE=api
```

Run `php artisan migrate` before starting the application. In local development, password-reset links are written to `storage/logs/laravel.log` when `MAIL_MAILER=log`. Configure a production mail provider, HTTPS, secure cookies, a restricted `FRONTEND_URL`, and appropriate session domain settings before deployment.

There is no default admin account or public signup route. Bootstrap the first administrator from trusted local tooling with `php artisan app:create-admin "Administrator" admin@example.com`; the command prompts for a strong password without echoing it. For local-only seeding, set `LOCAL_ADMIN_NAME`, `LOCAL_ADMIN_EMAIL`, and `LOCAL_ADMIN_PASSWORD` in the local environment before running `php artisan db:seed`. Do not set these values in production.

Available routes:

| Method | Path | Purpose |
|---|---|---|
| GET | `/api/auth/csrf-cookie` | Establish the CSRF/session cookies |
| PATCH | `/api/auth/profile` | Update the authenticated user's profile |
| POST | `/api/auth/users` | Admin-only account invitation; role and agency assignment are validated |
| GET | `/api/admin/users` | Admin-only paginated directory of all roles (optionally filtered by `role`); supports `search`, `page`, and `per_page` |
| PATCH | `/api/admin/users/{user}` | Admin update of employee or agency-owner details and activation |
| DELETE | `/api/admin/users/{user}` | Admin permanent deletion of an employee or agency owner |
| PATCH | `/api/admin/agencies/{agency}` | Admin activates or suspends an agency; suspension revokes its users' sessions |
| GET | `/api/agency/users` | Agency-owner list of agency accounts; employee accounts are read-only |
| POST | `/api/agency/users` | Agency owners invite clients into their active agency |
| PATCH | `/api/agency/users/{user}` | Owners update same-agency client details and activation |
| DELETE | `/api/agency/users/{user}` | Owners delete same-agency clients |
| GET | `/api/agency/directory` | Agency-owner, legacy agency-member, and agency-client directory including shared admin-created employees |
| GET | `/api/agency/team` | Agency-owner/client roster of shared admin-created employees |
| PATCH | `/api/agency/profile` | Agency-member or client may update their own display name |
| GET | `/api/agency/employees/available` | Agency client/owner list of active same-agency and shared employees with no active holder |
| POST | `/api/agency/assignments` | Hold an available employee exclusively for the current client or owner |
| GET | `/api/agency/assignments` | List the caller's own active assignments (holder only; `scope` is rejected with 422) |
| GET | `/api/agency/assignments/{assignment}/usage` | Read usage of an assignment the caller holds for a requested `?week=YYYY-MM-DD` (404 otherwise) |
| DELETE | `/api/agency/assignments/{assignment}` | Holder ends their own assignment (owners and clients alike; 404 for anyone else's) |
| GET | `/api/agency/projects` | Agency clients and owners page through projects in their agency. Query: `page`, `per_page` (default 25, max 100), `status` (`open`, `scheduled`, `in_progress`, `completed`, `cancelled`; `open` = scheduled + in progress), `assignment_id`, `search` (title, client or employee name). Returns `projects`, `pagination` and an agency-wide `summary` of counts by status that ignores filters and page |
| GET | `/api/agency/capacity?assignment_id=&from=&to=` | Agency client/owner read per-day availability of an employee they hold (same rules as the scheduler) for a date range of up to 366 days |
| GET | `/api/agency/projects/{workItem}` | Agency clients and owners read one project in their agency (404 for other agencies) |
| POST | `/api/agency/projects` | Agency client/owner create a project using their active `assignment_id` |
| PATCH | `/api/agency/projects/{workItem}/cancel` | Agency clients and owners cancel any open project in their agency |
| GET | `/api/agency/projects/{workItem}/tickets` | Agency clients and owners read tickets for projects in their agency |
| POST | `/api/agency/projects/{workItem}/tickets` | Agency clients and owners create tickets and plan them against project capacity |
| PATCH | `/api/agency/projects/{workItem}/tickets/reorder` | Reorders a project's tickets and recalculates their dates |
| PATCH | `/api/agency/tickets/{ticket}` | Agency clients and owners update tickets on projects in their agency |
| DELETE | `/api/agency/tickets/{ticket}` | Agency owner/client cancels a ticket and replans remaining tickets |
| GET | `/api/work-items/mine/tickets` | Employee reads tickets for their assigned projects |
| PATCH | `/api/tickets/{ticket}/status` | Assigned employee advances a ticket through its status flow |
| POST | `/api/tickets/{ticket}/comments` | Authorized project participants or admins add a ticket comment |
| GET | `/api/admin/tickets` | Admin read-only paginated ticket directory |
| GET | `/api/work-items/mine` | Employee list of assigned projects |
| GET | `/api/admin/projects` | Admin read-only, searchable paginated view of all projects and schedules |
| GET | `/api/admin/assignments` | Admin read-only paginated employee-assignment directory |
| DELETE | `/api/admin/assignments/{assignment}` | Admin ends an assignment and releases its future work allocations |
| POST | `/api/auth/login` | Sign in |
| POST | `/api/auth/logout` | End the current session |
| GET | `/api/auth/me` | Return the authenticated user and role |
| POST | `/api/auth/forgot-password` | Request a reset link |
| POST | `/api/auth/reset-password` | Reset password with a time-limited token |

Roles are `admin`, `team_lead`, `employee`, `qa`, `agency_owner`, `agency_member` (legacy), and `agency_client`. Only admins can create or manage employee accounts. Employee accounts may be linked to an agency or remain shared with a null agency ID. Admins can also create and manage agency owners. When an admin creates an agency owner, the request includes the agency name and the backend creates the agency and owner together; the database generates the agency ID. Agency owners can manage clients in their own agency, but employee accounts are view-only. Agency owners and clients can hold multiple employees, but each employee can have only one active holder at a time. Assignments are private to their holder: owners and clients can only list, read usage or capacity for, and end the assignments they hold themselves (there is no agency-wide assignment view, and owners cannot release a client's employee); admins see and end every assignment through `/api/admin/assignments`, and projects stay visible to the whole agency. Shared employees become unavailable to all other agencies while held. Agency roles and tenant IDs are assigned by the backend, never trusted from client input. Invited users receive a password setup email and set their own password through the existing password-reset flow. The `agency_id` and role returned by `/api/auth/me` are for frontend menus only; backend routes enforce authorization and tenant scoping. Agency-facing routes (team, directory, profile, agency users, employee holds, capacity and projects) run the `agency.active` middleware after `auth` and `role:`, which returns 403 when the caller has no agency or their agency is suspended. Ticket routes intentionally keep their own check and answer 404, and employee routes still allow a null agency.

Agency clients and owners create projects against an active employee assignment they hold. A project is limited to 180 estimated hours, and each client may have at most three open projects. The scheduler allocates earliest-first Monday through Saturday, skips Sundays and the `non_working_dates` configured in `config/scheduling.php`, and caps each employee at six hours per workday (36 hours per week). Today's capacity is available only before `today_cutoff_hour` (default 12) in the configured application timezone. Capacity checks and reservations are enforced transactionally; cancelled projects and ended assignments release allocations dated today or later. `GET /api/agency/capacity` returns the per-day availability the scheduler would use for a held employee (Sundays, non-working dates, today after the cutoff hour, the 6 h daily cap and bookings from every agency), so the UI never has to repeat that maths. Project payloads include `tickets_total`, `tickets_done`, `tickets_open` (cancelled tickets excluded) and `next_ticket` (the first non-done, non-cancelled ticket by position, or `null`). Clients and owners can see project details, employee selections, and statuses within their own agency; clients may create projects only against assignments they hold. Employees do not set project status directly: a project moves to in-progress when its first ticket starts and to completed when all of its non-cancelled tickets are done; admins can view all projects but cannot edit them.

Tickets are hour slices of a project's existing schedule and inherit its employee assignment. Agency clients and owners may create, edit, reorder, and cancel tickets for any project in their own agency while its assignment is active and the project remains open. Tickets are planned earliest-first within the project's allocated dates, and their combined hours cannot exceed the project's estimated hours. The assigned employee advances tickets through `todo` → `in_progress` → `in_review` → `done`; ticket progress updates the project status. Project cancellation cancels its tickets and removes all ticket allocations.

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
