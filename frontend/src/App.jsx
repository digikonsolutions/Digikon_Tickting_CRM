import { AuthProvider } from './auth/AuthContext'
import { BrandingProvider } from './branding/BrandingProvider'
import { ToastProvider } from './components/ui'
import { AppRoutes } from './app/AppRoutes'
import './App.css'
import './branding/agency-branding.css'
import './features/reports/reports.css'

function App() {
  return (
    <ToastProvider>
      <AuthProvider>
        <BrandingProvider>
          <AppRoutes />
        </BrandingProvider>
      </AuthProvider>
    </ToastProvider>
  )
}

export default App
