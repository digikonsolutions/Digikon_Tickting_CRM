<?php

namespace App\Notifications;

use App\Enums\Role;
use App\Services\MonitoringPolicy;
use App\Support\MailBrand;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Notifications\Messages\MailMessage;

class SetPasswordNotification extends ResetPassword
{
    private bool $isEmployee = false;

    /** The agency brand of the recipient (null for staff, who keep the ClientHub brand). */
    private ?array $brand = null;

    public function __construct($token, public bool $invite = false, public ?string $welcomeLine = null)
    {
        parent::__construct($token);
    }

    public function toMail($notifiable): MailMessage
    {
        $this->isEmployee = $this->invite && $notifiable->role === Role::Employee;
        $this->brand = MailBrand::for($notifiable);

        return parent::toMail($notifiable);
    }

    protected function buildMailMessage($url): MailMessage
    {
        if ($this->invite) {
            $url .= (str_contains($url, '?') ? '&' : '?').'invite=1';
        }

        $message = MailBrand::apply((new MailMessage)
            ->subject($this->brand ? 'Set your password – '.$this->brand['name'] : 'Set your password')
            ->line('Use the button below to set or reset your password.'), $this->brand);

        // An optional personal line from the administrator, shown above the button text.
        if ($this->invite && filled($this->welcomeLine)) {
            $message->line(trim($this->welcomeLine));
        }

        $message
            ->action('Set your password', $url)
            ->line('This link expires in '.config('auth.passwords.'.config('auth.defaults.passwords').'.expire').' minutes.');

        if ($this->isEmployee) {
            // The same text and version the employee accepts at first sign-in.
            foreach (app(MonitoringPolicy::class)->disclosureLines() as $line) {
                $message->line($line);
            }
        }

        $message->line('If you did not expect this email, you can ignore it.');
        if ($this->brand) {
            // Never "Regards, Laravel": the signature is the agency's own team.
            $message->salutation('The '.$this->brand['name'].' team');
        }

        return $message;
    }
}
