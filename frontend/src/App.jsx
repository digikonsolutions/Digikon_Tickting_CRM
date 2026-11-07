import { AuthProvider } from './auth/AuthContext'
import { ToastProvider } from './components/ui'
import { AppRoutes } from './app/AppRoutes'
import './App.css'

function App() {
  return (
    <ToastProvider>
      <AuthProvider>
        <AppRoutes />
      </AuthProvider>
    </ToastProvider>
  )
}

export default App
