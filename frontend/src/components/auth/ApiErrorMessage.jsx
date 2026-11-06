import { getErrorMessage } from '../../auth/authService'

export function ApiErrorMessage({ error }) {
  if (!error) return null

  const fieldErrors = Object.entries(error.errors ?? {}).flatMap(([field, messages]) =>
    messages.map((message) => ({ field, message })),
  )

  return (
    <div className="form-error" role="alert">
      <p>{getErrorMessage(error)}</p>
      {fieldErrors.length > 0 && (
        <ul>
          {fieldErrors.map(({ field, message }, index) => (
            <li key={`${field}-${message}-${index}`}>
              <strong>{field.replaceAll('_', ' ')}:</strong> {message}
            </li>
          ))}
        </ul>
      )}
    </div>
  )
}
