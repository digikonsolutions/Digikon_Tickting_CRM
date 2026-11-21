import { useCallback, useEffect, useId, useRef, useState } from 'react'
import { authService } from '../../auth/authService'
import { useBranding } from '../../branding/useBranding'
import { Icon, Spinner } from '../../components/ui'
import { AnswerBlocks } from './AnswerBlocks'

function QuestionButton({ question, onAsk, disabled }) {
  const [value, setValue] = useState('')
  const [open, setOpen] = useState(false)
  const selectId = useId()
  const param = question.params?.[0]

  if (!param) {
    return (
      <button type="button" className="as-chip" disabled={disabled} onClick={() => onAsk(question, {})} title={question.description}>
        {question.label}
      </button>
    )
  }

  return (
    <div className={`as-chip-group${open ? ' is-open' : ''}`}>
      <button type="button" className="as-chip" disabled={disabled} aria-expanded={open} onClick={() => setOpen((o) => !o)} title={question.description}>
        {question.label}
      </button>
      {open && (
        <form
          className="as-param-form"
          onSubmit={(event) => {
            event.preventDefault()
            onAsk(question, value ? { [param.name]: value } : {}, param.options?.find((o) => String(o.value) === value)?.label)
            setOpen(false)
          }}
        >
          <label htmlFor={selectId} className="as-param-label">{param.label}</label>
          <select id={selectId} className="ui-input ui-select as-param-select" value={value} onChange={(event) => setValue(event.target.value)} required={param.required}>
            <option value="">{param.placeholder ?? `Any ${param.label.toLowerCase()}`}</option>
            {(param.options ?? []).map((option) => <option key={option.value} value={option.value}>{option.label}</option>)}
          </select>
          <button type="submit" className="ui-button ui-button-primary ui-button-sm" disabled={disabled}>Ask</button>
        </form>
      )}
    </div>
  )
}

/**
 * "Ask" assistant shown on every page for every role. Questions are preset per role by the API and every
 * answer is read from live data, so there is no free-text input and nothing leaves the workspace.
 */
export function AssistantWidget() {
  const { displayName } = useBranding()
  const [open, setOpen] = useState(false)
  const [catalog, setCatalog] = useState({ state: 'idle', categories: [], error: null })
  const [thread, setThread] = useState([])
  const [busy, setBusy] = useState(false)
  const launcherRef = useRef(null)
  const panelRef = useRef(null)
  const endRef = useRef(null)
  const panelId = useId()
  const name = displayName || 'ClientHub'

  const loadQuestions = useCallback(async () => {
    setCatalog((c) => ({ ...c, state: 'loading', error: null }))
    try {
      const data = await authService.getAssistantQuestions()
      setCatalog({ state: 'ready', categories: data.categories ?? [], error: null })
    } catch (error) {
      setCatalog({ state: 'error', categories: [], error })
    }
  }, [])

  function toggle() {
    const next = !open
    setOpen(next)
    if (next && catalog.state === 'idle') void loadQuestions()
  }

  const close = useCallback(() => {
    setOpen(false)
    launcherRef.current?.focus()
  }, [])

  useEffect(() => {
    if (!open) return undefined
    panelRef.current?.focus()
    const onKey = (event) => { if (event.key === 'Escape') close() }
    document.addEventListener('keydown', onKey)
    return () => document.removeEventListener('keydown', onKey)
  }, [open, close])

  useEffect(() => {
    endRef.current?.scrollIntoView?.({ block: 'end', behavior: window.matchMedia?.('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth' })
  }, [thread])

  async function ask(question, params, paramLabel) {
    const id = `${question.key}-${Date.now()}`
    setThread((t) => [...t, { id, question: paramLabel ? `${question.label} (${paramLabel})` : question.label, status: 'loading', question_key: question.key, params }])
    setBusy(true)
    try {
      const answer = await authService.askAssistant(question.key, params)
      setThread((t) => t.map((entry) => (entry.id === id ? { ...entry, status: 'done', answer } : entry)))
    } catch (error) {
      setThread((t) => t.map((entry) => (entry.id === id ? { ...entry, status: 'error', error } : entry)))
    } finally {
      setBusy(false)
    }
  }

  function retry(entry) {
    setThread((t) => t.filter((e) => e.id !== entry.id))
    void ask({ key: entry.question_key, label: entry.question }, entry.params)
  }

  // On phones the panel covers the page, so following a link closes it.
  const onNavigate = () => { if (window.matchMedia?.('(max-width: 760px)').matches) setOpen(false) }
  const lastStatus = thread.at(-1)?.status

  return (
    <>
      <button
        ref={launcherRef}
        type="button"
        className={`as-launcher${open ? ' is-open' : ''}`}
        aria-label={open ? 'Close assistant' : 'Ask the assistant'}
        aria-expanded={open}
        aria-controls={panelId}
        onClick={toggle}
      >
        <Icon name={open ? 'close' : 'chat'} size={20} />
        <span className="as-launcher-label">{open ? 'Close' : 'Ask'}</span>
      </button>

      <section
        ref={panelRef}
        id={panelId}
        className="as-panel"
        role="dialog"
        aria-modal="false"
        aria-labelledby={`${panelId}-title`}
        tabIndex={-1}
        hidden={!open}
      >
        <header className="as-header">
          <div>
            <h2 id={`${panelId}-title`}>Ask {name}</h2>
            <p>Answers come straight from your live workspace data.</p>
          </div>
          <button type="button" className="as-icon-btn" aria-label="Close assistant" onClick={close}><Icon name="close" size={18} /></button>
        </header>

        <div className="as-body">
          {thread.length > 0 && (
            <ol className="as-thread" aria-label="Conversation">
              {thread.map((entry) => (
                <li key={entry.id} className="as-turn">
                  <p className="as-question"><span className="sr-only">You asked: </span>{entry.question}</p>
                  <div className="as-answer">
                    {entry.status === 'loading' && <p className="as-muted as-loading"><Spinner size="sm" label="" /> Looking that up…</p>}
                    {entry.status === 'error' && (
                      <p className="as-error" role="alert">
                        {entry.error?.message ?? 'Something went wrong.'}{' '}
                        <button type="button" className="inline-link" onClick={() => retry(entry)}>Try again</button>
                      </p>
                    )}
                    {entry.status === 'done' && (
                      <>
                        <h3 className="as-answer-title">{entry.answer.title}</h3>
                        <AnswerBlocks answer={entry.answer} onNavigate={onNavigate} />
                      </>
                    )}
                  </div>
                </li>
              ))}
              <li ref={endRef} aria-hidden="true" className="as-end" />
            </ol>
          )}

          <div className="as-suggestions">
            <h3 className="as-suggestions-title">{thread.length ? 'Ask something else' : 'What would you like to know?'}</h3>
            {catalog.state === 'loading' && <p className="as-muted as-loading"><Spinner size="sm" label="" /> Loading questions…</p>}
            {catalog.state === 'error' && (
              <p className="as-error" role="alert">
                Could not load questions. <button type="button" className="inline-link" onClick={loadQuestions}>Try again</button>
              </p>
            )}
            {catalog.state === 'ready' && catalog.categories.map((category) => (
              <div key={category.name} className="as-category">
                <h4>{category.name}</h4>
                <div className="as-chips">
                  {category.questions.map((question) => <QuestionButton key={question.key} question={question} onAsk={ask} disabled={busy} />)}
                </div>
              </div>
            ))}
          </div>
        </div>
        <p className="sr-only" aria-live="polite">{lastStatus === 'loading' ? 'Looking that up.' : lastStatus === 'done' ? 'Answer ready.' : ''}</p>
      </section>
    </>
  )
}
