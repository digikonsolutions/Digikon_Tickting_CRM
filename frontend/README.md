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

Currently, two official plugins are available:

- [@vitejs/plugin-react](https://github.com/vitejs/vite-plugin-react/blob/main/packages/plugin-react) uses [Oxc](https://oxc.rs)
- [@vitejs/plugin-react-swc](https://github.com/vitejs/vite-plugin-react/blob/main/packages/plugin-react-swc) uses [SWC](https://swc.rs/)

## React Compiler

The React Compiler is not enabled on this template because of its impact on dev & build performances. To add it, see [this documentation](https://react.dev/learn/react-compiler/installation).

## Expanding the Oxlint configuration

If you are developing a production application, we recommend using TypeScript with type-aware lint rules enabled. Check out the [TS template](https://github.com/vitejs/vite/tree/main/packages/create-vite/template-react-ts) for information on how to integrate TypeScript and Oxlint's TypeScript related rules in your project.
