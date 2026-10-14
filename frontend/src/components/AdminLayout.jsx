import { useLocation } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { AdminShell } from './AdminShell'
import '../pages/admin/admin.css'

const TITLES = {
  '/admin/servicos': 'services',
  '/admin/profissionais': 'professionals',
  '/admin/expediente': 'workingHours',
  '/admin/bloqueios': 'scheduleBlocks',
}

/**
 * The admin screens other than the agenda: the same shell (sidebar, language,
 * session) with the section name as the page heading, so every screen has
 * one h1 and the navigation never changes shape between sections.
 */
export function AdminLayout({ children }) {
  const { t } = useTranslation()
  const { pathname } = useLocation()
  const key = TITLES[pathname.replace(/\/+$/, '')]

  return (
    <AdminShell>
      <div className="admin-section">
        {key && <h1 className="admin-section-title">{t(`nav.${key}`)}</h1>}
        {children}
      </div>
    </AdminShell>
  )
}
