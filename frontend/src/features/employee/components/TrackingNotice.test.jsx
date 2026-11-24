import { render, screen } from '@testing-library/react'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const api = vi.hoisted(() => ({ getBrowserTrackingStatus: vi.fn() }))
vi.mock('../../../auth/authService', () => ({ authService: api }))
const { TrackingNotice } = await import('./TrackingNotice')

const work = { ticket_id: 3, ticket_key: 'NW-3', title: 'Build header', work_item_id: 1, project_title: 'Website' }

beforeEach(() => api.getBrowserTrackingStatus.mockReset())

describe('TrackingNotice', () => {
  it('says what is being recorded, for which ticket, and for how long', async () => {
    api.getBrowserTrackingStatus.mockResolvedValue({ consented: true, extension_connected: true, active_work: work, recording: true, retention_days: 365 })
    render(<TrackingNotice />)
    expect(await screen.findByText('Browser activity is being recorded for NW-3 Build header')).toBeInTheDocument()
    expect(screen.getByText(/Kept for 365 days/)).toBeInTheDocument()
    expect(screen.getByText(/Never page content, typing or private windows/)).toBeInTheDocument()
  })

  it('warns when work is in progress but the extension is not connected', async () => {
    api.getBrowserTrackingStatus.mockResolvedValue({ consented: true, extension_connected: false, active_work: work, recording: false, retention_days: 365 })
    render(<TrackingNotice />)
    expect(await screen.findByText('NW-3 Build header is in progress, but browser activity is not being recorded')).toBeInTheDocument()
    expect(screen.getByText(/extension is not connected in this browser/)).toBeInTheDocument()
  })

  it('shows nothing when no work is in progress', async () => {
    api.getBrowserTrackingStatus.mockResolvedValue({ consented: true, extension_connected: true, active_work: null, recording: false, retention_days: 365 })
    const { container } = render(<TrackingNotice />)
    await vi.waitFor(() => expect(api.getBrowserTrackingStatus).toHaveBeenCalled())
    expect(container).toBeEmptyDOMElement()
  })

})
