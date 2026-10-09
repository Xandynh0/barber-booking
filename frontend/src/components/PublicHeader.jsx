import { useTranslation } from 'react-i18next'
import { Link } from 'react-router-dom'
import { LanguageSwitcher } from './LanguageSwitcher'
import { ScissorsIcon } from './PublicIcons'

/**
 * Dark header of the public pages, after the approved reference
 * (docs/design/old-barber.png): scissors mark, brand in the display serif,
 * a brass divider and a short line about booking.
 */
export function PublicHeader({ shopName }) {
  const { t } = useTranslation()

  return (
    <header className="public-header">
      <div className="public-header-inner">
        <Link to="/" className="public-brand">
          <ScissorsIcon size={26} className="public-brand-icon" />
          <span>{shopName}</span>
        </Link>
        <span className="public-header-divider" aria-hidden="true" />
        <span className="public-header-tagline">{t('booking.headerTagline')}</span>
        <LanguageSwitcher tone="dark" />
      </div>
    </header>
  )
}
