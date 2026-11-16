<?php

namespace App\Notifications;

use App\Enums\Role;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Notifications\Messages\MailMessage;

class SetPasswordNotification extends ResetPassword
{
    private bool $isEmployee = false;

    public function __construct($token, public bool $invite = false)
    {
        parent::__construct($token);
    }

    public function toMail($notifiable): MailMessage
    {
        $this->isEmployee = $this->invite && $notifiable->role === Role::Employee;

        return parent::toMail($notifiable);
    }

    protected function buildMailMessage($url): MailMessage
    {
        if ($this->invite) {
            $url .= (str_contains($url, '?') ? '&' : '?').'invite=1';
        }

        $message = (new MailMessage)
            ->subject('Set your password')
            ->line('Use the button below to set or reset your password.')
            ->action('Set your password', $url)
            ->line('This link expires in '.config('auth.passwords.'.config('auth.defaults.passwords').'.expire').' minutes.');

        if ($this->isEmployee) {
            $message->line('Please note: your work activity in ClientHub (sign-ins, ticket updates and active time) is recorded for administrators. Keystrokes, screens and page content are never recorded.');
        }

        return $message->line('If you did not expect this email, you can ignore it.');
    }
}
