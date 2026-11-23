import { useEffect, useMemo, useRef, useState } from 'react'
import { authService } from '../../../auth/authService'
import { Alert, Button, FieldRow, FormSection, Input, Textarea } from '../../../components/ui'
import { Icon } from '../../../components/ui/Icon'
import { EmployeePicker } from './EmployeePicker'
import { LOGO_RULES, SLUG_PATTERN, contrastOnWhite, firstError, formatBytes, slugify } from './onboardingForm'

// Setup and access: pre-assigned employees, logo and brand colour, the invitation message and internal notes.
// Reusable: `setup` and `agency` are the two sections it edits (notes live on the agency object), each with its own
// onChange that receives the whole next object.
export function SetupFields({ setup, onSetup, agency, onAgency, errors, seats, logo, onLogo, logoNotice, onKnownEmployees }) {
  const fileRef = useRef(null)
  const preview = useMemo(() => (logo ? URL.createObjectURL(logo) : null), [logo])
  useEffect(() => () => { if (preview) URL.revokeObjectURL(preview) }, [preview])
  const ratio = contrastOnWhite(setup.brand_color)
  const colorProblem = firstError(errors, 'setup.brand_color')
  // The address the agency will get: what the admin typed, or what the server builds from the agency name.
  const autoSlug = slugify(agency.name)
  const slug = setup.slug.trim() || autoSlug
  const [availability, setAvailability] = useState({ slug: '', free: true })
  useEffect(() => {
    if (!slug || !SLUG_PATTERN.test(slug)) return undefined
    const guard = { active: true }
    // A hint only (an active portal answers 200, anything else 404); the server decides when the agency is created.
    const timer = window.setTimeout(() => {
      authService.getPublicBranding(slug).then(() => guard.active && setAvailability({ slug, free: false })).catch(() => guard.active && setAvailability({ slug, free: true }))
    }, 400)
    return () => { guard.active = false; window.clearTimeout(timer) }
  }, [slug])

  return (
    <>
      <FormSection title="Employees" description="Optional. Pre-assign free employees to this agency. They are held for the owner straight away.">
        <EmployeePicker
          selected={setup.employee_ids}
          onChange={(ids) => onSetup({ ...setup, employee_ids: ids })}
          onKnown={onKnownEmployees}
          seats={seats}
          error={firstError(errors, 'setup.employee_ids') ?? firstError(errors, 'setup.employee_ids.0')}
        />
      </FormSection>

      <FormSection title="Branding" description="Optional. The logo and colour theme the agency's workspace.">
        <div className="onb-brand">
          <div className="onb-logo">
            {preview ? <img src={preview} alt="Logo preview" /> : <span className="onb-logo-empty" aria-hidden="true"><Icon name="image" size={24} /></span>}
          </div>
          <div className="onb-brand-actions">
            <input
              ref={fileRef}
              type="file"
              hidden
              accept={LOGO_RULES.accept}
              onChange={(event) => { const file = event.target.files?.[0]; event.target.value = ''; if (file) onLogo(file) }}
            />
            <Button type="button" variant="secondary" size="sm" onClick={() => fileRef.current?.click()}>{logo ? 'Change logo' : 'Choose logo'}</Button>
            {logo && <Button type="button" variant="ghost" size="sm" onClick={() => onLogo(null)}>Remove</Button>}
            <p className="ui-field-help">{logo ? `${logo.name} · ${formatBytes(logo.size)}` : 'PNG, JPG or WEBP up to 2 MB.'}</p>
            {logoNotice && <p className="ui-field-error" role="alert">{logoNotice}</p>}
          </div>
        </div>
        <FieldRow>
          <div className="onb-color">
            <label className="ui-field-label" htmlFor="brand-color-text">Brand colour</label>
            <div className="onb-color-row">
              <input aria-label="Pick a brand colour" type="color" value={/^#[0-9a-fA-F]{6}$/.test(setup.brand_color) ? setup.brand_color : '#0f766e'} onChange={(event) => onSetup({ ...setup, brand_color: event.target.value.toUpperCase() })} />
              <input id="brand-color-text" className="ui-input" maxLength="7" placeholder="#0F766E" value={setup.brand_color} aria-invalid={Boolean(colorProblem)} onChange={(event) => onSetup({ ...setup, brand_color: event.target.value.toUpperCase() })} />
            </div>
            {colorProblem
              ? <p className="ui-field-error" role="alert">{colorProblem}</p>
              : ratio !== null && <p className="ui-field-help">Contrast on white: {ratio.toFixed(1)}:1 {ratio >= 4.5 ? '(good)' : '(too light)'}</p>}
          </div>
        </FieldRow>
        <FieldRow>
          <Input id="brand-display-name" label="Display name" maxLength={120} placeholder={agency.name || 'Shown instead of the agency name'} value={setup.display_name} onChange={(event) => onSetup({ ...setup, display_name: event.target.value })} helperText="What the agency's team sees as the product name." error={firstError(errors, 'setup.display_name')} />
          <Input id="brand-tagline" label="Tagline" maxLength={140} value={setup.tagline} onChange={(event) => onSetup({ ...setup, tagline: event.target.value })} error={firstError(errors, 'setup.tagline')} />
        </FieldRow>
        <FieldRow>
          <Input id="brand-support-email" label="Support email" type="email" maxLength={254} value={setup.support_email} onChange={(event) => onSetup({ ...setup, support_email: event.target.value })} helperText="Replies to the agency's emails go here." error={firstError(errors, 'setup.support_email')} />
          <div>
            <Input id="brand-slug" label="Portal address" maxLength={40} placeholder={autoSlug || 'agency-name'} value={setup.slug} onChange={(event) => onSetup({ ...setup, slug: event.target.value.toLowerCase().replace(/[^a-z0-9-]/g, '') })}
              helperText={slug ? `${window.location.origin}/a/${slug}` : 'Built from the agency name.'} error={firstError(errors, 'setup.slug')} />
            {slug && availability.slug === slug && !firstError(errors, 'setup.slug') && (
              <p className={`ui-field-help${availability.free ? '' : ' onb-slug-taken'}`} role="status">{availability.free ? 'Looks available.' : 'This address is already in use.'}</p>
            )}
          </div>
        </FieldRow>
      </FormSection>

      <FormSection title="Invitation" description="The owner gets an email with a link to set their password.">
        <label className="onb-check">
          <input type="checkbox" checked={setup.send_invite} onChange={(event) => onSetup({ ...setup, send_invite: event.target.checked })} />
          <span>Send the invitation email when I create the agency</span>
        </label>
        {!setup.send_invite && <Alert variant="info">No email will be sent. The owner can set a password with “Forgot password” on the sign-in page.</Alert>}
        <label className="onb-check">
          <input type="checkbox" checked={setup.welcome_enabled} onChange={(event) => onSetup({ ...setup, welcome_enabled: event.target.checked })} />
          <span>Add a personal welcome line to the email</span>
        </label>
        {setup.welcome_enabled && (
          <Textarea id="welcome-message" label="Welcome line" rows="3" maxLength="500" value={setup.welcome_message} helperText={`${setup.welcome_message.length} of 500 characters`} onChange={(event) => onSetup({ ...setup, welcome_message: event.target.value })} error={firstError(errors, 'setup.welcome_message')} />
        )}
      </FormSection>

      <FormSection title="Internal notes" description="Only your team sees these.">
        <Textarea id="agency-notes" label="Notes" rows="3" maxLength="5000" value={agency.internal_notes} onChange={(event) => onAgency({ ...agency, internal_notes: event.target.value })} error={firstError(errors, 'agency.internal_notes')} />
      </FormSection>
    </>
  )
}
