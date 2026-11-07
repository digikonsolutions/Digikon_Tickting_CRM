export function FormField({ id, label, ...inputProps }) {
  return <label className="field" htmlFor={id}><span>{label}</span><input id={id} required {...inputProps} /></label>
}
