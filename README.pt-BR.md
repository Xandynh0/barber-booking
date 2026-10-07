# Barber Booking

> 🚧 Em desenvolvimento.

Sistema administrativo para uma barbearia: cadastro de serviços e profissionais, expediente semanal e bloqueios de agenda (incluindo fechamento completo da barbearia), com autenticação administrativa por sessão. Disponível em português (Brasil) e inglês.

## Tecnologias

- React (JavaScript) + Vite, react-i18next
- Laravel (PHP), Sanctum (autenticação por sessão)
- MySQL
- Docker

## Já implementado

- Login administrativo (sessão/cookie, CSRF)
- Cadastro de serviços e profissionais, com vínculo profissional↔serviço
- Expediente semanal por profissional
- Bloqueios de agenda — um profissional específico ou o fechamento completo da barbearia
- Interface em português (Brasil) e inglês

Ainda não implementado: agendamento público, motor de disponibilidade, reservas, cancelamento ou notificações por e-mail.

Instruções de desenvolvimento, notas de arquitetura e cobertura de testes: veja [`docs/desenvolvimento.md`](docs/desenvolvimento.md).

Read in English: [README.md](README.md)
