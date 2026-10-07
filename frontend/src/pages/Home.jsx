import { useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { fetchHealth } from '../api/health'
import { LanguageSwitcher } from '../components/LanguageSwitcher'
import '../App.css'

function Home() {
  const { t } = useTranslation()
  const [state, setState] = useState('checking')
  const [detail, setDetail] = useState('')

  useEffect(() => {
    let cancelled = false

    fetchHealth()
      .then((body) => {
        if (cancelled) return
        setState('online')
        setDetail(t('home.statusDetailOnline', { status: body.status }))
      })
      .catch((error) => {
        if (cancelled) return
        setState('offline')
        setDetail(error.message)
      })

    return () => {
      cancelled = true
    }
  }, [t])

  const statusLabel = { checking: t('home.statusChecking'), online: t('home.statusOnline'), offline: t('home.statusOffline') }[
    state
  ]

  return (
    <main className="page">
      <div className="page-top-bar">
        <LanguageSwitcher />
      </div>

      <h1 className="brand">{t('common.brand')}</h1>
      <p className="tagline">{t('home.tagline')}</p>

      <hr className="rule" />

      <div className="status-card">
        <span className="status-label">{t('home.apiStatusLabel')}</span>
        <span className="status-value" data-state={state}>
          <span className="status-dot" aria-hidden="true" />
          {statusLabel}
        </span>
        {detail && <p className="status-detail">{detail}</p>}
      </div>

      <p className="footnote">{t('home.footnote')}</p>
    </main>
  )
}

export default Home
