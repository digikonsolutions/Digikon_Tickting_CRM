# Deploying the ClientHub extension to employees (force install)

Employees do **not** use "Load unpacked". The browser installs the extension from company policy: it appears by itself,
is pinned to the toolbar, cannot be removed or turned off by the person, and already knows the API address.

## 0. One-time decisions

| Question | Answer to use |
| --- | --- |
| Where does the extension come from? | **Chrome Web Store, unlisted** (works on any computer with the policy), or **self-hosted** `update.xml` (only on managed / domain-joined computers: Chrome refuses off-store installs otherwise on Windows). |
| Which browsers? | Chrome and Edge (both Chromium). Not Firefox or Safari. Tell employees to work in Chrome or Edge. |
| Which sites may be photographed? | `SNAPSHOT_DOMAINS` here **and** `SNAPSHOTS_ALLOWED_DOMAINS` on the server. They must match. |

## 1. Build the production extension

```powershell
cd extension
$env:API_BASE        = 'https://api.clienthub.example.com/api'
$env:SNAPSHOT_DOMAINS = 'gohighlevel.com,leadconnectorhq.com,msgsndr.com'
# Self-hosted only (skip both lines for the Web Store):
$env:KEY_FILE    = 'C:\secure\clienthub-extension-key.pem'
$env:UPDATE_BASE = 'https://updates.clienthub.example.com/extension'
node deploy/build.mjs
```

`dist/` now holds the production extension. Its manifest allows **only** your API host and the approved work sites, with
no localhost. Keep the private key out of git and safe: losing it changes the extension id.

## 2a. Chrome Web Store (recommended)

1. Zip the **contents** of `dist/` (`manifest.json` at the top of the zip).
2. Upload it at the Chrome Web Store developer dashboard as **Unlisted** (or Private to your Google Workspace domain).
   The store gives the extension an id (32 letters a to p). Use that id below.
3. For each new version: bump `version` in `manifest.json`, rebuild, upload. Browsers update by themselves.

## 2b. Self-hosted

1. Pack `dist/` into a `.crx` with the same key:
   `chrome.exe --pack-extension="<full path>\dist" --pack-extension-key="<key.pem>"`
2. Host `clienthub-extension.crx` and `dist/update.xml` at `UPDATE_BASE` over https.
3. The id was printed by the build. Use it below.

## 3. Push the policy to employee computers

Windows, one computer (administrator PowerShell):

```powershell
.\deploy\install-policy.ps1 -ExtensionId <id> -ApiBase https://api.clienthub.example.com/api
# self-hosted: add  -UpdateUrl https://updates.clienthub.example.com/extension/update.xml
```

Many computers: set the same values with Group Policy or your MDM (Intune and others):
- `ExtensionInstallForcelist` = `<id>;<update url>` (Web Store url is `https://clients2.google.com/service/update2/crx`)
- the extension's managed configuration = `dist/policy/managed-config.json` (`{"apiBase": "..."}`)
- or the single `ExtensionSettings` policy using `dist/policy/extension-settings.json` (also pins the icon).

macOS and Linux use the same policies as JSON files (Chrome: `/etc/opt/chrome/policies/managed/` on Linux,
a configuration profile on macOS).

Restart the browser, then open `chrome://policy` and check the extension is listed.

## 4. Check it works (do this once with one real employee)

1. The employee signs in to ClientHub in Chrome and accepts the work activity notice.
2. The toolbar icon shows **REC** while a ticket is in progress.
3. Open the extension popup: it says "Recording for ..." and nothing asks for an address.
4. Admin, Browser history shows the tabs. If snapshots are switched on, a picture appears after 5 to 15 minutes
   on a work site (the icon shows **CAM** for a moment).

## 5. Switching snapshots on (server)

Snapshots are **off** until you set this in the API `.env`:

```
SNAPSHOTS_ENABLED=true
SNAPSHOTS_DISK=s3          # a private bucket, not the web server's disk
SNAPSHOTS_ALLOWED_DOMAINS=gohighlevel.com,leadconnectorhq.com,msgsndr.com
SNAPSHOTS_MIN_MINUTES=5
SNAPSHOTS_MAX_MINUTES=15
SNAPSHOTS_RETENTION_DAYS=30
```

Turning it on moves the employee notice to version 3.0, which now lists snapshots. Every employee must accept it again
before the first picture is taken. Run the scheduler so `snapshots:prune` deletes old pictures.

## Known points to verify before launch

- Chrome's screenshot call needs permission for the page. This build grants it for the approved sites only; check on a
  real GoHighLevel page that pictures are taken. If Chrome refuses, the fallback is the `<all_urls>` permission (the
  extension and the server still only keep pictures from approved sites).
- Cookies: the extension signs in with the employee's own ClientHub session. Test one login on the production domains
  (the API and the app should share a parent domain, with `SESSION_DOMAIN` set).