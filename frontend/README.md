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

## Support module

`src/features/support` holds the Digikon ticket screens; the shared pieces are in `components/ui` (`LifecycleStatusBadge`, `LifecyclePriorityBadge`, `TypeBadge`, `SavedViewTabs`, `SlaIndicator`).

- Agency: `/agency/support` (tabs with counts, filters kept in the address, cards on a phone), `/agency/support/new` (one page: type cards, the questions each type needs, impact questions that suggest a priority, similar open tickets, files and links, a live password and key warning, a draft kept in `localStorage`) and `/agency/support/:ref` (status and what happens next, public conversation, files, activity, resolution with confirm or reopen, satisfaction). The old project tickets stay at `/agency/tickets`.
- Staff (admin and employees): `/staff/tickets` (KPI cards, saved views with live counts, filters, SLA column), `/staff/tickets/:ref` (valid next steps only, each opening a dialog that asks for exactly what the step needs; Public reply and Internal note composer that always starts on Public; internal items have amber stripes and a lock; Files, Time, scope and billing, convert to project or change request) and `/staff/reports` (KPIs with the spec's targets and a table under every bar chart).
- Agency users never receive internal data: the API sends separate payloads, and these screens render only what they are given.
- The queue has a Table and a Board layout. A card on the board moves to a column the server says it may move to (drag, or the Move to menu on the card), and the move opens the same dialog as the ticket page.
- The ticket page also has QA and bug, Time, approvals, related tickets, and scope and billing; /staff/settings is for administrators.
- The queue also has bulk actions (assign, priority, status), personal saved views, and a workload page at `/staff/workload`. Administrators set agency packages on `/staff/settings`; agencies manage recurring tickets at `/agency/support/recurring`.
- Quotes: staff send them from the ticket page (delivery manager or finance); the agency sees the quote on its ticket and the account owner accepts or declines it.
## GoHighLevel brief and ticket work orders

`src/features/workorder` holds everything for the detailed project brief and the ticket work order, so agency, admin and employee views share it.

- `briefSchema.js` describes the twelve brief sections as data. `BriefForm` (accordion, collapsed by default, with a live "Brief N% complete"), `BriefView` (read-only, empty sections hidden, copy buttons on ids and links) and the completeness estimate all read it. Adding a field means one line there and one rule in `backend/app/Support/GhlBrief.php`.
- `useProjectBrief` and `useTicketDetails` hold the form state; `briefToPayload` and `orderToPayload` (in `useProjectBrief.js` and `workOrder.js`) are the only places that map state to the API. Nested 422 keys such as `ghl_brief.client.email` or `work_order.ghl.refs.0.url` are matched to inputs by path, and a section with an error opens itself.
- `features/agency/components/TicketForm.jsx` is the create and edit dialog for tickets on a project page: core fields (title, hours, priority, work type) on top, then the collapsible work-order sections, with the project brief shown read-only for the work type.
- The ticket page has a "Work order" tab. The assigned employee gets a tickable checklist, a blocked note and a delivery form there and in the My Work ticket drawer; agency users can tick steps; admin is read-only.
- Shared inputs added to `components/ui`: `ChipInput`, `MultiSelect`, `RepeatableList`, `ChecklistEditor`, `UrlInput`, `CopyButton`, `ProgressBadge`.
- The tickets-page dialog (`TicketFormModal`) does not have the work-order sections yet.

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
