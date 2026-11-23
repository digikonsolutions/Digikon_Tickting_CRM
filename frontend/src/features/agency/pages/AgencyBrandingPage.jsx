import { useCallback, useEffect, useMemo, useState, useSyncExternalStore } from 'react'
import { useBlocker } from 'react-router-dom'
import { useAuth } from '../../../auth/useAuth'
import { authService } from '../../../auth/authService'
import { useAsync } from '../../../hooks/useAsync'
import { Alert, Button, Card, EmptyState, Icon, Input, Modal, PageHeader, Skeleton, Textarea, useToast } from '../../../components/ui'
import { ApiErrorMessage } from '../../auth/components/ApiErrorMessage'
import { BrandingPreview } from '../components/branding/BrandingPreview'
import { ColorField } from '../components/branding/ColorField'
import { contrastOnWhite } from '../components/branding/colorPresets'
import { ImageUploadField } from '../components/branding/ImageUploadField'

const TEXT_FIELDS = ['display_name', 'tagline', 'welcome_message', 'support_email', 'email_from_name']
const COLOR_FIELDS = ['primary_color', 'accent_color']
const ENUM_FIELDS = ['theme_mode', 'sidebar_style', 'corner_style']
const FIELDS = [...TEXT_FIELDS, ...COLOR_FIELDS, ...ENUM_FIELDS]

function toDraft(branding) {
  return {
    display_name: branding?.display_name ?? '',
    tagline: branding?.tagline ?? '',
    welcome_message: branding?.welcome_message ?? '',
    support_email: branding?.support_email ?? '',
    email_from_name: branding?.email_from_name ?? '',
    primary_color: branding?.primary_color ?? '',
    accent_color: branding?.accent_color ?? '',
    theme_mode: branding?.theme_mode ?? 'light',
    sidebar_style: branding?.sidebar_style ?? 'light',
    corner_style: branding?.corner_style ?? 'rounded',
  }
}

function sameDraft(a, b) {
  return FIELDS.every((field) => String(a[field] ?? '').trim() === String(b[field] ?? '').trim())
}

function useMediaQuery(query) {
  const subscribe = useCallback((notify) => {
    const list = window.matchMedia(query)
    list.addEventListener('change', notify)
    return () => list.removeEventListener('change', notify)
  }, [query])
  return useSyncExternalStore(subscribe, () => window.matchMedia(query).matches, () => false)
}

const FAVICON_TYPES = ['image/png', 'image/x-icon', 'image/vnd.microsoft.icon']

function ChoiceGroup({ legend, name, value, onChange, options, helperText }) {
  return (
    <fieldset className="ab-choice-group">
      <legend className="ui-field-label">{legend}</legend>
      <div className="ab-choices">
        {options.map((option) => (
          <label key={option.value} className={`ab-choice${value === option.value ? ' is-selected' : ''}`}>
            <input type="radio" name={name} value={option.value} checked={value === option.value} onChange={() => onChange(option.value)} />
            <span className="ab-choice-title">{option.label}</span>
            <span className="ab-choice-desc">{option.description}</span>
          </label>
        ))}
      </div>
      {helperText && <p className="ui-field-help">{helperText}</p>}
    </fieldset>
  )
}

function BrandingForm({ initial }) {
  const { user, updateBranding } = useAuth()
  const { showToast } = useToast()
  const [saved, setSaved] = useState(initial)
  const [draft, setDraft] = useState(() => toDraft(initial))
  const [resetKey, setResetKey] = useState(0)
  const [isSaving, setIsSaving] = useState(false)
  const [error, setError] = useState(null)
  const [confirmReset, setConfirmReset] = useState(false)
  const [isResetting, setIsResetting] = useState(false)
  const [previewOpen, setPreviewOpen] = useState(true)
  const isDesktop = useMediaQuery('(min-width: 1100px)')
  const systemDark = useMediaQuery('(prefers-color-scheme: dark)')

  const dirty = useMemo(() => !sameDraft(draft, toDraft(saved)), [draft, saved])
  const primaryContrast = contrastOnWhite(draft.primary_color)
  const accentContrast = contrastOnWhite(draft.accent_color)
  const lowContrast = primaryContrast?.passes === false || accentContrast?.passes === false

  // Leaving with unsaved changes: block in-app navigation (confirm in a modal) and tab close/refresh.
  const blocker = useBlocker(dirty)
  useEffect(() => {
    if (!dirty) return undefined
    const warn = (event) => { event.preventDefault(); event.returnValue = '' }
    window.addEventListener('beforeunload', warn)
    return () => window.removeEventListener('beforeunload', warn)
  }, [dirty])

  function set(field, value) {
    setDraft((current) => ({ ...current, [field]: value }))
    setError(null)
  }

  function applyServer(branding) {
    setSaved(branding)
    updateBranding(branding)
  }

  async function save(event) {
    event.preventDefault()
    if (!dirty || lowContrast || isSaving) return
    setIsSaving(true)
    setError(null)
    try {
      const { branding } = await authService.updateAgencyBranding(Object.fromEntries(FIELDS.map((field) => [field, draft[field].trim?.() ?? draft[field]])))
      applyServer(branding)
      setDraft(toDraft(branding))
      setResetKey((key) => key + 1)
      showToast('Branding saved. Your team will see it the next time they open the app.')
    } catch (reason) {
      setError(reason)
    } finally {
      setIsSaving(false)
    }
  }

  function discard() {
    setDraft(toDraft(saved))
    setResetKey((key) => key + 1)
    setError(null)
  }

  async function resetAll() {
    setIsResetting(true)
    setError(null)
    try {
      const { branding } = await authService.resetAgencyBranding()
      applyServer(branding)
      setDraft(toDraft(branding))
      setResetKey((key) => key + 1)
      setConfirmReset(false)
      showToast('Branding reset to the defaults.')
    } catch (reason) {
      setConfirmReset(false)
      setError(reason)
    } finally {
      setIsResetting(false)
    }
  }

  const upload = (kind) => async (file, options) => {
    const { branding } = await authService.uploadBrandingAsset(kind, file, options)
    applyServer(branding)
  }
  const removeAsset = (kind) => async () => {
    const { branding } = await authService.deleteBrandingAsset(kind)
    applyServer(branding)
  }

  async function copyAddress() {
    try {
      await navigator.clipboard.writeText(`${window.location.origin}/a/${saved.slug}`)
      showToast('Sign-in link copied.')
    } catch {
      showToast('Could not copy automatically. Select the link and copy it.', 'error')
    }
  }

  const fieldError = (field) => error?.status === 422 ? error.errors?.[field]?.[0] : undefined
  const unshown = error && (error.status !== 422 || Object.keys(error.errors ?? {}).some((field) => !FIELDS.includes(field))) ? error : null
  const showPreview = isDesktop || previewOpen
  const agencyName = saved.agency_name ?? user.agency_name

  return (
    <>
      <div className="ab-layout">
        <aside className="ab-preview-panel" aria-label="Live preview">
          {!isDesktop && (
            <button type="button" className="ab-preview-toggle" aria-expanded={previewOpen} aria-controls="ab-preview-body" onClick={() => setPreviewOpen((open) => !open)}>
              Live preview <span aria-hidden="true">{previewOpen ? '▴' : '▾'}</span>
            </button>
          )}
          {isDesktop && <h2 className="ab-preview-heading">Live preview</h2>}
          <div id="ab-preview-body" hidden={!showPreview}>
            <BrandingPreview
              draft={draft}
              logoUrl={saved.logo_url}
              faviconUrl={saved.favicon_url}
              agencyName={agencyName}
              systemDark={systemDark}
            />
          </div>
        </aside>

        <form className="ab-settings" onSubmit={save} noValidate aria-label="Agency branding settings">
          <Card title="Identity" description="How your agency is named across the workspace.">
            <div className="ab-fields">
              <Input
                label="Display name"
                value={draft.display_name}
                maxLength={120}
                onChange={(event) => set('display_name', event.target.value)}
                error={fieldError('display_name')}
                helperText={`Shown in the sidebar, sign-in page and browser tab. Leave empty to use ${agencyName ?? 'the default'}. (${draft.display_name.length}/120)`}
              />
              <Input
                label="Tagline"
                value={draft.tagline}
                maxLength={160}
                onChange={(event) => set('tagline', event.target.value)}
                error={fieldError('tagline')}
                helperText={`Shown above the welcome heading on the dashboard. (${draft.tagline.length}/160)`}
              />
              <Textarea
                label="Welcome message"
                rows={3}
                value={draft.welcome_message}
                maxLength={240}
                onChange={(event) => set('welcome_message', event.target.value)}
                error={fieldError('welcome_message')}
                helperText={`A short note for your team and clients on the dashboard banner. (${draft.welcome_message.length}/240)`}
              />
            </div>
          </Card>

          <Card title="Sign-in and email" description="Where your team and clients sign in, and how your emails look.">
            <div className="ab-fields">
              {saved.slug && (
                <div className="ui-field">
                  <span className="ui-field-label">Sign-in address</span>
                  <p className="ab-address">
                    <code>{`${window.location.origin}/a/${saved.slug}`}</code>
                    <Button type="button" variant="secondary" size="sm" onClick={copyAddress}>Copy link</Button>
                  </p>
                  <p className="ui-field-help">Share this with your team and clients. Contact support to change the address; links in earlier emails would stop working.</p>
                </div>
              )}
              <Input
                label="Support email"
                type="email"
                value={draft.support_email}
                maxLength={254}
                onChange={(event) => set('support_email', event.target.value)}
                error={fieldError('support_email')}
                helperText="Shown on your sign-in page, and replies to your emails go here."
              />
              <Input
                label="Email sender name"
                value={draft.email_from_name}
                maxLength={120}
                onChange={(event) => set('email_from_name', event.target.value)}
                error={fieldError('email_from_name')}
                helperText={`The name on emails your team and clients receive. Leave empty to use ${draft.display_name.trim() || agencyName || 'your display name'}.`}
              />
            </div>
          </Card>

          <Card title="Logos and favicon" description="Uploads are saved right away. Recommended: square PNG, at least 256×256.">
            <div className="ab-fields">
              {!saved.logo_url && !saved.logo_collapsed_url && !saved.favicon_url && (
                <EmptyState icon={<Icon name="image" size={22} />} title="No logo yet">
                  Your name and the default mark are shown until you upload one.
                </EmptyState>
              )}
              <ImageUploadField
                label="Logo"
                helperText="Shown in the sidebar. Wide or square. PNG, JPG or WEBP, up to 2 MB, at least 64×64."
                currentUrl={saved.logo_url}
                onUpload={upload('logo')}
                onRemove={removeAsset('logo')}
              />
              <ImageUploadField
                label="Collapsed logo"
                square
                helperText="A square mark for the collapsed sidebar. PNG, JPG or WEBP, up to 2 MB."
                currentUrl={saved.logo_collapsed_url}
                onUpload={upload('logo-collapsed')}
                onRemove={removeAsset('logo-collapsed')}
              />
              <ImageUploadField
                label="Favicon"
                square
                accept={FAVICON_TYPES}
                acceptLabel="PNG or ICO"
                maxBytes={512 * 1024}
                minSize={16}
                maxSize={1024}
                helperText="The browser tab icon. PNG or ICO, up to 512 KB, at least 16×16 (32×32 or larger is best)."
                currentUrl={saved.favicon_url}
                onUpload={upload('favicon')}
                onRemove={removeAsset('favicon')}
              />
              <p className="ui-field-help">SVG files are not accepted because they can carry scripts.</p>
            </div>
          </Card>

          <Card title="Colors" description="Buttons use white text, so the primary color must reach 4.5:1 contrast against white.">
            <div className="ab-fields">
              <ColorField
                key={`primary-${resetKey}`}
                label="Primary color"
                value={draft.primary_color}
                onChange={(value) => set('primary_color', value)}
                error={fieldError('primary_color')}
                helperText="Buttons, links, focus rings and the welcome banner."
                showShades
              />
              <ColorField
                key={`accent-${resetKey}`}
                label="Accent color (optional)"
                value={draft.accent_color}
                onChange={(value) => set('accent_color', value)}
                error={fieldError('accent_color')}
                helperText="Highlights the active menu item. Must also read as text on white."
                fallbackPicker="#4338CA"
              />
            </div>
          </Card>

          <Card title="Appearance" description="Shape, sidebar and theme.">
            <div className="ab-fields">
              <ChoiceGroup
                legend="Corner style"
                name="corner_style"
                value={draft.corner_style}
                onChange={(value) => set('corner_style', value)}
                options={[
                  { value: 'soft', label: 'Soft', description: 'Slightly rounded' },
                  { value: 'rounded', label: 'Rounded', description: 'The default' },
                  { value: 'sharp', label: 'Sharp', description: 'Square corners' },
                ]}
              />
              <ChoiceGroup
                legend="Sidebar"
                name="sidebar_style"
                value={draft.sidebar_style}
                onChange={(value) => set('sidebar_style', value)}
                options={[
                  { value: 'light', label: 'Light', description: 'White sidebar' },
                  { value: 'brand', label: 'Brand', description: 'Tinted with your primary color' },
                ]}
                helperText={draft.sidebar_style === 'brand' && !draft.primary_color ? 'Pick a primary color to tint the sidebar.' : undefined}
              />
              <ChoiceGroup
                legend="Theme"
                name="theme_mode"
                value={draft.theme_mode}
                onChange={(value) => set('theme_mode', value)}
                options={[
                  { value: 'light', label: 'Light', description: 'Always light' },
                  { value: 'dark', label: 'Dark', description: 'Always dark' },
                  { value: 'system', label: 'System', description: "Follows each person's device" },
                ]}
              />
            </div>
          </Card>

          <Card title="Reset" description="Remove all customization and delete the uploaded files.">
            <Button type="button" variant="secondary" onClick={() => setConfirmReset(true)}>Reset to defaults</Button>
          </Card>

          <ApiErrorMessage error={unshown} />
          {lowContrast && <Alert variant="warning">A color is too light for white text. Pick a darker shade to save.</Alert>}

          {dirty && (
            <div className="ab-savebar" role="region" aria-label="Unsaved changes">
              <p>You have unsaved changes.</p>
              <div className="ab-savebar-actions">
                <Button type="button" variant="secondary" disabled={isSaving} onClick={discard}>Discard</Button>
                <Button type="submit" loading={isSaving} loadingText="Saving…" disabled={lowContrast}>Save changes</Button>
              </div>
            </div>
          )}
        </form>
      </div>

      <Modal
        open={confirmReset}
        onClose={() => !isResetting && setConfirmReset(false)}
        title="Reset branding to defaults?"
        footer={(
          <>
            <Button type="button" variant="secondary" disabled={isResetting} onClick={() => setConfirmReset(false)}>Cancel</Button>
            <Button type="button" variant="danger" loading={isResetting} loadingText="Resetting…" onClick={resetAll}>Reset branding</Button>
          </>
        )}
      >
        <p>Your logos, favicon, colors, names and messages will be removed for everyone in your agency. This cannot be undone.</p>
      </Modal>

      <Modal
        open={blocker.state === 'blocked'}
        onClose={() => blocker.reset?.()}
        title="Leave without saving?"
        footer={(
          <>
            <Button type="button" variant="secondary" onClick={() => blocker.reset?.()}>Keep editing</Button>
            <Button type="button" variant="danger" onClick={() => blocker.proceed?.()}>Discard and leave</Button>
          </>
        )}
      >
        <p>Your branding changes have not been saved and will be lost.</p>
      </Modal>
    </>
  )
}

export function AgencyBrandingPage() {
  const load = useCallback(() => authService.getAgencyBranding().then((response) => response.branding), [])
  const branding = useAsync(load)

  return (
    <section className="page ab-page">
      <PageHeader
        eyebrow="Agency"
        title="Branding"
        subtitle="Make the workspace yours. Everyone in your agency sees these changes after they save."
      />
      {branding.isLoading && !branding.data ? (
        <div className="ab-layout" aria-busy="true" aria-label="Loading branding">
          <div className="ab-preview-panel"><Skeleton className="ab-skeleton-preview" /></div>
          <div className="ab-settings"><Skeleton /><Skeleton /><Skeleton /></div>
        </div>
      ) : branding.error ? (
        <Alert variant="error">
          {branding.error.message} <button type="button" className="inline-link" onClick={branding.reload}>Try again</button>
        </Alert>
      ) : branding.data ? (
        <BrandingForm initial={branding.data} />
      ) : null}
    </section>
  )
}
