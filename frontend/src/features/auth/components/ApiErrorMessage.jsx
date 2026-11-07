import { getErrorMessage } from '../../../auth/authService'
import { Alert } from '../../../components/ui/Alert'

export function ApiErrorMessage({ error }) {
  if (!error) return null

  const fieldErrors = Object.entries(error.errors ?? {}).flatMap(([field, messages]) =>
    messages.map((message) => ({ field, message })),
  )

  return (
    <Alert variant="error">
      {getErrorMessage(error)}
      {fieldErrors.length > 0 && (
        <ul>
          {fieldErrors.map(({ field, message }, index) => (
            <li key={`${field}-${message}-${index}`}>
              <strong>{field.replaceAll('_', ' ')}:</strong> {message}
            </li>
          ))}
        </ul>
      )}
    </Alert>
  )
}
