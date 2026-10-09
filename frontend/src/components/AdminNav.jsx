import { NavLink } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import './AdminNav.css'

const LINKS = [
  { to: '/admin/agenda', key: 'agenda' },
  { to: '/admin/servicos', key: 'services' },
  { to: '/admin/profissionais', key: 'professionals' },
  { to: '/admin/expediente', key: 'workingHours' },
  { to: '/admin/bloqueios', key: 'scheduleBlocks' },
]

export function AdminNav() {
  const { t } = useTranslation()

  return (
    <nav className="admin-nav" aria-label={t('nav.ariaLabel')}>
      {LINKS.map((link) => (
        <NavLink
          key={link.to}
          to={link.to}
          className={({ isActive }) => `admin-nav-link${isActive ? ' admin-nav-link--active' : ''}`}
        >
          {t(`nav.${link.key}`)}
        </NavLink>
      ))}
    </nav>
  )
}
