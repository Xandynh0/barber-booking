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
];
