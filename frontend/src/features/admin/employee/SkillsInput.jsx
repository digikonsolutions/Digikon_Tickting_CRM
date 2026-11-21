import { useState } from 'react'
import { Button, Select } from '../../../components/ui'
import { Icon } from '../../../components/ui/Icon'
import { MAX_SKILLS, SKILL_LEVELS, firstError } from './employeeForm'

// Tag-style skills editor: type a name, pick a level, press Enter (or Add). Each tag keeps its own level
// and years, and can be removed with one click. Errors from the API are shown per tag.
export function SkillsInput({ skills, onChange, errors }) {
  const [name, setName] = useState('')
  const [level, setLevel] = useState('intermediate')
  const [years, setYears] = useState('')
  const [notice, setNotice] = useState('')
  const full = skills.length >= MAX_SKILLS

  function add() {
    const trimmed = name.trim()
    if (!trimmed) return
    if (skills.some((skill) => skill.name.toLowerCase() === trimmed.toLowerCase())) {
      setNotice(`“${trimmed}” is already in the list.`)
      return
    }
    onChange([...skills, { name: trimmed, level, years_experience: years }])
    setName('')
    setYears('')
    setNotice('')
  }

  function update(index, patch) {
    onChange(skills.map((skill, i) => (i === index ? { ...skill, ...patch } : skill)))
  }

  return (
    <div className="emp-skills">
      <div className="emp-skill-add" role="group" aria-label="Add a skill">
        <div className="ui-field">
          <label className="ui-field-label" htmlFor="skill-name">Skill</label>
          <input
            id="skill-name"
            className="ui-input"
            type="text"
            maxLength="60"
            placeholder="e.g. Laravel, Figma, GoHighLevel"
            value={name}
            disabled={full}
            onChange={(event) => { setName(event.target.value); setNotice('') }}
            onKeyDown={(event) => { if (event.key === 'Enter') { event.preventDefault(); add() } }}
          />
        </div>
        <Select id="skill-level" label="Level" value={level} onChange={(event) => setLevel(event.target.value)} disabled={full}>
          {SKILL_LEVELS.map((option) => <option key={option.value} value={option.value}>{option.label}</option>)}
        </Select>
        <div className="ui-field">
          <label className="ui-field-label" htmlFor="skill-years">Years</label>
          <input id="skill-years" className="ui-input" type="number" min="0" max="60" step="0.5" inputMode="decimal" value={years} disabled={full} onChange={(event) => setYears(event.target.value)} />
        </div>
        <Button type="button" variant="secondary" onClick={add} disabled={full || !name.trim()}>
          <Icon name="plus" size={16} /> Add
        </Button>
      </div>
      {notice && <p className="ui-field-error" role="alert">{notice}</p>}
      {firstError(errors, 'skills') && <p className="ui-field-error" role="alert">{firstError(errors, 'skills')}</p>}
      <p className="ui-field-help">{skills.length} of {MAX_SKILLS} skills. Press Enter to add.</p>

      {skills.length > 0 && (
        <ul className="emp-skill-list" aria-label="Skills">
          {skills.map((skill, index) => {
            const problem = firstError(errors, `skills.${index}.name`) ?? firstError(errors, `skills.${index}.level`) ?? firstError(errors, `skills.${index}.years_experience`)
            return (
              <li className={`emp-skill-tag emp-level-${skill.level}`} key={skill.name}>
                <strong>{skill.name}</strong>
                <select
                  className="emp-skill-level"
                  aria-label={`Level for ${skill.name}`}
                  value={skill.level}
                  onChange={(event) => update(index, { level: event.target.value })}
                >
                  {SKILL_LEVELS.map((option) => <option key={option.value} value={option.value}>{option.label}</option>)}
                </select>
                <input
                  className="emp-skill-years"
                  type="number"
                  min="0"
                  max="60"
                  step="0.5"
                  aria-label={`Years of experience with ${skill.name}`}
                  placeholder="yrs"
                  value={skill.years_experience}
                  onChange={(event) => update(index, { years_experience: event.target.value })}
                />
                <button type="button" className="emp-icon-button" aria-label={`Remove ${skill.name}`} onClick={() => onChange(skills.filter((_, i) => i !== index))}>
                  <Icon name="close" size={14} />
                </button>
                {problem && <span className="ui-field-error emp-skill-error" role="alert">{problem}</span>}
              </li>
            )
          })}
        </ul>
      )}
    </div>
  )
}
