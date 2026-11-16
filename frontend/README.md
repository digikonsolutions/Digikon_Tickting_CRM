# React + Vite

This template provides a minimal setup to get React working in Vite with HMR and some Oxlint rules.

## Running with the Laravel API

The app uses the Laravel authentication API by default. Start the backend and frontend on the configured local origins:

```text
Backend:  http://localhost:8000
Frontend: http://localhost:5173
```

Set these values in `frontend/.env.local` if you use different URLs:

```env
VITE_API_URL=http://localhost:8000/api
VITE_AUTH_MODE=api
```

## Source structure

- `src/app/` contains route configuration; `src/App.jsx` composes app-wide providers.
- `src/features/` groups screens, feature-specific components, and services by domain (`admin`, `agency`, `auth`, `dashboard`, and `dev`).
- `src/components/ui/` contains shared UI primitives used across features.
- `src/auth/` contains authentication state, role definitions, and route guards.
- `src/hooks/` contains shared hooks.

Create the initial admin using the setup steps in `backend/README.md`. There is no public signup: ask an administrator to create your account. Set `VITE_AUTH_MODE=mock` only when you intentionally want the non-persistent UI demo.

## End-to-end tests (Playwright)

The specs in `e2e/` drive a real browser against real servers. Nothing is mocked.

```bash
cd frontend
npm install
npx playwright install chromium
npm run test:e2e
```

What `npm run test:e2e` does:

1. `playwright.config.js` writes `backend/.env.e2e` from `backend/.env.e2e.example`. It points at `backend/database/e2e.sqlite`, uses database sessions and logs mail to `storage/logs/laravel.log`. It then runs `php artisan migrate:fresh` with `APP_ENV=e2e`. It refuses to continue unless artisan reports the e2e SQLite database, so your normal `.env` database is never touched.
2. It starts `php artisan serve` on `http://localhost:8100` (with `APP_ENV=e2e`) and Vite on `http://localhost:5273`. These ports differ from the dev ports, so your own dev servers can keep running.
3. `e2e/global-setup.mjs` seeds one user per role through the real flows:
   - Admin: created with tinker, because `app:create-admin` asks for its password with a hidden interactive prompt.
   - Employee and agency owner: invited by the admin through `POST /api/auth/users`.
   - Client: invited by the owner through `POST /api/agency/users`.
   - Passwords: set through `POST /api/auth/reset-password`, using the links read from the mail log.
   - Team lead, QA and agency member: created with tinker, because the app has no creation path for these roles.
   - Seeding waits out the real reset-password throttle (3 a minute), so a run takes a couple of minutes.
4. `e2e/profile.spec.js` runs the profile and password scenarios for all seven roles, plus a two-browser test that checks a password change signs out the user's other session.

Artifacts land in `test-results/`; open a failure with `npx playwright show-trace test-results/<test>/trace.zip`.

Currently, two official plugins are available:

- [@vitejs/plugin-react](https://github.com/vitejs/vite-plugin-react/blob/main/packages/plugin-react) uses [Oxc](https://oxc.rs)
- [@vitejs/plugin-react-swc](https://github.com/vitejs/vite-plugin-react/blob/main/packages/plugin-react-swc) uses [SWC](https://swc.rs/)

## React Compiler

The React Compiler is not enabled on this template because of its impact on dev & build performances. To add it, see [this documentation](https://react.dev/learn/react-compiler/installation).

## Expanding the Oxlint configuration

If you are developing a production application, we recommend using TypeScript with type-aware lint rules enabled. Check out the [TS template](https://github.com/vitejs/vite/tree/main/packages/create-vite/template-react-ts) for information on how to integrate TypeScript and Oxlint's TypeScript related rules in your project.
