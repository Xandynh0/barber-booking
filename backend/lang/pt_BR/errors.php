<?php

/*
|--------------------------------------------------------------------------
| Mensagens de erro da API
|--------------------------------------------------------------------------
|
| `error.code` (ex.: "NOT_FOUND") é o contrato estável que o frontend usa
| para decidir o que fazer — nunca muda com o idioma. Só `error.message`
| (texto aqui) é traduzido, para humanos lerem.
|
*/

return [
    'unauthenticated' => 'Autenticação necessária.',
    'invalid_credentials' => 'E-mail ou senha inválidos.',
    'validation_error' => 'Dados inválidos.',
    'not_found' => 'Registro não encontrado.',
    'session_expired' => 'Sessão expirada. Entre novamente.',
    'rate_limited' => 'Muitas tentativas. Tente novamente em instantes.',
    'service_ids_missing' => 'Um ou mais serviços informados não existem.',
    'professional_not_found' => 'Profissional informado não existe.',
    'period_end_before_start' => 'O fim deve ser depois do início.',
    'periods_overlap' => 'Períodos do mesmo dia não podem se sobrepor.',
    'service_unavailable' => 'Serviço indisponível no momento.',
    'availability_service_unavailable' => 'Serviço indisponível para agendamento.',
    'availability_professional_unavailable' => 'Profissional indisponível para agendamento.',
    'availability_service_not_offered' => 'Este profissional não realiza o serviço escolhido.',
    'slot_unavailable' => 'Esse horário não está mais disponível. Escolha outro.',
    'contact_limit_reached' => 'Há um limite de reservas futuras por contato. Para agendar mais, fale com a barbearia.',
    'idempotency_key_reused' => 'Esta chave de idempotência já foi usada em outra reserva. Gere uma nova chave para uma nova reserva.',
    'invalid_phone' => 'Informe um telefone válido com DDD (ex.: +55 11 99999-9999).',
    'starts_at_needs_offset' => 'Informe o início com data, hora e fuso horário (ISO 8601, ex.: 2026-11-03T10:00:00-03:00).',
    'appointment_conflict_block' => 'Já existem reservas confirmadas neste intervalo: :list. Cancele-as antes de bloquear o horário.',
    'appointment_conflict_working_hours' => 'O novo expediente deixaria reservas futuras fora do horário: :list. Cancele-as antes de reduzir o expediente.',
];
