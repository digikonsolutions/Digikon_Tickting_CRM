import { useState } from 'react'
import { Link, useNavigate, useSearchParams } from 'react-router-dom'
import { authService } from '../../auth/authService'
import { ApiErrorMessage } from '../../components/auth/ApiErrorMessage'
import { AuthLayout } from '../../components/auth/AuthLayout'
import { FormField } from '../../components/auth/FormField'

export function ResetPasswordPage() {
  const [params] = useSearchParams()
  const navigate = useNavigate()
  const token = params.get('token')
  const [error, setError] = useState(null)
  const [isLoading, setIsLoading] = useState(false)

  async function submit(event) {
    event.preventDefault()
    setError(null)

    if (!token) {
      setError(new Error('This password reset link is incomplete. Request a new reset link.'))
      return
    }

    const data = new FormData(event.currentTarget)
    if (data.get('password') !== data.get('passwordConfirmation')) {
      setError(new Error('Passwords do not match.'))
      return
    }

    setIsLoading(true)
    try {
      await authService.resetPassword({
        token,
        email: data.get('email'),
        password: data.get('password'),
        passwordConfirmation: data.get('passwordConfirmation'),
      })
      navigate('/login', { replace: true, state: { message: 'Your password has been reset. Sign in with your new password.' } })
    } catch (reason) {
      setError(reason)
    } finally {
      setIsLoading(false)
    }
  }

  return (
    <AuthLayout
      title="Choose a new password"
      description="Use at least 12 characters with uppercase, lowercase, a number, and a symbol."
      footer={<Link to="/login">Back to sign in</Link>}
    >
      <form onSubmit={submit}>
        <FormField id="email" label="Email address" name="email" type="email" defaultValue={params.get('email') ?? ''} autoComplete="email" />
        <FormField id="password" label="New password" name="password" type="password" minLength="12" autoComplete="new-password" />
        <FormField id="passwordConfirmation" label="Confirm new password" name="passwordConfirmation" type="password" minLength="12" autoComplete="new-password" />
        <ApiErrorMessage error={error} />
        <button className="primary-button" disabled={isLoading || !token}>
          {isLoading ? 'Updating password…' : 'Reset password'}
        </button>
      </form>
    </AuthLayout>
  )
}
