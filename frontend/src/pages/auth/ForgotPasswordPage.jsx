import { useState } from 'react'
import { Link } from 'react-router-dom'
import { authService } from '../../auth/authService'
import { AuthLayout } from '../../components/auth/AuthLayout'
import { FormField } from '../../components/auth/FormField'
import { ApiErrorMessage } from '../../components/auth/ApiErrorMessage'

export function ForgotPasswordPage() {
  const [state, setState] = useState({ isLoading: false, message: '', error: null })
  async function submit(event) {
    event.preventDefault()
    const email = String(new FormData(event.currentTarget).get('email')).trim()
    setState({ isLoading: true, message: '', error: null })
    try {
      await authService.requestPasswordReset(email)
      setState({
        isLoading: false,
        message: 'If an account exists for that email, password reset instructions will be sent.',
        error: null,
      })
    } catch (error) {
      setState({ isLoading: false, message: '', error })
    }
  }

  return (
    <AuthLayout title="Reset your password" description="Enter your email and we’ll send reset instructions." footer={<Link to="/login">Back to sign in</Link>}>
      <form onSubmit={submit}>
        <FormField id="email" label="Email address" name="email" type="email" autoComplete="email" maxLength="254" />
        <ApiErrorMessage error={state.error} />
        {state.message && <p className="form-success" role="status">{state.message}</p>}
        <button className="primary-button" type="submit" disabled={state.isLoading}>
          {state.isLoading ? 'Sending…' : 'Send reset link'}
        </button>
      </form>
    </AuthLayout>
  )
}
