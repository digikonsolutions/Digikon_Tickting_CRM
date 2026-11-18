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
- `src/branding/` contains agency white-labelling (see below).

## Agency branding

An agency owner customises the workspace at `/agency/branding`; everyone in that agency (owner, client, legacy member) sees it, and employees and admins keep the default ClientHub look.

- The API sends the branding in `user.branding` (`/auth/me` and login), so the app themes itself before the first render. `AuthContext` keeps it in state; `updateBranding(branding)` replaces it after an edit.
- `BrandingProvider` (`useBranding()`) turns it into CSS variables on `<html>` (`brandingVariables.js`, shade scale from `colorUtils.js`), sets `data-theme` and `data-sidebar`, swaps the favicon and `document.title`, and resets all of it on logout. The last branding is cached in `localStorage` (`clienthub.branding`) so a reload does not flash the default look; the server response replaces it. It re-reads the branding when the tab regains focus (at most every 30 seconds), so a client sees an owner's change without signing in again.
- Themes: `theme_mode` is `light`, `dark` or `system`. Dark neutrals live in `index.css` under `[data-theme="dark"]`; brand-coloured text on dark surfaces uses `--brand-text` (see the end of `src/branding/agency-branding.css`). Corner style sets `--radius-*`.
- Contrast is enforced in the UI and the API: the primary and accent colours need at least 4.5:1 against white. The stock teal `#0D9488` is 3.7:1, so the teal preset is `#0F766E`.
- SVG uploads are rejected (see `backend/README.md`); logos are png, jpg or webp and the favicon png or ico.
- The login page is not branded: the agency is unknown before sign-in. A possible follow-up is a per-agency login URL (for example `/a/{slug}/login`).
- The settings page uses `useBlocker` for its unsaved-changes guard, which needs a data router, so `main.jsx` uses `createBrowserRouter` with a single `*` route that renders `<App />`.
- Styles for the shell and the settings page are in `src/branding/agency-branding.css` (`ab-` prefix).

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
