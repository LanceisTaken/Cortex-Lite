import { Navigate, Route, Routes } from 'react-router-dom'
import { useAuth } from './context/AuthContext'
import { ProtectedRoute } from './components/ProtectedRoute'
import Login from './pages/Login'
import Register from './pages/Register'
import ForgotPassword from './pages/ForgotPassword'
import ResetPassword from './pages/ResetPassword'
import VerifyEmail from './pages/VerifyEmail'
import Dashboard from './pages/Dashboard'
import Account from './pages/Account'

function GuestOnly({ children }) {
  const { user, loading } = useAuth()
  if (loading) return <div className="p-8 text-slate-500">Loading…</div>
  return user ? <Navigate to="/dashboard" replace /> : children
}

function Fallback() {
  const { user, loading } = useAuth()
  if (loading) return <div className="p-8 text-slate-500">Loading…</div>
  return <Navigate to={user ? '/dashboard' : '/login'} replace />
}

export default function App() {
  return (
    <Routes>
      <Route path="/login" element={<GuestOnly><Login /></GuestOnly>} />
      <Route path="/register" element={<GuestOnly><Register /></GuestOnly>} />
      <Route path="/forgot-password" element={<GuestOnly><ForgotPassword /></GuestOnly>} />
      <Route path="/reset-password/:token" element={<GuestOnly><ResetPassword /></GuestOnly>} />
      <Route path="/verify-email/:id/:hash" element={<VerifyEmail />} />
      <Route path="/dashboard" element={<ProtectedRoute><Dashboard /></ProtectedRoute>} />
      <Route path="/account" element={<ProtectedRoute requireVerified={false}><Account /></ProtectedRoute>} />
      <Route path="*" element={<Fallback />} />
    </Routes>
  )
}
