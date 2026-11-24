import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const api = vi.hoisted(() => ({ requestMoreHours: vi.fn(), cancelHoursRequest: vi.fn(), approveHoursRequest: vi.fn(), declineHoursRequest: vi.fn() }))
vi.mock('../../../auth/authService', () => ({ authService: api, getErrorMessage: (error) => error?.message ?? 'Something went wrong.' }))

const { HoursRequestPanel, validateHoursRequest } = await import('./HoursRequestPanel')

const ticket = { id: 9, key: 'NW-9', title: 'Build header', status: 'in_progress', estimated_hours: '10.00', hours_request: null }
const open = { ...ticket, hours_request: { id: 3, hours: '4.00', reason: 'The design grew.' } }

beforeEach(() => Object.values(api).forEach((fn) => fn.mockReset()))

describe('validation', () => {
  it('needs at least a quarter hour and a reason', () => {
    expect(validateHoursRequest({ hours: '', reason: '' })).toEqual({ hours: 'Ask for at least 0.25 hours.', reason: 'Tell the agency why the extra time is needed.' })
    expect(validateHoursRequest({ hours: '0.1', reason: 'because' }).hours).toMatch(/at least 0.25/)
    expect(validateHoursRequest({ hours: '200', reason: 'because' }).hours).toMatch(/180/)
    expect(validateHoursRequest({ hours: '2', reason: 'because' })).toEqual({})
  })
})

describe('employee', () => {
  it('validates, then sends the request and refreshes', async () => {
    const user = userEvent.setup()
    api.requestMoreHours.mockResolvedValue({})
    const onChanged = vi.fn()
    render(<HoursRequestPanel ticket={ticket} role="employee" onChanged={onChanged} />)

    await user.click(screen.getByRole('button', { name: 'Ask for more hours' }))
    expect(screen.getByText(/Your agency decides/)).toBeInTheDocument()
    await user.click(screen.getByRole('button', { name: 'Send request' }))
    expect(await screen.findByText('Ask for at least 0.25 hours.')).toBeInTheDocument()
    expect(api.requestMoreHours).not.toHaveBeenCalled()

    await user.type(screen.getByLabelText(/Extra hours needed/), '4')
    await user.type(screen.getByLabelText(/Why\?/), 'The design grew.')
    await user.click(screen.getByRole('button', { name: 'Send request' }))
    await waitFor(() => expect(api.requestMoreHours).toHaveBeenCalledWith(9, { hours: 4, reason: 'The design grew.' }))
    await waitFor(() => expect(onChanged).toHaveBeenCalled())
  })

  it('shows the open request and lets them withdraw it, with no approve buttons', async () => {
    const user = userEvent.setup()
    api.cancelHoursRequest.mockResolvedValue({})
    render(<HoursRequestPanel ticket={open} role="employee" onChanged={() => {}} />)

    expect(screen.getByText('You asked for 4 h more')).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /Approve/ })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Ask for more hours' })).not.toBeInTheDocument()
    await user.click(screen.getByRole('button', { name: 'Withdraw request' }))
    await waitFor(() => expect(api.cancelHoursRequest).toHaveBeenCalledWith(3))
  })

  it('offers nothing on closed work', () => {
    const { container } = render(<HoursRequestPanel ticket={{ ...ticket, status: 'done' }} role="employee" />)
    expect(container).toBeEmptyDOMElement()
  })
})

describe('agency', () => {
  it('approves from the ticket and reports the error when the project has no hours left', async () => {
    const user = userEvent.setup()
    const failure = Object.assign(new Error('Ticket hours exceed the project\'s hours. Remaining: 2.00 h.'), { status: 422, errors: { estimated_hours: ['x'] } })
    api.approveHoursRequest.mockRejectedValueOnce(failure).mockResolvedValueOnce({})
    const onChanged = vi.fn()
    render(<HoursRequestPanel ticket={open} role="agency_owner" employeeName="Emma Alpha" onChanged={onChanged} />)

    expect(screen.getByText('Emma Alpha asks for 4 h more')).toBeInTheDocument()
    expect(screen.getByText('The design grew.')).toBeInTheDocument()
    await user.click(screen.getByRole('button', { name: 'Approve and add the hours' }))
    expect(await screen.findByText(/Remaining: 2.00 h/)).toBeInTheDocument()
    expect(onChanged).not.toHaveBeenCalled()

    await user.click(screen.getByRole('button', { name: 'Approve and add the hours' }))
    await waitFor(() => expect(onChanged).toHaveBeenCalled())
    expect(api.approveHoursRequest).toHaveBeenCalledWith(3)
  })

  it('declines with a note', async () => {
    const user = userEvent.setup()
    api.declineHoursRequest.mockResolvedValue({})
    render(<HoursRequestPanel ticket={open} role="agency_client" onChanged={() => {}} />)

    await user.click(screen.getByRole('button', { name: 'Decline' }))
    await user.type(screen.getByLabelText(/Note for the employee/), 'Stay within the estimate.')
    await user.click(screen.getByRole('button', { name: 'Send answer' }))
    await waitFor(() => expect(api.declineHoursRequest).toHaveBeenCalledWith(3, 'Stay within the estimate.'))
  })

  it('shows clients and owners nothing when no one has asked', () => {
    const { container } = render(<HoursRequestPanel ticket={ticket} role="agency_owner" />)
    expect(container).toBeEmptyDOMElement()
    expect(screen.queryByRole('button', { name: 'Ask for more hours' })).not.toBeInTheDocument()
  })
})
