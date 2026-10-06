import { NavLink } from 'react-router-dom'
import './AdminNav.css'

const LINKS = [
  { to: '/admin/agenda', label: 'Agenda' },
  { to: '/admin/servicos', label: 'Serviços' },
  { to: '/admin/profissionais', label: 'Profissionais' },
  { to: '/admin/expediente', label: 'Expediente' },
  { to: '/admin/bloqueios', label: 'Bloqueios' },
]

export function AdminNav() {
  return (
    <nav className="admin-nav" aria-label="Navegação administrativa">
      {LINKS.map((link) => (
        <NavLink
          key={link.to}
          to={link.to}
          className={({ isActive }) => `admin-nav-link${isActive ? ' admin-nav-link--active' : ''}`}
        >
          {link.label}
        </NavLink>
      ))}
    </nav>
  )
}
