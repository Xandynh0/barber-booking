import { useState } from 'react'
import { NavLink, useNavigate } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { useAuth } from '../context/AuthContext'
import { LanguageSwitcher } from './LanguageSwitcher'
import { BanIcon, CalendarIcon, ClockIcon, MenuIcon, ScissorsIcon, UsersIcon } from './PublicIcons'
import '../styles/barber-theme.css'
import './AdminShell.css'

const LINKS = [
  { to: '/admin/agenda', key: 'agenda', Icon: CalendarIcon },
  { to: '/admin/servicos', key: 'services', Icon: ScissorsIcon },
  { to: '/admin/profissionais', key: 'professionals', Icon: UsersIcon },
  { to: '/admin/expediente', key: 'workingHours', Icon: ClockIcon },
  { to: '/admin/bloqueios', key: 'scheduleBlocks', Icon: BanIcon },
]

/**
 * Admin layout after docs/design/1.png: dark sidebar with the brand and the
 * sections, and a light top bar with language and session. On narrow
 * screens the sidebar becomes a top bar whose menu opens on demand.
 *
 * Every admin screen uses it: the agenda directly, the others through
 * AdminLayout, which adds the section heading.
 */
export function AdminShell({ children }) {
  const { t } = useTranslation()
  const { user, logout } = useAuth()
  const navigate = useNavigate()
  const [menuOpen, setMenuOpen] = useState(false)
  const [signingOut, setSigningOut] = useState(false)

  async function handleLogout() {
    setSigningOut(true)
    try {
      await logout()
    } finally {
      navigate('/admin/login', { replace: true })
    }
  }

  return (
    <div className="admin-shell barber-theme">
      <aside className="admin-sidebar">
        <div className="admin-sidebar-top">
          <span className="admin-sidebar-brand">
            <ScissorsIcon size={26} className="admin-sidebar-brand-icon" />
            {t('common.brand')}
          </span>
          <button
            type="button"
            className="admin-menu-toggle"
            aria-expanded={menuOpen}
            aria-controls="admin-sidebar-nav"
            onClick={() => setMenuOpen((open) => !open)}
          >
            <MenuIcon size={22} />
            <span className="sr-only">{t('nav.ariaLabel')}</span>
          </button>
        </div>
        <nav
          id="admin-sidebar-nav"
          className={`admin-sidebar-nav${menuOpen ? ' admin-sidebar-nav--open' : ''}`}
          aria-label={t('nav.ariaLabel')}
        >
          {LINKS.map(({ to, key, Icon }) => (
            <NavLink
              key={to}
              to={to}
              className={({ isActive }) => `admin-sidebar-link${isActive ? ' admin-sidebar-link--active' : ''}`}
            >
              <Icon size={20} />
              {t(`nav.${key}`)}
            </NavLink>
          ))}
        </nav>
      </aside>

      <div className="admin-shell-main">
        <header className="admin-topbar">
          <LanguageSwitcher />
          <span className="admin-topbar-user" title={user?.email}>
            <span className="admin-topbar-avatar" aria-hidden="true">
              {(user?.name ?? '').match(/[\p{L}\p{N}]/u)?.[0]?.toUpperCase() ?? '?'}
            </span>
            <span className="admin-topbar-name">{user?.name}</span>
          </span>
          <button type="button" className="secondary-button admin-topbar-signout" onClick={handleLogout} disabled={signingOut}>
            {signingOut ? t('common.actions.signingOut') : t('common.actions.signOut')}
          </button>
        </header>
        <main className="admin-shell-content">{children}</main>
      </div>
    </div>
  )
}
