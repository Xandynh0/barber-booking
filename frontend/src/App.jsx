import { useEffect, useState } from 'react'
import { fetchHealth } from './api/health'
import './App.css'

function statusLabel(state) {
  if (state === 'checking') return 'Verificando...'
  if (state === 'online') return 'Conectado'
  return 'Indisponível'
}

function App() {
  const [state, setState] = useState('checking')
  const [detail, setDetail] = useState('')

  useEffect(() => {
    let cancelled = false

    fetchHealth()
      .then((body) => {
        if (cancelled) return
        setState('online')
        setDetail(`API e banco de dados respondendo (${body.status}).`)
      })
      .catch((error) => {
        if (cancelled) return
        setState('offline')
        setDetail(error.message)
      })

    return () => {
      cancelled = true
    }
  }, [])

  return (
    <main className="page">
      <h1 className="brand">Barber Booking</h1>
      <p className="tagline">Tradição no estilo. Simplicidade na agenda.</p>

      <hr className="rule" />

      <div className="status-card">
        <span className="status-label">Status da API</span>
        <span className="status-value" data-state={state}>
          <span className="status-dot" aria-hidden="true" />
          {statusLabel(state)}
        </span>
        {detail && <p className="status-detail">{detail}</p>}
      </div>

      <p className="footnote">
        Fundação técnica em construção — sem agendamentos reais nesta etapa.
      </p>
    </main>
  )
}

export default App
