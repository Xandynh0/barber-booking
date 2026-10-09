import { useCallback, useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link } from 'react-router-dom'
import { getBusiness, listPublicServices } from '../api/publicBooking'
import { PublicHeader } from '../components/PublicHeader'
import { ArrowRightIcon, BarberPoleIcon, ClockIcon, MapPinIcon, PhoneIcon, ScissorsIcon } from '../components/PublicIcons'
import { formatPrice } from '../utils/publicFormat'
import '../styles/barber-theme.css'
import './public.css'

/**
 * Public homepage (docs/planejamento-barbearia-mvp.md, seção 2): the
 * barbershop's name and contact, and the services that can actually be
 * booked right now, straight from the API — no fictitious content. Same
 * identity as the booking journey (docs/design/old-barber.png), with the
 * catalog as responsive cards.
 */
function Home() {
  const { t, i18n } = useTranslation()
  const [business, setBusiness] = useState(null)
  const [services, setServices] = useState(null)
  const [servicesFailed, setServicesFailed] = useState(false)

  // Only sets state once the API answers; the retry button puts the list
  // back to "loading" itself (react-hooks set-state-in-effect).
  const fetchHome = useCallback(() => {
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
    fetchHome()
  }, [fetchHome])

  function reload() {
    setServicesFailed(false)
    setServices(null)
    fetchHome()
  }

  const shopName = business?.name || t('common.brand')

  return (
    <div className="public-shell barber-theme">
      <PublicHeader shopName={shopName} />

      <main className="home-page">
        <section className="home-hero theme-card" aria-labelledby="home-title">
          <BarberPoleIcon size={64} className="home-hero-pole" />
          <div className="home-hero-text">
            <h1 id="home-title" className="home-title">
              {shopName}
            </h1>
            <p className="home-tagline">{t('home.tagline')}</p>
            <div className="home-hero-actions">
              <Link className="primary-button" to="/agendar">
                {t('home.bookCta')}
                <ArrowRightIcon size={18} />
              </Link>
            </div>
            {business && (business.address || business.phone) && (
              <dl className="home-contact" aria-label={t('home.contactTitle')}>
                {business.address && (
                  <div>
                    <dt>
                      <MapPinIcon size={18} />
                      <span className="sr-only">{t('home.address')}</span>
                    </dt>
                    <dd>{business.address}</dd>
                  </div>
                )}
                {business.phone && (
                  <div>
                    <dt>
                      <PhoneIcon size={18} />
                      <span className="sr-only">{t('home.phone')}</span>
                    </dt>
                    <dd>{business.phone}</dd>
                  </div>
                )}
              </dl>
            )}
          </div>
        </section>

        <section className="home-catalog" aria-labelledby="home-services-title">
          <div className="home-catalog-header">
            <h2 id="home-services-title" className="home-section-title">
              {t('home.servicesTitle')}
            </h2>
            <p className="booking-intro">{t('home.servicesIntro')}</p>
          </div>

          {services === null && !servicesFailed && (
            <p role="status" className="booking-loading">
              {t('home.servicesLoading')}
            </p>
          )}

          {servicesFailed && (
            <div role="alert" className="public-notice public-notice--error">
              <p>{t('home.servicesError')}</p>
              <button type="button" className="secondary-button" onClick={reload}>
                {t('booking.actions.retry')}
              </button>
            </div>
          )}

          {services !== null && services.length === 0 && <p className="booking-hint">{t('home.servicesEmpty')}</p>}

          {services !== null && services.length > 0 && (
            <ul className="home-services">
              {services.map((service) => (
                <li key={service.id} className="home-service theme-card">
                  <span className="icon-bubble home-service-icon">
                    <ScissorsIcon size={22} />
                  </span>
                  <h3 className="home-service-name">{service.name}</h3>
                  {service.description && <p className="home-service-description">{service.description}</p>}
                  <p className="home-service-meta">
                    <span className="home-service-duration">
                      <ClockIcon size={16} />
                      {t('booking.durationMinutes', { count: service.duration_minutes })}
                    </span>
                    <span className="home-service-price">{formatPrice(service.price, i18n.language)}</span>
                  </p>
                  <Link className="secondary-button home-service-link" to={`/agendar?servico=${service.id}`}>
                    {t('home.bookThis')}
                    <span className="sr-only">: {service.name}</span>
                  </Link>
                </li>
              ))}
            </ul>
          )}
        </section>

        <p className="home-footnote">
          <Link to="/admin/login">{t('home.adminLink')}</Link>
        </p>
      </main>
    </div>
  )
}

export default Home
