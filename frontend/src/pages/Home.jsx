import { useCallback, useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link } from 'react-router-dom'
import { getBusiness, listPublicServices } from '../api/publicBooking'
import { PublicFooter, PublicHeader } from '../components/PublicHeader'
import { ArrowRightIcon, BarberPoleIcon, ClockIcon, MapPinIcon, PhoneIcon } from '../components/PublicIcons'
import { formatPrice } from '../utils/publicFormat'
import './public.css'

const HOW_STEPS = ['service', 'professional', 'time']

/**
 * Public homepage (docs/planejamento-barbearia-mvp.md, seção 2): the
 * barbershop's name and contact, how booking works, and the services that
 * can actually be booked right now, straight from the API — no fictitious
 * content. The catalog reads as a barbershop price board: name, dotted
 * leader, price, then duration and the way into the booking journey.
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
    <div className="public-shell">
      <PublicHeader shopName={shopName} />

      <main id="conteudo" className="home-page" tabIndex={-1}>
        <section className="home-hero" aria-labelledby="home-title">
          <div className="home-hero-text">
            <p className="eyebrow">{t('home.eyebrow')}</p>
            <h1 id="home-title" className="home-title">
              {shopName}
            </h1>
            <p className="home-tagline">{t('home.tagline')}</p>
            <div className="home-hero-actions">
              <Link className="primary-button" to="/agendar">
                {t('home.bookCta')}
                <ArrowRightIcon size={18} />
              </Link>
              <a className="quiet-button" href="#servicos">
                {t('home.seeServices')}
              </a>
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

          <aside className="home-signboard on-ink" aria-labelledby="home-how-title">
            <BarberPoleIcon size={56} className="home-signboard-pole" />
            <h2 id="home-how-title" className="home-signboard-title">
              {t('home.howTitle')}
            </h2>
            <ol className="home-steps">
              {HOW_STEPS.map((step) => (
                <li key={step}>
                  <strong>{t(`home.how.${step}`)}</strong>
                  <span>{t(`home.how.${step}Text`)}</span>
                </li>
              ))}
            </ol>
          </aside>
        </section>

        <section id="servicos" className="home-catalog" aria-labelledby="home-services-title">
          <header className="home-catalog-header">
            <p className="eyebrow">{t('home.servicesEyebrow')}</p>
            <h2 id="home-services-title" className="home-section-title">
              {t('home.servicesTitle')}
            </h2>
            <p className="home-catalog-intro">{t('home.servicesIntro')}</p>
          </header>

          {services === null && !servicesFailed && (
            <p role="status" className="loading-state">
              {t('home.servicesLoading')}
            </p>
          )}

          {servicesFailed && (
            <div role="alert" className="notice notice--error">
              <p>{t('home.servicesError')}</p>
              <button type="button" className="secondary-button" onClick={reload}>
                {t('booking.actions.retry')}
              </button>
            </div>
          )}

          {services !== null && services.length === 0 && (
            <p className="empty-state">
              <span className="empty-state-title">{t('home.servicesEmpty')}</span>
            </p>
          )}

          {services !== null && services.length > 0 && (
            <ul className="price-list">
              {services.map((service) => (
                <li key={service.id} className="price-item">
                  <div className="price-item-head">
                    <h3 className="price-item-name">{service.name}</h3>
                    <span className="price-item-leader" aria-hidden="true" />
                    <span className="price-item-price">{formatPrice(service.price, i18n.language)}</span>
                  </div>
                  {service.description && <p className="price-item-description">{service.description}</p>}
                  <div className="price-item-foot">
                    <span className="price-item-duration">
                      <ClockIcon size={16} />
                      {t('booking.durationMinutes', { count: service.duration_minutes })}
                    </span>
                    <Link className="price-item-link" to={`/agendar?servico=${service.id}`}>
                      {t('home.bookThis')}
                      <span className="sr-only">: {service.name}</span>
                      <ArrowRightIcon size={16} />
                    </Link>
                  </div>
                </li>
              ))}
            </ul>
          )}
        </section>
      </main>

      <PublicFooter shopName={shopName} business={business} />
    </div>
  )
}

export default Home
