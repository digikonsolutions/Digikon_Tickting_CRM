import { getErrorMessage } from '../../auth/authService'

export function ApiErrorMessage({ error }) {
  if (!error) return null

  const fieldErrors = Object.values(error.errors ?? {}).flat()

  return (
    <div className="form-error" role="alert">
      <p>{getErrorMessage(error)}</p>
      {fieldErrors.length > 0 && (
        <ul>
          {fieldErrors.map((message, index) => <li key={`${message}-${index}`}>{message}</li>)}
        </ul>
      )}
    </div>
  )
}
