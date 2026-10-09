import { useCallback, useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link } from 'react-router-dom'
import { getBusiness, listPublicServices } from '../api/publicBooking'
import { LanguageSwitcher } from '../components/LanguageSwitcher'
import { formatPrice } from '../utils/publicFormat'
import '../App.css'
import './public.css'

/**
 * Public homepage (docs/planejamento-barbearia-mvp.md, seção 2): the
 * barbershop's name and contact, and the services that can actually be
 * booked right now, straight from the API — no fictitious content.
 */
function Home() {
  const { t, i18n } = useTranslation()
  const [business, setBusiness] = useState(null)
  const [services, setServices] = useState(null)
  const [servicesFailed, setServicesFailed] = useState(false)

  const load = useCallback(() => {
    setServicesFailed(false)
    setServices(null)

    // The business details are a nicety on this page: if they fail, the
    // brand and the services still render.
    getBusiness()
      .then((body) => setBusiness(body.data))
      .catch(() => setBusiness(null))

    listPublicServices()
      .then((body) => setServices(body.data))
      .catch(() => setServicesFailed(true))
  }, [])

  useEffect(() => {
    load()
  }, [load])

  const shopName = business?.name || t('common.brand')

  return (
    <main className="page home-page">
      <div className="page-top-bar">
        <LanguageSwitcher />
      </div>

      <h1 className="brand">{shopName}</h1>
      <p className="tagline">{t('home.tagline')}</p>

      <Link className="cta-button" to="/agendar">
        {t('home.bookCta')}
      </Link>

      <hr className="rule" />

      <section className="home-section" aria-labelledby="home-services-title">
        <h2 id="home-services-title" className="home-section-title">
          {t('home.servicesTitle')}
        </h2>

        {services === null && !servicesFailed && <p role="status">{t('home.servicesLoading')}</p>}

        {servicesFailed && (
          <div role="alert" className="public-error">
            <p>{t('home.servicesError')}</p>
            <button type="button" className="secondary-button" onClick={load}>
              {t('booking.actions.retry')}
            </button>
          </div>
        )}

        {services !== null && services.length === 0 && <p>{t('home.servicesEmpty')}</p>}

        {services !== null && services.length > 0 && (
          <ul className="home-services">
            {services.map((service) => (
              <li key={service.id} className="home-service">
                <div className="home-service-main">
                  <span className="home-service-name">{service.name}</span>
                  {service.description && <span className="home-service-description">{service.description}</span>}
                </div>
                <div className="home-service-meta">
                  <span>{t('booking.durationMinutes', { count: service.duration_minutes })}</span>
                  <span className="home-service-price">{formatPrice(service.price, i18n.language)}</span>
                </div>
                <Link className="home-service-link" to={`/agendar?servico=${service.id}`}>
                  {t('home.bookThis')}
                  <span className="sr-only">: {service.name}</span>
                </Link>
              </li>
            ))}
          </ul>
        )}
      </section>

      {business && (business.address || business.phone) && (
        <section className="home-section" aria-labelledby="home-contact-title">
          <h2 id="home-contact-title" className="home-section-title">
            {t('home.contactTitle')}
          </h2>
          <dl className="home-contact">
            {business.address && (
              <>
                <dt>{t('home.address')}</dt>
                <dd>{business.address}</dd>
              </>
            )}
            {business.phone && (
              <>
                <dt>{t('home.phone')}</dt>
                <dd>{business.phone}</dd>
              </>
            )}
          </dl>
        </section>
      )}

      <p className="footnote">
        <Link to="/admin/login">{t('home.adminLink')}</Link>
      </p>
    </main>
  )
}

export default Home
