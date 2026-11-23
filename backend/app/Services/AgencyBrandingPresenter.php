<?php

namespace App\Services;

use App\Models\Agency;

/**
 * The ONLY brand fields an agency user (or an anonymous visitor of a portal) may ever receive. Deliberately no agency
 * id, status, counts or contract data: this payload is also served without authentication.
 */
class AgencyBrandingPresenter
{
    /** @return array{slug: string, display_name: string, tagline: string|null, logo_url: string|null, favicon_url: string|null, primary_color: string|null, support_email: string|null} */
    public static function present(Agency $agency): array
    {
        $b = $agency->relationLoaded('branding') ? $agency->branding : $agency->branding()->first();

        return [
            'slug' => (string) $agency->slug,
            'display_name' => filled($b?->display_name) ? $b->display_name : $agency->name,
            'tagline' => $b?->tagline,
            'logo_url' => $b?->logo_url,
            'favicon_url' => $b?->favicon_url,
            'primary_color' => $b?->primary_color,
            'support_email' => $b?->support_email,
        ];
    }

    /** The name an agency user sees for their own agency. */
    public static function displayName(Agency $agency): string
    {
        return self::present($agency)['display_name'];
    }
}
