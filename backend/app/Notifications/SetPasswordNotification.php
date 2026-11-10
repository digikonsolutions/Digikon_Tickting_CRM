<?php

namespace App\Notifications;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Notifications\Messages\MailMessage;

class SetPasswordNotification extends ResetPassword
{
    public function __construct($token, public bool $invite = false)
    {
        parent::__construct($token);
    }

    protected function buildMailMessage($url): MailMessage
    {
        if ($this->invite) {
            $url .= (str_contains($url, '?') ? '&' : '?').'invite=1';
        }

        return (new MailMessage)
            ->subject('Set your password')
            ->line('Use the button below to set or reset your password.')
            ->action('Set your password', $url)
            ->line('This link expires in '.config('auth.passwords.'.config('auth.defaults.passwords').'.expire').' minutes.')
            ->line('If you did not expect this email, you can ignore it.');
    }
}
