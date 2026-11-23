import { render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { ToastProvider } from '../../components/ui'

const catalog = {
  types: [
    {
      key: 'workflow', label: 'Workflow / automation',
      fields: [
        { key: 'trigger', label: 'What starts it', type: 'select', required: true, ticket: true, options: { form_submitted: 'A form is submitted', manual: 'Added manually' } },
        { key: 'actions', label: 'What it should do', type: 'checkbox', required: true, ticket: true, options: { send_email: 'Send an email', add_tag: 'Add or remove tags' } },
        { key: 'goal', label: 'Goal in a few words', type: 'text', max: 200, ticket: false },
        { key: 'reference_url', label: 'Example or reference link', type: 'url', ticket: false },
      ],
    },
    { key: 'other', label: 'Something else', fields: [] },
  ],
  employee_request: { roles: { ghl_builder: 'GHL builder', designer: 'Designer' }, max_hours_per_week: 36, max_duration_weeks: 52 },
}

const api = vi.hoisted(() => ({
  getServiceCatalog: vi.fn(),
  getCapacity: vi.fn(),
  createAgencyProject: vi.fn(),
  updateAgencyProject: vi.fn(),
  getAgencyProject: vi.fn(),
  uploadProjectAttachments: vi.fn(),
  listAgencyProjects: vi.fn(),
  listAgencyTickets: vi.fn(),
  listProjectTickets: vi.fn(),
  listMyAssignments: vi.fn(),
  createProjectTicket: vi.fn(),
  updateTicket: vi.fn(),
  sendAgencyRequest: vi.fn(),
}))
vi.mock('../../auth/authService', () => ({ authService: api }))
vi.mock('../../auth/useAuth', () => ({ useAuth: () => ({ user: { id: 1, role: 'agency_owner', name: 'Olive Owner' } }) }))

const { ServiceFields } = await import('./ServiceFields')
const { buildFromAnswers, cleanDetails, missingRequired, fieldsFor, typeOf } = await import('./serviceCatalog')
const { ProjectFormModal } = await import('../agency/components/ProjectFormModal')
const { TicketFormModal } = await import('../agency/components/TicketFormModal')
const { RequestModal } = await import('../agency/components/RequestModal')
const { EditWorkCard } = await import('../agency/components/EditWorkCard')

const wrap = (node) => render(<MemoryRouter><ToastProvider>{node}</ToastProvider></MemoryRouter>)
const assignments = [{ id: 3, employee: { id: 5, name: 'Emma Alpha' } }]
const workflow = typeOf(catalog, 'workflow')

beforeEach(() => {
  Object.values(api).forEach((fn) => fn.mockReset())
  api.getServiceCatalog.mockResolvedValue(catalog)
  api.getCapacity.mockResolvedValue({ total_available_hours: '40.00' })
  api.listAgencyProjects.mockResolvedValue({ projects: [{ id: 7, title: 'Lead follow-up', status: 'in_progress', estimated_hours: '12.00', due_date: '2026-12-20', assignment_id: 3 }] })
  api.listProjectTickets.mockResolvedValue({ tickets: [] })
  api.listMyAssignments.mockResolvedValue({ assignments })
})

describe('service catalog helpers', () => {
  it('asks fewer questions on a ticket than on a project', () => {
    expect(fieldsFor(workflow, 'project').map((field) => field.key)).toEqual(['trigger', 'actions', 'goal', 'reference_url'])
    expect(fieldsFor(workflow, 'ticket').map((field) => field.key)).toEqual(['trigger', 'actions'])
  })

  it('keeps only answered fields of the service, and null when nothing was answered', () => {
    expect(cleanDetails(workflow, { trigger: 'manual', actions: [], goal: '  ', stray: 'x', notes: ' Hi ' }, 'project')).toEqual({ trigger: 'manual', notes: 'Hi' })
    expect(cleanDetails(workflow, { goal: 'Too much for a ticket', trigger: 'manual' }, 'ticket')).toEqual({ trigger: 'manual' })
    expect(cleanDetails(workflow, { trigger: '' }, 'project')).toBeNull()
    expect(cleanDetails(null, { trigger: 'manual' }, 'project')).toBeNull()
  })

  it('lists the required answers still missing on a project, never on a ticket', () => {
    expect(missingRequired(workflow, { trigger: 'manual' }, 'project')).toEqual(['What it should do'])
    expect(missingRequired(workflow, {}, 'ticket')).toEqual([])
  })

  it('writes a title and description from the answers', () => {
    const { title, description } = buildFromAnswers(workflow, { trigger: 'form_submitted', actions: ['send_email', 'add_tag'], goal: 'Follow up fast', notes: 'Use our tone.' }, 'project')
    expect(title).toBe('Workflow / automation: A form is submitted')
    expect(description).toBe('Service: Workflow / automation\nWhat starts it: A form is submitted\nWhat it should do: Send an email, Add or remove tags\nGoal in a few words: Follow up fast\n\nUse our tone.')
    expect(buildFromAnswers(typeOf(catalog, 'other'), {}, 'project').title).toBe('Something else')
    expect(buildFromAnswers(workflow, { trigger: 'x'.repeat(300) }, 'project').title.length).toBeLessThanOrEqual(160)
  })
})

describe('ServiceFields', () => {
  it('draws each field by its type and reports answers upward', async () => {
    const user = userEvent.setup()
    const onChange = vi.fn()
    wrap(<ServiceFields catalog={catalog} scope="project" serviceType="workflow" details={{}} onChange={onChange} />)

    expect(screen.getByLabelText(/What starts it/)).toBeInTheDocument()
    expect(screen.getByRole('group', { name: /What it should do/ })).toBeInTheDocument()
    expect(screen.getByLabelText(/Example or reference link/)).toHaveAttribute('type', 'url')
    expect(screen.getByLabelText(/Anything else/)).toBeInTheDocument()

    await user.click(screen.getByRole('checkbox', { name: 'Send an email' }))
    expect(onChange).toHaveBeenLastCalledWith({ serviceType: 'workflow', details: { actions: ['send_email'] } })
    await user.selectOptions(screen.getByLabelText(/What starts it/), 'manual')
    expect(onChange).toHaveBeenLastCalledWith({ serviceType: 'workflow', details: { trigger: 'manual' } })
  })

  it('shows the lighter set on a ticket and shows server errors under the field', () => {
    wrap(<ServiceFields catalog={catalog} scope="ticket" serviceType="workflow" details={{}} onChange={() => {}} errors={(key) => (key === 'trigger' ? 'Pick one.' : undefined)} />)
    expect(screen.queryByLabelText(/Goal in a few words/)).not.toBeInTheDocument()
    expect(screen.getByText('Pick one.')).toBeInTheDocument()
  })

  it('clears the answers when the service changes', async () => {
    const user = userEvent.setup()
    const onChange = vi.fn()
    wrap(<ServiceFields catalog={catalog} scope="project" serviceType="workflow" details={{ trigger: 'manual' }} onChange={onChange} />)
    await user.selectOptions(screen.getByLabelText('Service'), 'other')
    expect(onChange).toHaveBeenLastCalledWith({ serviceType: 'other', details: {} })
  })
})

describe('project form', () => {
  it('writes the title and description from the answers, keeps edits, and sends the service with the project', async () => {
    const user = userEvent.setup()
    api.createAgencyProject.mockResolvedValue({ project: { id: 9 } })
    const onSaved = vi.fn()
    wrap(<ProjectFormModal assignments={assignments} assignmentsLoading={false} defaultAssignmentId={3} onClose={() => {}} onSaved={onSaved} />)

    const submit = screen.getByRole('button', { name: 'Create project' })
    expect(submit).toBeDisabled()
    // No schedule for an agency: no dates, and the estimate says our team confirms the schedule.
    expect(screen.queryByLabelText(/Start date/)).not.toBeInTheDocument()
    expect(screen.queryByLabelText(/Due date/)).not.toBeInTheDocument()
    expect(screen.queryByText('Schedule')).not.toBeInTheDocument()
    expect(screen.getByLabelText('Estimated hours (optional)')).toBeInTheDocument()
    expect(screen.getByText('Our team will confirm the final schedule.')).toBeInTheDocument()
    await user.selectOptions(await screen.findByLabelText('Service'), 'workflow')
    await user.selectOptions(screen.getByLabelText(/What starts it/), 'form_submitted')
    expect(screen.getByLabelText(/Project title/)).toHaveValue('Workflow / automation: A form is submitted')
    expect(submit).toBeDisabled() // the required "what it should do" is still open

    await user.click(screen.getByRole('checkbox', { name: 'Send an email' }))
    expect(screen.getByLabelText(/^Description/).value).toContain('What it should do: Send an email')

    // Once the person edits the title it is theirs: more answers no longer overwrite it.
    const title = screen.getByLabelText(/Project title/)
    await user.clear(title)
    await user.type(title, 'Lead follow-up')
    await user.click(screen.getByRole('checkbox', { name: 'Add or remove tags' }))
    expect(title).toHaveValue('Lead follow-up')
    expect(screen.getByLabelText(/^Description/).value).toContain('Add or remove tags')

    await waitFor(() => expect(submit).toBeEnabled())
    await user.click(submit)
    await waitFor(() => expect(api.createAgencyProject).toHaveBeenCalled())
    expect(api.createAgencyProject.mock.calls[0][0]).toMatchObject({
      title: 'Lead follow-up', assignment_id: 3, service_type: 'workflow',
      details: { trigger: 'form_submitted', actions: ['send_email', 'add_tag'] },
    })
    // The agency never sends dates, and the estimate is optional: nothing was typed, nothing is sent.
    const sent = api.createAgencyProject.mock.calls[0][0]
    expect(sent).not.toHaveProperty('start_date')
    expect(sent).not.toHaveProperty('due_date')
    expect(sent).not.toHaveProperty('estimated_hours')
    expect(onSaved).toHaveBeenCalled()
  })

  it('shows what the server says about a bad answer next to the field', async () => {
    const user = userEvent.setup()
    const { ApiError } = await vi.importActual('../../auth/authService').catch(() => ({}))
    const failure = Object.assign(new Error('The given data was invalid.'), { status: 422, errors: { 'details.reference_url': ['The details.reference url must be a valid URL.'] } })
    api.createAgencyProject.mockRejectedValue(ApiError ? new ApiError(failure.message, { status: 422, errors: failure.errors }) : failure)
    wrap(<ProjectFormModal assignments={assignments} assignmentsLoading={false} defaultAssignmentId={3} onClose={() => {}} onSaved={() => {}} />)

    await user.selectOptions(await screen.findByLabelText('Service'), 'workflow')
    await user.selectOptions(screen.getByLabelText(/What starts it/), 'manual')
    await user.click(screen.getByRole('checkbox', { name: 'Send an email' }))
    await waitFor(() => expect(screen.getByRole('button', { name: 'Create project' })).toBeEnabled())
    await user.click(screen.getByRole('button', { name: 'Create project' }))
    expect(await screen.findByText(/must be a valid URL/)).toBeInTheDocument()
  })

  it('edits an open project: shows its answers, and sends only what changed', async () => {
    const user = userEvent.setup()
    api.updateAgencyProject.mockResolvedValue({ project: { id: 7 } })
    const project = { id: 7, title: 'Lead follow-up', description: 'Old text', estimated_hours: '6.00', start_date: '2026-12-07', due_date: '2026-12-20', assignment_id: 3, service_type: 'workflow', details: { trigger: 'manual', actions: ['send_email'] } }
    wrap(<ProjectFormModal project={project} assignments={assignments} assignmentsLoading={false} onClose={() => {}} onSaved={() => {}} />)

    expect(await screen.findByLabelText('Service')).toHaveValue('workflow')
    expect(screen.getByLabelText(/What starts it/)).toHaveValue('manual')
    expect(screen.getByRole('checkbox', { name: 'Send an email' })).toBeChecked()
    expect(screen.getByLabelText(/Project title/)).toHaveValue('Lead follow-up') // editing never rewrites the text

    const hours = screen.getByLabelText(/Estimated hours/)
    await user.clear(hours)
    await user.type(hours, '10')
    await user.click(screen.getByRole('checkbox', { name: 'Add or remove tags' }))
    await user.click(screen.getByRole('button', { name: 'Save changes' }))

    await waitFor(() => expect(api.updateAgencyProject).toHaveBeenCalled())
    expect(api.updateAgencyProject).toHaveBeenCalledWith(7, {
      title: 'Lead follow-up', description: 'Old text', estimated_hours: 10, details: { trigger: 'manual', actions: ['send_email', 'add_tag'] },
    })
  })
})

describe('ticket form', () => {
  it('is optional, asks the lighter questions and sends the service with the ticket', async () => {
    const user = userEvent.setup()
    api.createProjectTicket.mockResolvedValue({ ticket: { id: 1 } })
    wrap(<TicketFormModal projectId={7} onClose={() => {}} onSaved={() => {}} />)

    await user.selectOptions(await screen.findByLabelText('Service'), 'workflow')
    expect(screen.queryByLabelText(/Goal in a few words/)).not.toBeInTheDocument()
    await user.selectOptions(screen.getByLabelText(/What starts it/), 'manual')
    expect(screen.getByLabelText(/^Title/)).toHaveValue('Workflow / automation: Added manually')
    await user.type(screen.getByLabelText(/Estimated hours/), '2')
    await user.click(screen.getByRole('button', { name: 'Create ticket' }))

    await waitFor(() => expect(api.createProjectTicket).toHaveBeenCalled())
    expect(api.createProjectTicket).toHaveBeenCalledWith('7', expect.objectContaining({ service_type: 'workflow', details: { trigger: 'manual' }, estimated_hours: 2 }))
  })

  it('still creates a plain ticket with no service', async () => {
    const user = userEvent.setup()
    api.createProjectTicket.mockResolvedValue({ ticket: { id: 2 } })
    wrap(<TicketFormModal projectId={7} onClose={() => {}} onSaved={() => {}} />)

    await user.type(await screen.findByLabelText(/^Title/), 'Fix the footer')
    await user.type(screen.getByLabelText(/^Description/), 'It is crooked.')
    await user.type(screen.getByLabelText(/Estimated hours/), '1')
    await user.click(screen.getByRole('button', { name: 'Create ticket' }))

    await waitFor(() => expect(api.createProjectTicket).toHaveBeenCalled())
    expect(api.createProjectTicket.mock.calls[0][1]).toMatchObject({ title: 'Fix the footer', service_type: null, details: null })
  })
})

describe('request an employee', () => {
  it('asks for a role, hours per week and duration, and sends them as answers', async () => {
    const user = userEvent.setup()
    api.sendAgencyRequest.mockResolvedValue({})
    wrap(<RequestModal type="employee" onClose={() => {}} />)

    const send = screen.getByRole('button', { name: 'Send request' })
    expect(send).toBeDisabled()
    await user.selectOptions(await screen.findByLabelText(/What kind of team member/), 'designer')
    expect(send).toBeEnabled()

    const hours = screen.getByLabelText(/Hours per week/)
    await user.clear(hours)
    await user.type(hours, '40')
    expect(await screen.findByText('Enter 1 to 36.')).toBeInTheDocument()
    expect(send).toBeDisabled()
    await user.clear(hours)
    await user.type(hours, '12')
    await user.clear(screen.getByLabelText(/For how many weeks/))
    await user.type(screen.getByLabelText(/For how many weeks/), '6')
    await user.click(send)

    await waitFor(() => expect(api.sendAgencyRequest).toHaveBeenCalled())
    expect(api.sendAgencyRequest).toHaveBeenCalledWith(expect.objectContaining({ type: 'employee', details: { role: 'designer', hours_per_week: 12, duration_weeks: 6 } }))
  })

  it('leaves the other requests as they were: notes only, no answers', async () => {
    const user = userEvent.setup()
    api.sendAgencyRequest.mockResolvedValue({})
    wrap(<RequestModal type="more_hours" onClose={() => {}} />)
    expect(screen.queryByLabelText(/What kind of team member/)).not.toBeInTheDocument()
    await user.click(screen.getByRole('button', { name: 'Send request' }))
    await waitFor(() => expect(api.sendAgencyRequest).toHaveBeenCalledWith(expect.objectContaining({ type: 'more_hours', details: null })))
  })
})

describe('dashboard edit card', () => {
  const tickets = [
    { id: 1, key: 'NW-1', title: 'Open ticket', status: 'in_progress', project_title: 'Lead follow-up', work_item_id: 7, estimated_hours: '2.00', priority: 'normal', due_date: '2026-12-10', service_type: null, details: null },
    { id: 2, key: 'NW-2', title: 'Finished ticket', status: 'done', project_title: 'Lead follow-up', work_item_id: 7 },
    { id: 3, key: 'NW-3', title: 'Dropped ticket', status: 'cancelled', project_title: 'Lead follow-up', work_item_id: 7 },
  ]

  it('offers Edit on open projects and tickets only, and opens the edit forms', async () => {
    const user = userEvent.setup()
    api.listAgencyProjects.mockResolvedValue({ projects: [
      { id: 7, title: 'Lead follow-up', status: 'in_progress', estimated_hours: '12.00', due_date: '2026-12-20' },
      { id: 8, title: 'Old project', status: 'completed', estimated_hours: '4.00', due_date: '2026-11-01' },
      { id: 9, title: 'Dropped project', status: 'cancelled', estimated_hours: '4.00', due_date: '2026-11-01' },
    ] })
    api.listAgencyTickets.mockResolvedValue({ tickets })
    api.getAgencyProject.mockResolvedValue({ project: { id: 7, title: 'Lead follow-up', description: 'D', estimated_hours: '12.00', start_date: '2026-12-07', due_date: '2026-12-20', assignment_id: 3, service_type: 'workflow', details: { trigger: 'manual', actions: ['send_email'] } } })
    wrap(<EditWorkCard isOwner />)

    expect(await screen.findByRole('button', { name: 'Edit project Lead follow-up' })).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Edit ticket Open ticket' })).toBeInTheDocument()
    for (const closed of ['Old project', 'Dropped project', 'Finished ticket', 'Dropped ticket']) {
      expect(screen.queryByText(closed)).not.toBeInTheDocument()
      expect(screen.queryByRole('button', { name: new RegExp(`Edit .*${closed}`) })).not.toBeInTheDocument()
    }

    await user.click(screen.getByRole('button', { name: 'Edit project Lead follow-up' }))
    const dialog = await screen.findByRole('dialog')
    expect(within(dialog).getByText('Edit project')).toBeInTheDocument()
    expect(within(dialog).getByLabelText('Service')).toHaveValue('workflow')
  })

  it('gives clients ticket editing only', async () => {
    api.listAgencyTickets.mockResolvedValue({ tickets })
    wrap(<EditWorkCard isOwner={false} />)
    expect(await screen.findByRole('button', { name: 'Edit ticket Open ticket' })).toBeInTheDocument()
    expect(screen.queryByText('Projects')).not.toBeInTheDocument()
    expect(api.listAgencyProjects).not.toHaveBeenCalled()
  })
})
