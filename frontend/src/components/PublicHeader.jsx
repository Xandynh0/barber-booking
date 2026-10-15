import { useTranslation } from 'react-i18next'
import { Link } from 'react-router-dom'
import { LanguageSwitcher } from './LanguageSwitcher'
import { ScissorsIcon } from './PublicIcons'

/**
 * Ink bar of the public pages: the barber-pole band on top, the scissors
 * mark and the shop's name in the signboard serif, a short line about
 * booking and the language switch. The skip link is the first thing a
 * keyboard user reaches; every public page names its <main> "conteudo".
 */
export function PublicHeader({ shopName }) {
  const { t } = useTranslation()

  return (
    <header className="public-header on-ink">
      <a className="skip-link" href="#conteudo">
        {t('common.skipToContent')}
      </a>
      <div className="pole-stripe" aria-hidden="true" />
      <div className="public-header-inner">
        <Link to="/" className="public-brand">
          <ScissorsIcon size={24} className="public-brand-icon" />
          <span>{shopName}</span>
        </Link>
        <span className="public-header-tagline">{t('booking.headerTagline')}</span>
        <LanguageSwitcher tone="dark" />
      </div>
    </header>
  )
}

/**
 * Ink footer shared by the public pages: the shop's name and contact (when
 * the API has them) and the way into the administrator area.
 */
export function PublicFooter({ shopName, business }) {
  const { t } = useTranslation()

  return (
    <footer className="public-footer on-ink">
      <div className="public-footer-inner">
        <p className="public-footer-brand">{shopName}</p>
        {business && (business.address || business.phone) && (
          <p className="public-footer-contact">
            {[business.address, business.phone].filter(Boolean).join(' · ')}
          </p>
        )}
        <Link className="public-footer-admin" to="/admin/login">
          {t('home.adminLink')}
        </Link>
      </div>
    </footer>
  )
}
