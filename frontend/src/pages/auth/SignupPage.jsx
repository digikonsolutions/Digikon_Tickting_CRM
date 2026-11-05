import { Link } from 'react-router-dom'
import { AuthLayout } from '../../components/auth/AuthLayout'

export function SignupPage() {
  return <AuthLayout eyebrow="Workspace access" title="Your team sets up your account" description="For security, accounts are created by your administrator. Ask them for an invitation or sign-in details." footer={<>Already have access? <Link to="/login">Back to sign in</Link></>} />
}
