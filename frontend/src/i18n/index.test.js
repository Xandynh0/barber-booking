import { afterEach, describe, expect, it, vi } from 'vitest'
import { resolveInitialLanguage, STORAGE_KEY } from './index'

function withBrowserLanguage(language) {
  vi.spyOn(window.navigator, 'language', 'get').mockReturnValue(language)
  vi.spyOn(window.navigator, 'languages', 'get').mockReturnValue(language === '' ? [] : [language])
}

describe('resolveInitialLanguage', () => {
  afterEach(() => {
    window.localStorage.clear()
    vi.restoreAllMocks()
  })

  it('uses a previously saved preference over everything else', () => {
    window.localStorage.setItem(STORAGE_KEY, 'en')
    withBrowserLanguage('pt-BR')

    expect(resolveInitialLanguage()).toBe('en')
  })

  it('falls back to English when the browser language starts with "en" and nothing is saved', () => {
    withBrowserLanguage('en-US')

    expect(resolveInitialLanguage()).toBe('en')
  })

  it('falls back to Portuguese for any non-English browser language', () => {
    withBrowserLanguage('fr-FR')

    expect(resolveInitialLanguage()).toBe('pt-BR')
  })

  it('ignores an unsupported saved value and falls through to browser detection', () => {
    window.localStorage.setItem(STORAGE_KEY, 'fr')
    withBrowserLanguage('fr-FR')

    expect(resolveInitialLanguage()).toBe('pt-BR')
  })

  it('falls back to Portuguese when there is no browser language at all', () => {
    withBrowserLanguage('')

    expect(resolveInitialLanguage()).toBe('pt-BR')
  })
})
