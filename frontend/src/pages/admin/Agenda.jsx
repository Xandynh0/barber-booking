import { useTranslation } from 'react-i18next'
import { AdminLayout } from '../../components/AdminLayout'
import './admin.css'

function Agenda() {
  const { t } = useTranslation()

  return (
    <AdminLayout>
      <section className="admin-card admin-placeholder">
        <p>{t('agenda.placeholder')}</p>
      </section>
    </AdminLayout>
  )
}

export default Agenda
