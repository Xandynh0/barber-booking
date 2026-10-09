<?php

/*
|--------------------------------------------------------------------------
| E-mail de confirmação e páginas de cancelamento
|--------------------------------------------------------------------------
*/

return [
    'fields' => [
        'service' => 'Serviço',
        'professional' => 'Profissional',
        'date' => 'Data',
        'time' => 'Horário',
        'duration' => 'Duração',
        'price' => 'Preço',
        'address' => 'Endereço',
        'reference' => 'Código da reserva',
    ],
    'duration' => ':minutes min',

    'mail' => [
        'subject' => 'Reserva confirmada — :date',
        'heading' => 'Reserva confirmada',
        'greeting' => 'Olá, :name!',
        'intro' => 'Seu horário em :shop está confirmado. Confira os detalhes:',
        'cancel_intro' => 'Se não puder comparecer, cancele pelo botão abaixo. Abrir o link só mostra a reserva; o cancelamento acontece quando você confirmar na página.',
        'cancel_button' => 'Cancelar reserva',
        'cancel_note' => 'O link é pessoal e vale até o início do atendimento. Não o compartilhe.',
        'contact' => 'Dúvidas? Fale com a barbearia: :phone.',
        'signature' => 'Até breve,',
    ],

    'cancel' => [
        'title' => 'Cancelar reserva',
        'heading' => 'Cancelar reserva?',
        'question' => 'Confira os dados antes de confirmar. Esta ação não pode ser desfeita.',
        'button' => 'Confirmar cancelamento',
        'keep' => 'Manter reserva',
        'done_title' => 'Reserva cancelada',
        'done' => 'O horário foi liberado.',
        'book_again' => 'Agendar novamente',
        'home' => 'Ir para a página inicial',
        'already_title' => 'Reserva já cancelada',
        'already' => 'Esta reserva já está cancelada.',
        'deadline_title' => 'Prazo de cancelamento encerrado',
        'deadline_passed' => 'O prazo para cancelar esta reserva pelo link já terminou. Se precisar, fale com a barbearia.',
        'error_title' => 'Link indisponível',
        'invalid_link' => 'Este link de cancelamento é inválido ou expirou. Se precisar de ajuda, fale com a barbearia.',
        'form_expired' => 'A página ficou aberta por muito tempo. Abra o link do e-mail novamente para cancelar.',
        'too_many_attempts' => 'Muitas tentativas em pouco tempo. Aguarde um instante e tente novamente.',
    ],
];
