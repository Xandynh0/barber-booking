import { useTranslation } from 'react-i18next'
import { SUPPORTED_LANGUAGES, persistLanguage } from '../i18n'
import './LanguageSwitcher.css'

/**
 * Pure in-memory language change — i18next re-renders everything subscribed
 * via useTranslation(), no page reload, no effect on session/CSRF/form
 * state. Labels are always "Português"/"English" regardless of the current
 * language (so a reader can always find the OTHER option), never flags.
 */
export function LanguageSwitcher() {
  const { t, i18n } = useTranslation()

  return (
    <div className="language-switcher" role="group" aria-label={t('common.languageSwitcher.ariaLabel')}>
      {SUPPORTED_LANGUAGES.map((language) => (
        <button
          key={language}
          type="button"
          className={`language-switcher-option${i18n.language === language ? ' language-switcher-option--active' : ''}`}
          aria-pressed={i18n.language === language}
          onClick={() => {
            i18n.changeLanguage(language)
            persistLanguage(language)
          }}
        >
          {t(`common.languageSwitcher.${language === 'pt-BR' ? 'pt' : 'en'}`)}
        </button>
      ))}
    </div>
  )
}
