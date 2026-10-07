# Barber Booking

> 🚧 In development.

Admin system for a barbershop: service and professional catalogs, weekly working hours, and schedule blocks (including full barbershop closures), behind session-based admin authentication. Available in Portuguese (Brazil) and English.

## Technologies

- React (JavaScript) + Vite, react-i18next
- Laravel (PHP), Sanctum (session auth)
- MySQL
- Docker

## Implemented so far

- Admin login (session/cookie, CSRF)
- Services and professionals catalog, with professional↔service links
- Weekly working hours per professional
- Schedule blocks — a specific professional or a full barbershop closure
- Portuguese (BR) / English interface

Not yet implemented: public booking, an availability engine, appointments, cancellation, or e-mail notifications.

Development instructions, architecture notes, and test coverage: see [`docs/desenvolvimento.md`](docs/desenvolvimento.md).

Leia em português: [README.pt-BR.md](README.pt-BR.md)
