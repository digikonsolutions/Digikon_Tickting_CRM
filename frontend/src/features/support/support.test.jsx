import { fireEvent, render, screen } from '@testing-library/react'
import { useState } from 'react'
import { describe, expect, it, vi } from 'vitest'
import { LifecyclePriorityBadge, LifecycleStatusBadge, SavedViewTabs, SlaIndicator } from '../../components/ui'

describe('support badges', () => {
  it('every status and priority is words as well as colour', () => {
    render(<><LifecycleStatusBadge status="waiting_agency" /><LifecycleStatusBadge status="rejected" /><LifecyclePriorityBadge priority="urgent" /><LifecyclePriorityBadge priority="low" /></>)
    for (const text of ['Waiting for agency', 'Rejected', 'P1 Critical', 'P4 Low']) expect(screen.getByText(text)).toBeTruthy()
  })
})

describe('SlaIndicator', () => {
  it('says what the clock is doing in plain words', () => {
    const { rerender } = render(<SlaIndicator kind="response" clock={{ state: 'on_track', minutes_left: 135 }} />)
    expect(screen.getByText('Response due in 2h 15m')).toBeTruthy()
    rerender(<SlaIndicator kind="response" clock={{ state: 'at_risk', minutes_left: 25 }} />)
    expect(screen.getByText('At risk, response due in 25m')).toBeTruthy()
    rerender(<SlaIndicator kind="resolution" clock={{ state: 'paused', minutes_left: 100 }} />)
    expect(screen.getByText('Paused, waiting for the agency')).toBeTruthy()
    rerender(<SlaIndicator kind="resolution" clock={{ state: 'breached', minutes_left: -180 }} />)
    expect(screen.getByText('Resolution breached by 3h')).toBeTruthy()
  })
})

describe('SavedViewTabs', () => {
  function Tabs({ onChange }) {
    const [active, setActive] = useState('a')
    return <SavedViewTabs views={[{ id: 'a', label: 'One' }, { id: 'b', label: 'Two' }, { id: 'c', label: 'Three' }]} counts={{ a: 3, b: 0 }} active={active} onChange={(id) => { setActive(id); onChange(id) }} />
  }

  it('shows counts and moves with the arrow keys, Home and End', () => {
    const onChange = vi.fn()
    render(<Tabs onChange={onChange} />)
    expect(screen.getByRole('tab', { name: /One/ }).textContent).toContain('3')
    fireEvent.keyDown(screen.getByRole('tab', { name: /One/ }), { key: 'ArrowRight' })
    expect(onChange).toHaveBeenLastCalledWith('b')
    fireEvent.keyDown(screen.getByRole('tab', { name: /Two/ }), { key: 'End' })
    expect(onChange).toHaveBeenLastCalledWith('c')
    fireEvent.keyDown(screen.getByRole('tab', { name: /Three/ }), { key: 'Home' })
    expect(onChange).toHaveBeenLastCalledWith('a')
    expect(screen.getByRole('tab', { name: /One/ }).getAttribute('aria-selected')).toBe('true')
  })
})
