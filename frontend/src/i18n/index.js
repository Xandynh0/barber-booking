import i18n from 'i18next'
import { initReactI18next } from 'react-i18next'
import en from './locales/en.json'
import ptBR from './locales/pt-BR.json'

export const SUPPORTED_LANGUAGES = ['pt-BR', 'en']
export const STORAGE_KEY = 'barber-booking-language'

/**
 * Only an explicit, previously-saved choice is read back from storage — the
 * browser-language heuristic below runs fresh on every visit until the user
 * actually picks a language (docs/desenvolvimento.md, "Idiomas (PT/EN)").
 */
function readSavedLanguage() {
  try {
    const saved = window.localStorage.getItem(STORAGE_KEY)

    return SUPPORTED_LANGUAGES.includes(saved) ? saved : null
  } catch {
    // localStorage unavailable (privacy mode, disabled storage, etc).
    return null
  }
}

function detectBrowserLanguage() {
  const candidates = navigator.languages?.length ? navigator.languages : [navigator.language ?? '']
  const prefersEnglish = candidates.some((lang) => lang?.toLowerCase().startsWith('en'))

  return prefersEnglish ? 'en' : 'pt-BR'
}

export function resolveInitialLanguage() {
  return readSavedLanguage() ?? detectBrowserLanguage()
}

export function persistLanguage(language) {
  try {
    window.localStorage.setItem(STORAGE_KEY, language)
  } catch {
    // Ignored — the language still applies for this session, it just won't
    // survive a reload.
  }
}

i18n.use(initReactI18next).init({
  resources: {
    'pt-BR': { translation: ptBR },
    en: { translation: en },
  },
  lng: resolveInitialLanguage(),
  fallbackLng: 'pt-BR',
  interpolation: { escapeValue: false },
  returnNull: false,
})

function syncHtmlLang(language) {
  document.documentElement.lang = language
}

syncHtmlLang(i18n.language)
i18n.on('languageChanged', syncHtmlLang)

export default i18n
