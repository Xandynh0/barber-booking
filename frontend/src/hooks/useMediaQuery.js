import { useSyncExternalStore } from 'react'

/**
 * Whether a CSS media query matches, kept in sync with the window. Where
 * `matchMedia` does not exist (tests, very old browsers) it reports false,
 * so the narrow-screen layout — which works everywhere — is the fallback.
 */
export function useMediaQuery(query) {
  return useSyncExternalStore(
    (onChange) => {
      if (typeof window.matchMedia !== 'function') return () => {}
      const list = window.matchMedia(query)
      list.addEventListener('change', onChange)
      return () => list.removeEventListener('change', onChange)
    },
    () => (typeof window.matchMedia === 'function' ? window.matchMedia(query).matches : false),
    () => false
  )
}
