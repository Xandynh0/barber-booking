import { BrowserRouter, Route, Routes } from 'react-router-dom'
import { ProtectedRoute } from './components/ProtectedRoute'
import { AuthProvider } from './context/AuthContext'
import Agenda from './pages/admin/Agenda'
import Login from './pages/admin/Login'
import Professionals from './pages/admin/Professionals'
import Services from './pages/admin/Services'
import Home from './pages/Home'

function AdminArea() {
  return (
    <AuthProvider>
      <Routes>
        <Route path="login" element={<Login />} />
        <Route
          path="agenda"
          element={
            <ProtectedRoute>
              <Agenda />
            </ProtectedRoute>
          }
        />
        <Route
          path="servicos"
          element={
            <ProtectedRoute>
              <Services />
            </ProtectedRoute>
          }
        />
        <Route
          path="profissionais"
          element={
            <ProtectedRoute>
              <Professionals />
            </ProtectedRoute>
          }
        />
      </Routes>
    </AuthProvider>
  )
}

function App() {
  return (
    <BrowserRouter>
      <Routes>
        <Route path="/" element={<Home />} />
        <Route path="/admin/*" element={<AdminArea />} />
      </Routes>
    </BrowserRouter>
  )
}

export default App
