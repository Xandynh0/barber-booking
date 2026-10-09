<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Mensagens de validação
    |--------------------------------------------------------------------------
    |
    | Cobre só as regras realmente usadas pelos Form Requests desta
    | aplicação (backend/app/Http/Requests/Admin/*.php). Uma regra sem
    | entrada aqui recai no `fallback_locale` (en) configurado em
    | config/app.php, nunca num placeholder quebrado.
    |
    */

    'required' => 'O campo :attribute é obrigatório.',
    'required_if' => 'O campo :attribute é obrigatório quando :other é :value.',
    'prohibited_if' => 'O campo :attribute não deve ser informado quando :other é :value.',
    'string' => 'O campo :attribute deve ser um texto.',
    'integer' => 'O campo :attribute deve ser um número inteiro.',
    'numeric' => 'O campo :attribute deve ser um número.',
    'boolean' => 'O campo :attribute deve ser verdadeiro ou falso.',
    'array' => 'O campo :attribute deve ser uma lista.',
    'distinct' => 'O campo :attribute tem um valor duplicado.',
    'present' => 'O campo :attribute deve estar presente.',
    'date' => 'O campo :attribute deve ser uma data válida.',
    'date_format' => 'O campo :attribute deve estar no formato :format.',
    'after' => 'O campo :attribute deve ser uma data depois de :date.',
    'in' => 'O valor selecionado para :attribute é inválido.',
    'email' => 'O campo :attribute deve ser um e-mail válido.',
    'regex' => 'O formato do campo :attribute é inválido.',
    'max' => [
        'numeric' => 'O campo :attribute não pode ser maior que :max.',
        'string' => 'O campo :attribute não pode ter mais que :max caracteres.',
    ],
    'min' => [
        'numeric' => 'O campo :attribute deve ser pelo menos :min.',
        'string' => 'O campo :attribute deve ter pelo menos :min caracteres.',
    ],
    'between' => [
        'numeric' => 'O campo :attribute deve estar entre :min e :max.',
    ],
    'size' => [
        'array' => 'O campo :attribute deve ter :size itens.',
    ],

    /*
    |--------------------------------------------------------------------------
    | Nomes amigáveis dos campos
    |--------------------------------------------------------------------------
    */

    'attributes' => [
        'name' => 'nome',
        'description' => 'descrição',
        'duration_minutes' => 'duração em minutos',
        'price' => 'preço',
        'is_active' => 'ativo',
        'service_ids' => 'serviços',
        'service_ids.*' => 'serviço',
        'professional_id' => 'profissional',
        'scope' => 'alcance',
        'starts_at' => 'início',
        'ends_at' => 'fim',
        'reason' => 'motivo',
        'email' => 'e-mail',
        'password' => 'senha',
        'days' => 'dias',
        'days.*.weekday' => 'dia da semana',
        'days.*.periods' => 'períodos',
        'days.*.periods.*.start_time' => 'horário de início',
        'days.*.periods.*.end_time' => 'horário de fim',
    ],

];
