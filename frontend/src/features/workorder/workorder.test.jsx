import { fireEvent, render, screen } from '@testing-library/react'
import { useState } from 'react'
import { describe, expect, it } from 'vitest'
import { ChecklistEditor, ChipInput } from '../../components/ui'
import { briefPercent, briefToPayload, briefToState } from './useProjectBrief'
import { compact, errorAt, hasErrorUnder } from './objectPath'
import { missingFields, orderToPayload, orderToState } from './workOrder'

describe('payload mapping', () => {
  it('compact drops empty values but keeps false and 0', () => {
    expect(compact({ a: '', b: [], c: { d: '' }, e: false, f: 0, g: ['x', ''], _key: 'k' })).toEqual({ e: false, f: 0, g: ['x'] })
  })

  it('a new brief sends only sections with content; an edit says which sections were cleared', () => {
    const state = briefToState({ client: { name: 'Ada', email: '' }, scope: { categories: ['workflow'] } })
    expect(briefToPayload(state)).toEqual({ client: { name: 'Ada' }, scope: { categories: ['workflow'] } })
    const edit = briefToPayload(state, { replaceAll: true })
    expect(edit.ghl).toBeNull()
    expect(edit.client).toEqual({ name: 'Ada' })
  })

  it('brief percent follows the recommended fields', () => {
    expect(briefPercent(briefToState(null))).toBe(0)
    expect(briefPercent(briefToState({ client: { name: 'Ada', email: 'a@b.co' } }))).toBeGreaterThan(0)
  })

  it('a work order payload keeps real answers, turns the yes/no selects into booleans and leaves out empty sections', () => {
    const state = orderToState(null)
    state.work_type = 'workflow_build'
    state.environment.affects_live_traffic = 'false'
    state.inputs.inputs_ready = 'true'
    state.checklist = [{ text: ' Build it ' }, { text: '' }]
    const payload = orderToPayload(state)
    expect(payload.environment).toEqual({ affects_live_traffic: false })
    expect(payload.inputs).toEqual({ inputs_ready: true })
    expect(payload.checklist).toEqual([{ text: 'Build it' }])
    expect(payload.requirements).toBeUndefined()
    expect(orderToPayload(orderToState(null), { replaceAll: true })).toMatchObject({ requirements: {}, checklist: [], work_type: null })
  })

  it('lists what a template recommends and the form still lacks', () => {
    const state = orderToState(null)
    expect(missingFields(state, { recommended: ['requirements.instructions', 'ghl.refs'] })).toEqual(['instructions', 'GoHighLevel targets'])
    state.requirements.instructions = 'Do it'
    expect(missingFields(state, { recommended: ['requirements.instructions'] })).toEqual([])
  })

  it('finds server errors by dotted path', () => {
    const errors = { 'work_order.ghl.refs.0.url': ['Bad link'] }
    expect(errorAt(errors, 'work_order.ghl.refs.0.url')).toBe('Bad link')
    expect(hasErrorUnder(errors, 'work_order.ghl')).toBe(true)
    expect(hasErrorUnder(errors, 'work_order.testing')).toBe(false)
  })
})

describe('shared inputs', () => {
  function Chips() {
    const [value, setValue] = useState([])
    return <ChipInput label="Tags" value={value} onChange={setValue} />
  }

  it('ChipInput adds on Enter, ignores duplicates and removes with the chip button', () => {
    render(<Chips />)
    const box = screen.getByLabelText('Tags')
    fireEvent.change(box, { target: { value: 'vip' } })
    fireEvent.keyDown(box, { key: 'Enter' })
    fireEvent.change(box, { target: { value: 'vip' } })
    fireEvent.keyDown(box, { key: 'Enter' })
    expect(screen.getAllByText('vip')).toHaveLength(1)
    fireEvent.click(screen.getByRole('button', { name: 'Remove vip' }))
    expect(screen.queryByText('vip')).toBeNull()
  })

  function Steps() {
    const [items, setItems] = useState([{ text: 'one' }, { text: 'two' }])
    return <ChecklistEditor items={items} onChange={setItems} />
  }

  it('ChecklistEditor reorders and removes steps with buttons only', () => {
    render(<Steps />)
    fireEvent.click(screen.getByRole('button', { name: 'Move item 2 up' }))
    expect(screen.getAllByRole('textbox').map((input) => input.value)).toEqual(['two', 'one'])
    fireEvent.click(screen.getByRole('button', { name: 'Remove item 1' }))
    expect(screen.getAllByRole('textbox').map((input) => input.value)).toEqual(['one'])
  })
})
