import '@testing-library/jest-dom/vitest'
import { afterEach } from 'vitest'
import i18n from './i18n'

// jsdom's default navigator.language ("en-US") would otherwise make every
// test run in English via detectInitialLanguage(), breaking every existing
// assertion written against the Portuguese default. Force pt-BR before each
// test (and reset it after), so language-switching tests that explicitly
// call i18n.changeLanguage('en') never leak into the next test.
i18n.changeLanguage('pt-BR')

afterEach(() => {
  i18n.changeLanguage('pt-BR')
})
