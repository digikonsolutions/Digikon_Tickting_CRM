import { Input } from './Fields'

// A link field. The browser checks the format as the person types; the server checks it again.
export function UrlInput({ label = 'Link', ...props }) {
  return <Input type="url" inputMode="url" autoComplete="off" placeholder="https://" maxLength={500} label={label} {...props} />
}
