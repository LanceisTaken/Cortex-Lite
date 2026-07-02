import { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'
import { BrowserRouter } from 'react-router-dom'
import './index.css'
import App from './App.jsx'
import { AuthProvider } from './context/AuthContext.jsx'
import { PlaySessionProvider } from './context/PlaySessionContext.jsx'

createRoot(document.getElementById('root')).render(
  <StrictMode>
    <BrowserRouter>
      <AuthProvider>
        <PlaySessionProvider>
          <App />
        </PlaySessionProvider>
      </AuthProvider>
    </BrowserRouter>
  </StrictMode>,
)
