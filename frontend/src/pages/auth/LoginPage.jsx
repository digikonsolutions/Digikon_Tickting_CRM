import { useState } from 'react'
import { Link, useLocation, useNavigate } from 'react-router-dom'
import { AuthLayout } from '../../components/auth/AuthLayout'
import { FormField } from '../../components/auth/FormField'
import { useAuth } from '../../auth/useAuth'
import { authService } from '../../auth/authService'
import { ApiErrorMessage } from '../../components/auth/ApiErrorMessage'

export function LoginPage() {
  const { signIn, isLoading } = useAuth()
  const navigate = useNavigate()
  const location = useLocation()
  const [error, setError] = useState(null)
  const notice = location.state?.message
  async function submit(event) {
    event.preventDefault(); setError(null)
    const data = new FormData(event.currentTarget)
    try { await signIn({ email: data.get('email'), password: data.get('password') }); navigate(location.state?.from ?? '/dashboard', { replace: true }) } catch (reason) { setError(reason) }
  }
  return <AuthLayout eyebrow="Welcome back" title="Sign in to your workspace" description="Keep every conversation, client, and ticket moving forward." footer={<>Need an account? <Link to="/signup">Contact your administrator</Link></>}><form onSubmit={submit}><FormField id="email" label="Work email" name="email" type="email" placeholder="you@company.com" autoComplete="email" /><FormField id="password" label="Password" name="password" type="password" placeholder="Enter your password" autoComplete="current-password" /><div className="auth-form-meta"><Link className="inline-link" to="/forgot-password">Forgot password?</Link></div>{notice && <p className="form-success" role="status">{notice}</p>}<ApiErrorMessage error={error} /><button className="primary-button" disabled={isLoading}>{isLoading ? 'Signing in…' : 'Sign in'}</button></form>{authService.isMockMode && <p className="demo-note">Demo mode is active: use an email starting with <code>admin</code>, <code>agency.owner</code>, <code>client</code>, or <code>employee</code>.</p>}</AuthLayout>
}
