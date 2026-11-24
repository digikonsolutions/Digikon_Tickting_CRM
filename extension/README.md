# ClientHub Tab History (Chrome extension)

Reports browser tab activity of **employees** to ClientHub so administrators can review it (read-only) in
**Admin → Browser history**. It is separate from the React app and uses Manifest V3.

Nothing is recorded or sent until the signed-in employee has accepted the work activity notice (version 2.0 or
later) that lists *Browser tab history*. The same text is in the invite email and the consent screen.

## Install (unpacked)

1. Open `chrome://extensions`, switch on **Developer mode**.
2. **Load unpacked** and choose this `extension/` folder.
3. Open the extension's **Settings** (toolbar icon → Settings) and enter your ClientHub API address, e.g.
   `https://clienthub.example.com/api` (or `http://localhost:8000/api` for development). Chrome then asks to
   allow access to that one address.
4. Sign in to ClientHub in the same browser profile and accept the notice. The popup then shows
   *Tracking and syncing*.

## What it records

| Event | When |
| --- | --- |
| `tab_opened` | A tab loads its first web page |
| `navigated` | The tab moves to another page (also single-page apps); carries the time spent on the previous page |
| `tab_activated` | The person switches to a different tab or window |
| `tab_closed` | A tab closes; carries the time spent on its last page |

Each record has: a random id (so re-sending never duplicates), a per-tab session id, the page address **without
anything after `?` or `#`**, the page title, an https favicon address, the time, and a duration when measurable.
Time only counts while the tab is in front **and** its browser window has focus.

Never recorded: page content, typed text, form fields, passwords, private (incognito) windows
(`"incognito": "not_allowed"`), and non-web pages (`chrome://`, `file://`, extension pages).

## Permissions

| Permission | Why |
| --- | --- |
| `tabs` | Read each tab's address and title (no content scripts, no page access) |
| `webNavigation` | Detect page changes, including single-page apps |
| `storage` | Keep the unsent queue and settings so a restart loses nothing |
| `alarms` | Sync once a minute while the browser is open |
| `cookies` | Read ClientHub's CSRF cookie to send a signed request. The extension uses your existing ClientHub sign-in; it stores no password or token |
| optional host access | Granted at run time for the **one** ClientHub address you enter |

## When it records

Only while the employee has a ticket in progress (or a project without tickets that they started). The extension checks in every 30 seconds; the server answers whether to record and for which ticket, and the toolbar icon shows **REC** while recording. The server also links every record to the ticket that was in progress at that moment (from the ticket's status history, not from anything the extension sends) and drops records from outside working time, so pressing **Send to review**, **Mark blocked** or finishing the work stops it. The employee's My work page says plainly when recording is on, for which ticket, and warns when a ticket is in progress but the extension is not connected.

For development, http://localhost and http://127.0.0.1 are host permissions in the manifest, so a local API works right after loading the extension; production addresses (https) are still requested at run time for the one address entered in Settings.

## Behaviour

- **Off switch:** the toolbar popup toggles tracking. While off, nothing new is recorded.
- **Offline:** records wait in a local queue (max 5,000) and are sent when the connection returns, in batches of 200.
- **No duplicates:** each record has a client-generated id and the server ignores ids it already has, so
  re-sending a batch (lost response, restart) is safe.
- **Restarts:** tab state is kept in `chrome.storage.local`. After a browser restart tabs get new ids, so tracking
  starts again from the open tabs without reporting them as newly opened.
- **Signed out / notice not accepted / notice replaced by a new version:** the queue is discarded, not sent.

## Tests

```bash
cd extension
npm test
```
