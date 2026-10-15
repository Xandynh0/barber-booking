import { useEffect, useId, useRef } from 'react'
import { createPortal } from 'react-dom'
import { CloseIcon } from './PublicIcons'
import './Modal.css'

const FOCUSABLE = 'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])'

/**
 * Accessible dialog: `role="dialog"` + `aria-modal`, labelled by its
 * title; focus moves into it on open, Tab stays inside, Escape and the
 * close button call `onClose`, and focus returns to whatever opened it.
 * Keys are listened to on the document, not on the dialog: when the
 * focused control disappears (a confirmation step that closes, a button
 * replaced by its result) focus falls back to <body>, and Escape and Tab
 * must still work from there.
 * Rendered in a portal; the design tokens are global, so it looks the
 * same wherever it was opened from.
 */
export function Modal({ title, onClose, closeLabel, children, size = 'medium', initialFocusRef }) {
  const titleId = useId()
  const dialogRef = useRef(null)
  const onCloseRef = useRef(onClose)

  useEffect(() => {
    onCloseRef.current = onClose
  }, [onClose])

  useEffect(() => {
    const opener = document.activeElement
    const dialog = dialogRef.current
    const target = initialFocusRef?.current ?? dialog.querySelector(FOCUSABLE) ?? dialog
    target.focus()

    function onKeyDown(event) {
      if (event.key === 'Escape') {
        event.stopPropagation()
        onCloseRef.current()
        return
      }
      if (event.key !== 'Tab') return

      const focusable = [...dialog.querySelectorAll(FOCUSABLE)]
      if (focusable.length === 0) {
        event.preventDefault()
        return
      }
      const first = focusable[0]
      const last = focusable[focusable.length - 1]
      if (!dialog.contains(document.activeElement)) {
        event.preventDefault()
        const target = event.shiftKey ? last : first
        target.focus()
      } else if (event.shiftKey && document.activeElement === first) {
        event.preventDefault()
        last.focus()
      } else if (!event.shiftKey && document.activeElement === last) {
        event.preventDefault()
        first.focus()
      }
    }

    document.addEventListener('keydown', onKeyDown)
    return () => {
      document.removeEventListener('keydown', onKeyDown)
      if (opener && typeof opener.focus === 'function' && document.contains(opener)) opener.focus()
    }
    // Runs once per opening: the initial focus must not jump on re-render.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [])

  return createPortal(
    <div className="modal-root">
      <div className="modal-backdrop" onClick={() => onCloseRef.current()} aria-hidden="true" />
      <div
        ref={dialogRef}
        className={`modal-dialog modal-dialog--${size}`}
        role="dialog"
        aria-modal="true"
        aria-labelledby={titleId}
        tabIndex={-1}
      >
        <div className="modal-header">
          <h2 id={titleId} className="modal-title">
            {title}
          </h2>
          <button type="button" className="modal-close" onClick={() => onCloseRef.current()} aria-label={closeLabel}>
            <CloseIcon size={20} />
          </button>
        </div>
        <div className="modal-body">{children}</div>
      </div>
    </div>,
    document.body
  )
}
