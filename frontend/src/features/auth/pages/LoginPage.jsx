import { useState } from 'react'
import { Link, useLocation, useNavigate } from 'react-router-dom'
import { useAuth } from '../../../auth/useAuth'
import { ROLE_HOME } from '../../../auth/roles'
import { AuthLayout } from '../components/AuthLayout'
import { Alert, Button, Input } from '../../../components/ui'

export function LoginPage() {
  const { signIn } = useAuth()
  const navigate = useNavigate()
  const location = useLocation()
  const [error, setError] = useState(null)
  const [isSubmitting, setIsSubmitting] = useState(false)
  const notice = location.state?.message

  async function submit(event) {
    event.preventDefault()
    setError(null)
    setIsSubmitting(true)
    const data = new FormData(event.currentTarget)

    try {
      const user = await signIn({
        email: String(data.get('email')).trim(),
        password: data.get('password'),
      })
      navigate(ROLE_HOME[user.role], { replace: true })
    } catch (reason) {
      setError(reason)
    } finally {
      setIsSubmitting(false)
    }
  }

  return (
    <AuthLayout
      eyebrow="Welcome back"
      title="Sign in to your workspace"
      description="Keep every conversation, client, and ticket moving forward."
    >
      <form onSubmit={submit}>
        <Input id="email" label="Work email" name="email" type="email" placeholder="you@company.com" autoComplete="email" maxLength="254" required />
        <Input id="password" label="Password" name="password" type="password" placeholder="Enter your password" autoComplete="current-password" maxLength="128" required />
        <div className="auth-form-meta"><Link className="inline-link" to="/forgot-password">Forgot password?</Link></div>
        {notice && <Alert variant="success">{notice}</Alert>}
        {error && <Alert variant="error">{error.message}</Alert>}
        <Button type="submit" size="lg" loading={isSubmitting} loadingText="Signing in…">Sign in</Button>
      </form>
    </AuthLayout>
  )
}
