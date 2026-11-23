import { useState } from 'react'
import { brandingVariables, resolveTheme } from '../../../../branding/brandingVariables'

const YEAR = new Date().getFullYear()

function PreviewLogo({ url, name, collapsedMark }) {
  const [failed, setFailed] = useState(null)
  if (url && failed !== url) {
    return <img className="ab-pv-logo" src={url} alt="" onError={() => setFailed(url)} />
  }
  return (
    <>
      <span className="ab-pv-mark">{collapsedMark}</span>
      <span className="ab-pv-name">{name}</span>
    </>
  )
}

/**
 * A miniature of the app that reflects the unsaved draft: sidebar, header, welcome banner, button, badge,
 * stat card and a table row, plus a browser-tab mock. It is self-contained (its own classes and the
 * shared branding variables scoped to the frame), so it never leaks into or inherits the live page.
 */
export function BrandingPreview({ draft, logoUrl, faviconUrl, agencyName, systemDark }) {
  const [override, setOverride] = useState(null)
  const [view, setView] = useState('app')
  const theme = override ?? resolveTheme(draft, systemDark)
  const vars = brandingVariables(draft, { dark: theme === 'dark', complete: true })
  const name = draft.display_name?.trim() || agencyName || 'Your agency'
  const mark = name.charAt(0).toUpperCase()
  const welcome = draft.welcome_message?.trim() || 'Here is what needs your attention today.'
  const eyebrow = draft.tagline?.trim() || 'Agency client'
  const support = draft.support_email?.trim()

  return (
    <div className="ab-preview">
      <div className="ab-preview-toolbar">
        <span className="ab-preview-label" id="ab-preview-theme-label">Preview theme</span>
        <div className="ab-segment" role="group" aria-labelledby="ab-preview-theme-label">
          {['light', 'dark'].map((mode) => (
            <button
              key={mode}
              type="button"
              className={`ab-segment-btn${theme === mode ? ' is-active' : ''}`}
              aria-pressed={theme === mode}
              onClick={() => setOverride(mode)}
            >
              {mode === 'light' ? 'Light' : 'Dark'}
            </button>
          ))}
        </div>
      </div>

      <div className="ab-preview-toolbar">
        <span className="ab-preview-label" id="ab-preview-view-label">Preview page</span>
        <div className="ab-segment" role="group" aria-labelledby="ab-preview-view-label">
          {[['app', 'Workspace'], ['signin', 'Sign-in page']].map(([id, label]) => (
            <button key={id} type="button" className={`ab-segment-btn${view === id ? ' is-active' : ''}`} aria-pressed={view === id} onClick={() => setView(id)}>{label}</button>
          ))}
        </div>
      </div>

      <div className="ab-pv" data-pv-theme={theme} style={vars}>
        <div className="ab-pv-tabbar" aria-hidden="true">
          <span className="ab-pv-tab">
            {faviconUrl ? <img className="ab-pv-favicon" src={faviconUrl} alt="" /> : <span className="ab-pv-favicon ab-pv-favicon-default" />}
            <span className="ab-pv-tab-title">{name}</span>
          </span>
        </div>

        {view === 'signin' && (
          <div className="ab-pv-login" data-testid="signin-preview" aria-hidden="true">
            <div className="ab-pv-login-side">
              <div className="ab-pv-brand"><PreviewLogo url={logoUrl} name={name} collapsedMark={mark} /></div>
              {logoUrl && <strong>{name}</strong>}
              {draft.tagline?.trim() && <span>{draft.tagline.trim()}</span>}
            </div>
            <div className="ab-pv-login-card">
              <strong>Sign in to {name}</strong>
              <span className="ab-pv-field">Work email</span>
              <span className="ab-pv-field">Password</span>
              <span className="ab-pv-btn">Sign in</span>
              {support && <small>Need help? {support}</small>}
              <small>&copy; {YEAR} {name}</small>
            </div>
          </div>
        )}

        <div className={`ab-pv-frame${draft.sidebar_style === 'brand' ? ' is-brand-sidebar' : ''}`} hidden={view === 'signin'}>
          <aside className="ab-pv-sidebar" aria-hidden="true">
            <div className="ab-pv-brand"><PreviewLogo url={logoUrl} name={name} collapsedMark={mark} /></div>
            <span className="ab-pv-nav is-active"><i />Dashboard</span>
            <span className="ab-pv-nav"><i />Projects</span>
            <span className="ab-pv-nav"><i />Time summary</span>
          </aside>

          <div className="ab-pv-main" aria-hidden="true">
            <div className="ab-pv-header"><strong>Dashboard</strong><span className="ab-pv-avatar">A</span></div>

            <div className="ab-pv-banner">
              <small>{eyebrow}</small>
              <strong>Welcome back, Alex</strong>
              <span>{welcome}</span>
            </div>

            <div className="ab-pv-row">
              <div className="ab-pv-card">
                <small>Open projects</small>
                <strong>12</strong>
              </div>
              <div className="ab-pv-card ab-pv-actions">
                <span className="ab-pv-btn">New project</span>
                <span className="ab-pv-badge">In progress</span>
              </div>
            </div>

            <div className="ab-pv-table">
              <span>Website refresh</span>
              <span className="ab-pv-badge">Scheduled</span>
              <span className="ab-pv-link">View</span>
            </div>
          </div>
        </div>
      </div>
      <p className="ui-field-help ab-preview-note">Preview of unsaved changes. Nothing is applied to your workspace until you save.</p>
    </div>
  )
}
