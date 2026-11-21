import { Alert, Badge, Button, Card } from '../../../components/ui'
import { formatDate } from '../../../lib/format'
import {
  BILLING_CYCLES, COMPANY_SIZES, CONTACT_METHODS, contractSummary, formatBytes, labelOf, reviewWarnings,
} from './onboardingForm'

const Row = ({ label, children }) => (children === undefined || children === null || children === ''
  ? null
  : <div><dt>{label}</dt><dd>{children}</dd></div>)

function ReviewCard({ title, step, onEdit, children, empty }) {
  return (
    <Card>
      <div className="onb-review-card">
        <div className="onb-review-head">
          <h3>{title}</h3>
          <Button type="button" variant="ghost" size="sm" aria-label={`Edit ${title.toLowerCase()}`} onClick={() => onEdit(step)}>Edit</Button>
        </div>
        {empty ? <p className="adm-muted">{empty}</p> : <dl className="onb-review-facts">{children}</dl>}
      </div>
    </Card>
  )
}

// The last step: everything in summary cards with Edit links, and the warnings before anything is created.
export function ReviewStep({ values, plans, employees, logo, document: contractDocument, onEdit }) {
  const { owner, agency, contract, setup } = values
  const warnings = reviewWarnings(values, 'create')
  const plan = (plans ?? []).find((item) => String(item.id) === String(contract.plan_id))
  const chosen = setup.employee_ids.map((id) => employees[id]?.name ?? `Employee #${id}`)
  const limits = contractSummary(contract)

  return (
    <div className="onb-review">
      {warnings.length > 0 && (
        <Alert variant="warning" title="Before you create">
          <ul className="onb-warnings">{warnings.map((warning) => <li key={warning}>{warning}</li>)}</ul>
        </Alert>
      )}
      <div className="onb-review-grid">
        <ReviewCard title="Owner" step="owner" onEdit={onEdit}>
          <Row label="Name">{owner.name}</Row>
          <Row label="Email">{owner.email}</Row>
          <Row label="Phone">{owner.phone}</Row>
          <Row label="WhatsApp">{owner.whatsapp}</Row>
          <Row label="Job title">{owner.job_title}</Row>
          <Row label="Preferred contact">{owner.preferred_contact_method && labelOf(CONTACT_METHODS, owner.preferred_contact_method)}</Row>
          <Row label="Time zone">{owner.timezone}</Row>
        </ReviewCard>

        <ReviewCard title="Agency" step="agency" onEdit={onEdit}>
          <Row label="Name">{agency.name}</Row>
          <Row label="Brand name">{agency.brand_name}</Row>
          <Row label="Legal name">{agency.legal_name}</Row>
          <Row label="Website">{agency.website}</Row>
          <Row label="Industry">{agency.industry}</Row>
          <Row label="Size">{agency.company_size && `${labelOf(COMPANY_SIZES.map((size) => ({ value: size, label: size })), agency.company_size)} people`}</Row>
          <Row label="Location">{[agency.city, agency.country].filter(Boolean).join(', ')}</Row>
          <Row label="Billing contact">{[agency.billing_contact_name, agency.billing_email].filter(Boolean).join(' · ')}</Row>
          <Row label="Tax number">{agency.tax_number && '•••• (stored encrypted)'}</Row>
        </ReviewCard>

        <ReviewCard title="Plan & contract" step="contract" onEdit={onEdit} empty={!values.contractEnabled ? 'No contract added. The agency will have no limits until you add one.' : null}>
          <Row label="Plan">{plan?.name ?? 'Custom'}</Row>
          <Row label="Status"><Badge variant={contract.status === 'trial' ? 'warning' : 'success'}>{contract.status === 'trial' ? 'Trial' : 'Active'}</Badge></Row>
          <Row label="Limits">{limits.join(' · ')}</Row>
          <Row label="Price">{contract.price !== '' && `${contract.currency} ${Number(contract.price).toLocaleString()} ${labelOf(BILLING_CYCLES, contract.billing_cycle).toLowerCase()}`}</Row>
          <Row label="Dates">{[contract.start_date && formatDate(contract.start_date), contract.end_date && formatDate(contract.end_date)].filter(Boolean).join(' to ')}</Row>
          <Row label="Trial ends">{contract.status === 'trial' && contract.trial_end_date && formatDate(contract.trial_end_date)}</Row>
          <Row label="Renewal">{contract.auto_renew ? 'Renews automatically' : 'Does not renew automatically'}</Row>
          <Row label="Payment terms">{contract.payment_terms_days !== '' && `Net ${contract.payment_terms_days}`}</Row>
          <Row label="Discount">{contract.discount_percent !== '' && `${contract.discount_percent}%`}</Row>
          <Row label="Contract file">{contractDocument && `${contractDocument.name} (${formatBytes(contractDocument.size)})`}</Row>
        </ReviewCard>

        <ReviewCard title="Setup & access" step="setup" onEdit={onEdit}>
          <Row label="Employees">{chosen.length > 0 ? chosen.join(', ') : 'None yet'}</Row>
          <Row label="Logo">{logo ? `${logo.name} (${formatBytes(logo.size)})` : 'None'}</Row>
          <Row label="Brand colour">{setup.brand_color && <span className="onb-swatch"><span style={{ background: setup.brand_color }} aria-hidden="true" />{setup.brand_color}</span>}</Row>
          <Row label="Invitation">{setup.send_invite ? 'Sent when you create' : 'Not sent'}</Row>
          <Row label="Welcome line">{setup.welcome_enabled && setup.welcome_message.trim()}</Row>
          <Row label="Internal notes">{agency.internal_notes}</Row>
        </ReviewCard>
      </div>
    </div>
  )
}
