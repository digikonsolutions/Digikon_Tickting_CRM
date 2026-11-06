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
| POST | `/api/auth/users` | Admin-only account invitation; role and agency assignment are validated |
| GET | `/api/admin/agencies` | Admin-only list of active agencies for agency-member assignment |
| GET | `/api/admin/users` | Admin-only paginated directory of all users; supports `search`, `page`, and `per_page` |
| GET | `/api/agency/users` | Agency-owner list of employee and client accounts in their agency |
| POST | `/api/agency/users` | Agency-owner invitation for an employee or client in their active agency |
| PATCH | `/api/agency/users/{user}` | Agency-owner update of a same-agency account, including activation |
| DELETE | `/api/agency/users/{user}` | Agency-owner permanent deletion of a same-agency account |
| GET | `/api/agency/directory` | Agency-member, employee, or client directory for their own agency |
| PATCH | `/api/agency/profile` | Agency-member, employee, or client may update their own display name |
| POST | `/api/auth/login` | Sign in |
| POST | `/api/auth/logout` | End the current session |
| GET | `/api/auth/me` | Return the authenticated user and role |
| POST | `/api/auth/forgot-password` | Request a reset link |
| POST | `/api/auth/reset-password` | Reset password with a time-limited token |

Roles are `admin`, `team_lead`, `employee`, `qa`, `agency_owner`, `agency_member` (legacy), `agency_employee`, and `agency_client`. Admin-created internal users must not have an agency. When an admin creates an agency owner, the request includes the agency name and the backend creates the agency and owner together; the database generates the agency ID. Admin-created agency users must be assigned to an existing agency. Agency owners can invite only agency employees or clients for their own active agency. Owners can edit those accounts, deactivate/reactivate them, and permanently delete them. The API scopes every account operation to the signed-in owner's agency; clients cannot choose a role or agency assignment themselves. Invited users receive a password setup email and set their own password through the existing password-reset flow. The `agency_id` and role returned by `/api/auth/me` are for frontend menus only; backend routes must enforce authorization. Protect future ticket routes with `auth` and `role:admin` (or a comma-separated role list), and enforce record ownership and tenant scoping with policies as well.

There is not yet an administrator user-update endpoint. If one is added, it must prevent an administrator from deactivating or demoting their own account and prevent deactivation or demotion of the last active administrator.

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

   Valid roles are `admin`, `team_lead`, `employee`, `qa`, `agency_owner`, `agency_member`, `agency_employee`, and `agency_client`. Agency users require an existing `agency_id`; internal roles must omit it. Agency owners invite employees or clients through `/api/agency/users`; the backend always assigns the owner's agency and accepts only those two roles. An unauthenticated request returns 401; a signed-in user without the required role returns 403.
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
