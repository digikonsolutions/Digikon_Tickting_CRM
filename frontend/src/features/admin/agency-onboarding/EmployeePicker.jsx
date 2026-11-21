import { useCallback, useEffect, useState } from 'react'
import { authService } from '../../../auth/authService'
import { useAsync } from '../../../hooks/useAsync'
import { Alert, Avatar, Badge, Button, EmptyState, Input, Skeleton } from '../../../components/ui'

// Free employees (nobody holds them) to pre-assign to the new agency, with search, avatars and a seat counter.
// `selected` is the list of ids, `onChange` gets the next list, `onKnown` is told about the employees seen so the
// review step can show their names, and `seats` (or null) is the contract limit.
export function EmployeePicker({ selected, onChange, onKnown, seats, error }) {
  const [search, setSearch] = useState('')
  const [debounced, setDebounced] = useState('')
  useEffect(() => {
    const timer = setTimeout(() => setDebounced(search.trim()), 250)
    return () => clearTimeout(timer)
  }, [search])
  // One loader per search term: useAsync reruns exactly when the term changes.
  const load = useCallback(() => authService.listAssignableEmployees({ search: debounced }), [debounced])
  const { data, error: loadError, isLoading, reload } = useAsync(load)
  const employees = data?.employees ?? []
  useEffect(() => { if (data?.employees) onKnown?.(data.employees) }, [data, onKnown])

  const atLimit = seats !== null && selected.length >= seats
  const toggle = (id) => onChange(selected.includes(id) ? selected.filter((item) => item !== id) : [...selected, id])

  return (
    <div className="onb-picker">
      <div className="onb-picker-head">
        <Input id="employee-search" label="Search available employees" type="search" value={search} onChange={(event) => setSearch(event.target.value)} placeholder="Search by name" />
        <p className={`onb-seats${atLimit ? ' is-full' : ''}`} aria-live="polite">
          <strong>{selected.length}</strong>{seats !== null ? ` of ${seats} seat${seats === 1 ? '' : 's'}` : ' selected'}
          {atLimit && ' · seats full'}
        </p>
      </div>
      {error && <p className="ui-field-error" role="alert">{error}</p>}
      {loadError && (
        <Alert variant="error" title="Could not load employees">
          {loadError.message}
          <div className="ui-retry"><Button type="button" size="sm" variant="secondary" onClick={reload}>Try again</Button></div>
        </Alert>
      )}
      {isLoading && !data && <Skeleton variant="row" />}
      {data && employees.length === 0 && (
        <EmptyState title={debounced ? 'No one matches' : 'Everyone is already held'}>
          {debounced ? 'Try another name.' : 'There are no free employees right now. You can assign employees later from the agency.'}
        </EmptyState>
      )}
      {employees.length > 0 && (
        <ul className="onb-employees" aria-label="Available employees">
          {employees.map((employee) => {
            const checked = selected.includes(employee.id)
            const blocked = !checked && atLimit
            return (
              <li key={employee.id}>
                <label className={`onb-employee${checked ? ' is-selected' : ''}${blocked ? ' is-blocked' : ''}`}>
                  <input type="checkbox" checked={checked} disabled={blocked} onChange={() => toggle(employee.id)} />
                  <Avatar name={employee.name} src={employee.photo_url} size="md" />
                  <span className="onb-employee-main">
                    <strong>{employee.name}</strong>
                    <small>{employee.headline ?? 'No headline'}</small>
                  </span>
                  <span className="onb-employee-skills">
                    {(employee.top_skills ?? []).slice(0, 2).map((skill) => <Badge key={skill} variant="slate">{skill}</Badge>)}
                  </span>
                </label>
              </li>
            )
          })}
        </ul>
      )}
    </div>
  )
}
