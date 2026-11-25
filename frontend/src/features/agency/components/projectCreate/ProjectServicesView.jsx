import { useCallback } from 'react'
import { authService } from '../../../../auth/authService'
import { useAsync } from '../../../../hooks/useAsync'
import { Badge, Card } from '../../../../components/ui'
import { LinkOrText } from '../../../workorder/BriefView'
import { fieldsFor } from '../../../services/serviceCatalog'

// The services a project asks for, with the answers in words (option labels, not codes). Read-only; used on the agency
// project page and the admin project page. Renders nothing for a project without services.
export function ProjectServicesView({ services }) {
  const catalog = useAsync(useCallback(() => authService.getServiceCatalog(), [])).data
  if (!services?.length) return null
  const show = (field, value) => {
    const label = (item) => field.options?.[item] ?? item
    if (Array.isArray(value)) return value.map(label).join(', ')
    return field.type === 'select' ? label(value) : value
  }
  return (
    <Card title="Services requested" description={`${services.length} service${services.length === 1 ? '' : 's'} in this project`}>
      <ul className="pc-services">
        {services.map((service) => {
          const type = (catalog?.types ?? []).find((item) => item.key === service.key)
          const rows = (type ? fieldsFor(type, 'project') : []).filter((field) => service.details?.[field.key] != null && service.details[field.key] !== '')
          return (
            <li key={service.id}>
              <header><strong>{service.label}</strong>{service.estimated_hours && <Badge variant="info">{Number(service.estimated_hours)} h estimate</Badge>}</header>
              {service.details === null ? <p className="ui-field-help">{service.answers} answer{service.answers === 1 ? '' : 's'}</p> : (
                <dl>
                  {rows.map((field) => <div key={field.key}><dt>{field.label}</dt><dd>{field.type === 'url' ? <LinkOrText value={service.details[field.key]} /> : show(field, service.details[field.key])}</dd></div>)}
                  {service.details.notes && <div><dt>Notes</dt><dd>{service.details.notes}</dd></div>}
                </dl>
              )}
            </li>
          )
        })}
      </ul>
    </Card>
  )
}
