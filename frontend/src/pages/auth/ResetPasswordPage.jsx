import { useState } from 'react'
import { Link, useNavigate, useSearchParams } from 'react-router-dom'
import { authService } from '../../auth/authService'
import { ROLE_HOME } from '../../auth/roles'
import { useAuth } from '../../auth/useAuth'
import { ApiErrorMessage } from '../../components/auth/ApiErrorMessage'
import { AuthLayout } from '../../components/auth/AuthLayout'
import { FormField } from '../../components/auth/FormField'

export function ResetPasswordPage() {
  const [params] = useSearchParams()
  const navigate = useNavigate()
  const { user } = useAuth()
  const token = params.get('token')
  const email = params.get('email') ?? ''
  const isInvite = params.get('invite') === '1'
  const [error, setError] = useState(null)
  const [isLoading, setIsLoading] = useState(false)
  const [isComplete, setIsComplete] = useState(false)

  async function submit(event) {
    event.preventDefault()
    setError(null)

    if (!token || !email) {
      setError(new Error('This password reset link is incomplete. Request a new reset link.'))
      return
    }

    const data = new FormData(event.currentTarget)
    if (data.get('password') !== data.get('passwordConfirmation')) {
      setError(new Error('Passwords do not match.'))
      return
    }
    if (!/(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[^A-Za-z\d]).{12,}/.test(data.get('password'))) {
      setError(new Error('Use at least 12 characters with uppercase, lowercase, a number, and a symbol.'))
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
      if (user) {
        setIsComplete(true)
      } else {
        navigate('/login', { replace: true, state: { message: 'Your password has been reset. Sign in with your new password.' } })
      }
    } catch (reason) {
      setError(reason)
    } finally {
      setIsLoading(false)
    }
  }

  return (
    <AuthLayout
      title={isComplete ? 'Password set successfully' : isInvite ? 'Set your password' : 'Reset your password'}
      description={isComplete
        ? `The password for ${email} has been updated. You are still signed in as ${user.name}; this does not sign you in as the invited user.`
        : 'Use at least 12 characters with uppercase, lowercase, a number, and a symbol.'}
      footer={isComplete && user
        ? <Link to={ROLE_HOME[user.role]}>Return to your workspace</Link>
        : <Link to="/login">Back to sign in</Link>}
    >
      {isComplete
        ? <p className="form-success" role="status">The invited user can now sign in with their new password.</p>
        : (
          <form onSubmit={submit}>
            <FormField id="email" label="Email address" name="email" type="email" defaultValue={email} autoComplete="email" maxLength="254" />
            <FormField id="password" label="New password" name="password" type="password" minLength={12} maxLength="128" autoComplete="new-password" />
            <FormField id="passwordConfirmation" label="Confirm new password" name="passwordConfirmation" type="password" minLength={12} maxLength="128" autoComplete="new-password" />
            <ApiErrorMessage error={error} />
            <button className="primary-button" type="submit" disabled={isLoading || !token || !email}>
              {isLoading ? 'Updating password…' : isInvite ? 'Set password' : 'Reset password'}
            </button>
          </form>
        )}
    </AuthLayout>
  )
}
