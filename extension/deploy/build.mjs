// Builds the production extension into dist/ for company-managed (force) installation.
//
//   API_BASE=https://api.clienthub.example.com/api \
//   SNAPSHOT_DOMAINS=gohighlevel.com,leadconnectorhq.com,msgsndr.com \
//   KEY_FILE=./key.pem UPDATE_BASE=https://updates.clienthub.example.com/extension \
//   node deploy/build.mjs
//
// - API_BASE (required): the https API address. It becomes the only API host the extension may talk to.
// - SNAPSHOT_DOMAINS: the sites snapshots may be taken on. Must match SNAPSHOTS_ALLOWED_DOMAINS on the server.
// - KEY_FILE: a private key (PEM) that fixes the extension id, so the install policy can name it. Create one with
//   `openssl genrsa -out key.pem 2048` and KEEP IT SECRET (it is not in git).
// - UPDATE_BASE: where you host update.xml and the .crx, for self-hosted installs. Leave out for the Chrome Web Store.
import { createHash, createPublicKey } from 'node:crypto'
import { cpSync, existsSync, mkdirSync, readFileSync, rmSync, writeFileSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { fileURLToPath } from 'node:url'

const root = join(dirname(fileURLToPath(import.meta.url)), '..')
const dist = join(root, 'dist')
const fail = (message) => { console.error(`Build stopped: ${message}`); process.exit(1) }

const apiBase = (process.env.API_BASE ?? '').trim().replace(/\/+$/, '')
let api
try { api = new URL(apiBase) } catch { fail('set API_BASE to the https API address, for example https://api.clienthub.example.com/api') }
if (api.protocol !== 'https:') fail('API_BASE must be https://')
const domains = (process.env.SNAPSHOT_DOMAINS ?? 'gohighlevel.com,leadconnectorhq.com,msgsndr.com').split(',').map((item) => item.trim().toLowerCase()).filter(Boolean)
if (domains.some((domain) => !/^[a-z0-9.-]+\.[a-z]{2,}$/.test(domain))) fail('SNAPSHOT_DOMAINS must be plain domains such as gohighlevel.com')

rmSync(dist, { recursive: true, force: true })
mkdirSync(dist, { recursive: true })
for (const entry of ['src', 'popup', 'options']) cpSync(join(root, entry), join(dist, entry), { recursive: true })
mkdirSync(join(dist, 'deploy'), { recursive: true })
cpSync(join(root, 'deploy', 'managed_schema.json'), join(dist, 'deploy', 'managed_schema.json'))

const manifest = JSON.parse(readFileSync(join(root, 'manifest.json'), 'utf8'))
// Least privilege: no localhost, only the API and the approved work sites.
manifest.host_permissions = [`${api.origin}/*`, ...domains.map((domain) => `https://*.${domain}/*`)]
delete manifest.optional_host_permissions

let extensionId = null
if (process.env.KEY_FILE) {
  if (!existsSync(process.env.KEY_FILE)) fail(`KEY_FILE not found: ${process.env.KEY_FILE}`)
  const der = createPublicKey(readFileSync(process.env.KEY_FILE)).export({ type: 'spki', format: 'der' })
  manifest.key = der.toString('base64')
  // Chrome's extension id: the first 128 bits of SHA-256 of the public key, written with the letters a to p.
  extensionId = [...createHash('sha256').update(der).digest().subarray(0, 16)].map((byte) => String.fromCharCode(97 + (byte >> 4)) + String.fromCharCode(97 + (byte & 15))).join('')
}
const updateBase = (process.env.UPDATE_BASE ?? '').trim().replace(/\/+$/, '')
if (updateBase) manifest.update_url = `${updateBase}/update.xml`
writeFileSync(join(dist, 'manifest.json'), `${JSON.stringify(manifest, null, 2)}\n`)

mkdirSync(join(dist, 'policy'), { recursive: true })
const managed = { apiBase }
writeFileSync(join(dist, 'policy', 'managed-config.json'), `${JSON.stringify(managed, null, 2)}\n`)
if (extensionId) {
  const updateUrl = updateBase ? `${updateBase}/update.xml` : 'https://clients2.google.com/service/update2/crx'
  writeFileSync(join(dist, 'policy', 'extension-settings.json'), `${JSON.stringify({ [extensionId]: { installation_mode: 'force_installed', update_url: updateUrl, toolbar_pin: 'force_pinned' } }, null, 2)}\n`)
  if (updateBase) {
    writeFileSync(join(dist, 'update.xml'), `<?xml version='1.0' encoding='UTF-8'?>\n<gupdate xmlns='http://www.google.com/update2/response' protocol='2.0'>\n  <app appid='${extensionId}'>\n    <updatecheck codebase='${updateBase}/clienthub-extension.crx' version='${manifest.version}' />\n  </app>\n</gupdate>\n`)
  }
}
console.log(`Built dist/ (version ${manifest.version})`)
console.log(`  API:      ${api.origin}`)
console.log(`  Snapshot sites: ${domains.join(', ')}`)
console.log(extensionId ? `  Extension id: ${extensionId}` : '  No KEY_FILE: the extension id is chosen by the Chrome Web Store when you upload.')