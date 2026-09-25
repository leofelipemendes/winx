<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ResetPasswordNotification extends Notification
{
    public function __construct(public readonly string $token) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Recuperação de senha')
            ->line('Use o token abaixo para redefinir sua senha:')
            ->line($this->token)
            ->line('Informe esse token, seu email, a nova senha e sua confirmação no fluxo de redefinição de senha.')
            ->line('O token expira em '.config('auth.passwords.users.expire').' minutos.')
            ->line('Se você não solicitou a recuperação, ignore esta mensagem.');
    }
}
