# White-label QA script

Local only. You need your admin account and the demo data (or your own agency).

## Setup
1. In `backend/.env` set `MAIL_MAILER=log`. Optionally set `DEMO_PASSWORD=<something local>` and run `php artisan db:seed --class=WhiteLabelDemoSeeder` to get two demo agencies: `/a/northwind/login` (teal) and `/a/sunrise/login` (orange). Owners are `owner@northwind.test` and `owner@sunrise.test`, clients `client@...test`.
2. Start the API (`php artisan serve`) and the app (`npm run dev` in `frontend`).

## Steps
1. **Create an owner with a brand.** Sign in at `/login` as admin. Create an agency owner (Admin, Agency owners, New). The agency gets a portal address automatically (`/a/{slug}`); an admin can change it with `PATCH /api/admin/agencies/{id}/branding` (changing the slug breaks links already emailed). Note the slug.
2. **Read the invite.** Open `backend/storage/logs/laravel.log` and find the "Set your password" mail. Check: subject ends with the agency name, sender name is the agency, the button is the agency colour, the footer says the agency name, there is no "Laravel" or "ClientHub", and the link is `/a/{slug}/reset-password?...&invite=1`.
3. **Set the password and sign in.** Open the link, set a password, then sign in at `/a/{slug}/login`. Check the tab title, favicon (if set), colours, logo or name in the sidebar and sign-in page.
4. **Owner edits the brand.** As the owner open Branding, upload a logo, change the colour or display name, save, and confirm the app updates without a reload.
5. **Invite a client.** Clients, Create client. Read that invitation in the log (same checks as step 2), accept it, sign in on the same portal, and confirm the client sees the owner's brand and cannot open Branding.
6. **Work.** Request or add an employee, create a project and a ticket. Confirm nothing says "ClientHub", "administrator", "shared", or names another agency. Comments written by platform staff show as "{agency} Support".
7. **Isolation.** Open the second agency in a different browser profile. Neither can see the other's name, users, employees or projects. An owner signing in on the wrong portal (or on `/login`) gets the same "credentials are incorrect" message as a wrong password.
8. **Platform unchanged.** As admin at `/login` the product is still ClientHub, and the admin can see both agencies.
9. **Suspend.** In Admin, Agencies, suspend one agency. Its portal address now shows "Page not found", its users cannot sign in, and the public brand call (`GET /api/public/agencies/{slug}/branding`) returns 404 identical to an unknown slug.

## Automated checks
```powershell
cd backend; php artisan test
cd ../frontend; npm run lint; npm run build
```
Key backend tests: `AgencyPortalTest`, `BrandedMailTest`, `WhiteLabelLeakTest`.