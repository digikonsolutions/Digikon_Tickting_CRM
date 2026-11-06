import { useState } from 'react'
import { Link, useLocation, useNavigate } from 'react-router-dom'
import { useAuth } from '../../auth/useAuth'
import { ROLE_HOME } from '../../auth/roles'
import { ApiErrorMessage } from '../../components/auth/ApiErrorMessage'
import { AuthLayout } from '../../components/auth/AuthLayout'
import { FormField } from '../../components/auth/FormField'

export function LoginPage() {
  const { signIn, isLoading } = useAuth()
  const navigate = useNavigate()
  const location = useLocation()
  const [error, setError] = useState(null)
  const notice = location.state?.message

  async function submit(event) {
    event.preventDefault()
    setError(null)
    const data = new FormData(event.currentTarget)

    try {
      const user = await signIn({
        email: String(data.get('email')).trim(),
        password: data.get('password'),
      })
      navigate(ROLE_HOME[user.role], { replace: true })
    } catch (reason) {
      setError(reason)
    }
  }

  return (
    <AuthLayout
      eyebrow="Welcome back"
      title="Sign in to your workspace"
      description="Keep every conversation, client, and ticket moving forward."
    >
      <form onSubmit={submit}>
        <FormField id="email" label="Work email" name="email" type="email" placeholder="you@company.com" autoComplete="email" maxLength="254" />
        <FormField id="password" label="Password" name="password" type="password" placeholder="Enter your password" autoComplete="current-password" maxLength="128" />
        <div className="auth-form-meta"><Link className="inline-link" to="/forgot-password">Forgot password?</Link></div>
        {notice && <p className="form-success" role="status">{notice}</p>}
        <ApiErrorMessage error={error} />
        <button className="primary-button" type="submit" disabled={isLoading}>
          {isLoading ? 'Signing in…' : 'Sign in'}
        </button>
      </form>
    </AuthLayout>
  )
}
