<?php

namespace App\Support;

use App\Models\User;
use App\Services\AgencyBrandingPresenter;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * The brand an email is sent under. Agency users get their own agency's name, logo and colour, a sender name, a
 * reply-to and the portal address; everyone else gets the ClientHub platform brand (null here, the templates fall back).
 *
 * EXTENSION POINT (not implemented): per-agency verified sender domains. When an agency verifies a domain, return its
 * address as `from_address` below and use it in apply(); until then every brand sends from the platform address, with
 * only the display name changed, so SPF/DKIM keep passing.
 */
class MailBrand
{
    /** @return array{name: string, logo_url: string|null, color: string|null, url: string, slug: string, from_name: string, reply_to: string|null}|null */
    public static function for(object $notifiable): ?array
    {
        if (! $notifiable instanceof User || ! $notifiable->role->isAgencyRole()) {
            return null;
        }
        $agency = $notifiable->agency;
        if ($agency === null) {
            return null;
        }
        $brand = AgencyBrandingPresenter::present($agency);
        $row = $agency->branding;

        return [
            'name' => $brand['display_name'],
            'logo_url' => $brand['logo_url'],
            'color' => $brand['primary_color'],
            'url' => self::portalUrl($brand['slug']),
            'slug' => $brand['slug'],
            'from_name' => filled($row?->email_from_name) ? $row->email_from_name : $brand['display_name'],
            'reply_to' => $brand['support_email'],
        ];
    }

    public static function portalUrl(string $slug, string $path = ''): string
    {
        return rtrim((string) config('app.frontend_url'), '/')."/a/{$slug}".$path;
    }

    /** Applies the brand to a mail message: template data, sender name and reply-to. */
    public static function apply(MailMessage $message, ?array $brand): MailMessage
    {
        if ($brand === null) {
            return $message;
        }
        $message->viewData = [...$message->viewData, 'brand' => $brand];
        $message->from((string) config('mail.from.address'), $brand['from_name']);
        if ($brand['reply_to']) {
            $message->replyTo($brand['reply_to'], $brand['name']);
        }

        return $message;
    }
}
