# Team queue and urgent requests: QA script

Run this against your **local** database with your own admin account. Use two browser profiles (or one normal window and one private window) so two people can be signed in at once. Name everything you create `QA-TEST-...` so it is easy to find and remove afterwards. Do not write any password in a note, ticket or file.

## Before you start

Three terminals, from `backend/` unless stated:

```powershell
php artisan serve
php artisan queue:work
php artisan schedule:work
```

and, from `frontend/`:

```powershell
npm run dev
```

`queue:work` delivers notifications and `schedule:work` runs the five-minute expiry commands. Without them steps 4, 5, 6 and 8 will look stuck. Run `php artisan migrate` once if you have not since pulling this branch.

Optional shortcut: `php artisan db:seed --class=QueueDemoSeeder` (needs `DEMO_PASSWORD` in your `.env` and `APP_ENV=local`) creates two demo agencies, owners, clients, four employees with queues and a pending request. The steps below create their own data instead, so you can skip it.

## 1. Admin sets the stage

1. Sign in as **admin**.
2. Employees → Create employee: `QA-TEST-Employee One` and `QA-TEST-Employee Two` (any email you control). Open each from the email invitation, or leave them and sign in later via a password reset.
3. Agency owners → Create agency owner: `QA-TEST-Owner`, agency `QA-TEST-Agency`. Activate the agency if the wizard left it as a draft. Add one agency client, `QA-TEST-Client`, from the owner's Clients page later.

**Expect:** the new people appear in their lists. Employees are not held by anyone yet.

## 2. Owner holds an employee and sees Now / Up next / Free from

1. In the second browser profile sign in as `QA-TEST-Owner` through the agency's portal address.
2. Employees → add `QA-TEST-Employee One`.
3. Projects → Create project three times for that employee: `QA-TEST-A` (12 h), `QA-TEST-B` (6 h), `QA-TEST-C` (6 h). Use start date today, due date three weeks out.
4. Open **Team queue**.

**Expect:** one card for Employee One. "Now working on" says nothing is in progress (or today's scheduled work). "Up next" lists A, B, C in that order with planned start and end dates, due dates and hours. "Free from" shows the first day with spare hours, the two capacity bars show today and this week. Each new project joined the end of the queue.

## 3. Reorder with preview, and a blocked reorder

1. On the card click **Reorder**. Move `QA-TEST-C` up to first with the arrow buttons.
2. Click **Preview impact**. **Expect:** a list of the projects whose dates would change, old range → new range; **Apply** is enabled.
3. Click **Apply**. **Expect:** the card shows C first with earlier dates; A and B moved later.
4. Edit `QA-TEST-A` (Projects → Edit) so its due date is two working days from today. Back on Team queue, Reorder, move A to last, **Preview impact**.
5. **Expect:** a red "This order would break due dates" message naming `QA-TEST-A` and how many days late. **Apply** stays disabled. Nothing changed.

## 4. Employee view and "Now"

1. In a third profile (or sign out in the second) sign in as `QA-TEST-Employee One` and accept the monitoring notice.
2. My work shows a **My queue** card with Now, Up next and Free from. **Expect:** no buttons to reorder, and no prices anywhere.
3. Open a project's ticket (the owner can create one from Projects → project → Add ticket), and **Start** it.
4. Reload the owner's Team queue. **Expect:** "Now working on" shows that ticket, who started when, and hours logged against the estimate. The project holding it can no longer be moved behind others (the reorder arrows stop it, the server refuses it).

Also check that the employee got an in-app notification "Your queue was reordered" after step 3 of the previous section.

## 5. Priority insert: request, quote, accept, charge

1. As `QA-TEST-Client` (create one under Clients first) open Team queue → **Request urgent help** on Employee One's card. Choose "Jump the queue", fill title `QA-TEST-Rush`, 3 hours, needed by in two days. **Expect:** the dialog says a quote comes first and nothing is charged. Submit.
2. As **admin**: Urgent requests (the sidebar shows a badge, the dashboard shows a strip). Open the request, **Quote**: price `250.00`, valid 48 hours. **Expect:** the owner gets an in-app notification and an email (in `storage/logs/laravel.log` when `MAIL_MAILER=log`), branded with the agency name.
3. As the **client**: Rush requests tab shows "Quote ready" with **no price and no accept button**.
4. As the **owner**: Team queue → Rush requests tab. **Expect:** the amount, the expiry and the queue impact. If other projects would pass their due date you must tick the confirmation before **Accept quote** enables.
5. Accept. **Expect:** the request becomes "Scheduled"; `QA-TEST-Rush` sits right behind anything already in progress (first otherwise) on the queue; the employee was told (no price in that message); and as admin, Urgent requests → **Charges** lists one `250.00 USD` unbilled charge for the agency.

## 6. Extra team member and temporary assignment

1. As the owner or client: Request urgent help → "Another team member", title `QA-TEST-Extra`, 6 hours, needed by tomorrow, skills `anything`.
2. As admin: **Quote**, choose `QA-TEST-Employee Two` from the list, price `100.00`.
3. As the owner (Rush requests tab): the quote shows **no employee name**. Accept.
4. **Expect:** Employees now lists Employee Two (a temporary assignment) with a `QA-TEST-Extra` project. Admin → Assignments shows it active.
5. Expiry: for a quick check change the assignment's `expires_at` to a minute ago (`php artisan tinker`: `App\Models\EmployeeAssignment::where('employee_id', <id>)->where('status','active')->update(['expires_at' => now()->subMinute()]);`), then wait up to five minutes with `schedule:work` running, or run `php artisan assignments:expire-temporary`. **Expect:** the assignment ends, the project is closed, the employee is free again, and the owner and employee are notified.
6. Rejection wording: raise another "Another team member" request and, as admin, **Reject** it with any reason. **Expect:** the agency only ever reads "No team member is available for this request."

## 7. A second agency sees nothing of the first

1. Create `QA-TEST-Agency Two` with its own owner and sign in as that owner in another browser profile.
2. **Expect:** its Team queue has no cards from the first agency, its Rush requests tab is empty, no page, notification or email mentions the first agency, the platform, administrators, other agencies or a shared pool. Pasting the first agency's queue or request URLs (`/api/agency/assignments/<id>/queue`, `/api/agency/rush-requests/<id>`) returns 404.
3. Employee Two, once free, appears for hiring only through your account manager flow; the agency never sees a pool list.

## 8. Background work: worker and scheduler

1. With `queue:work` running, each step above that says "notified" should produce a row in the bell menu within a few seconds. Stop the worker, trigger a quote, and notice nothing arrives until you start it again (jobs wait in the `jobs` table).
2. With `schedule:work` running: request a quote with a short validity (the quote form takes hours; set `1`, then in tinker set `quote_expires_at` to a minute ago). Within five minutes the request flips to "Quote expired", the owner can no longer accept it, and the admins are notified.
3. `php artisan schedule:list` shows `rush:expire-quotes` and `assignments:expire-temporary` every five minutes.

## Clean up

List what you created (all named `QA-TEST-`), then remove it from Employees, Agency owners and Agencies as admin. Removal is destructive, so check the list first.
