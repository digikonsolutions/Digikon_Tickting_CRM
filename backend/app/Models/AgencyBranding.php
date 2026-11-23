<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One row per agency. `agency_id` is never mass-assignable: it always comes from the authenticated user. */
class AgencyBranding extends Model
{
    protected $table = 'agency_branding';

    public const THEME_MODES = ['light', 'dark', 'system'];

    public const SIDEBAR_STYLES = ['light', 'brand'];

    public const CORNER_STYLES = ['soft', 'rounded', 'sharp'];

    /** asset kind (URL segment) => column */
    public const ASSET_COLUMNS = [
        'logo' => 'logo_path',
        'logo-collapsed' => 'logo_collapsed_path',
        'favicon' => 'favicon_path',
    ];

    protected $fillable = [
        'display_name',
        'tagline',
        'primary_color',
        'accent_color',
        'theme_mode',
        'sidebar_style',
        'corner_style',
        'welcome_message',
        'support_email',
        'email_from_name',
        // Cloudinary fields
        'logo_public_id',
        'logo_url',
        'logo_collapsed_public_id',
        'logo_collapsed_url',
        'favicon_public_id',
        'favicon_url',
    ];

    public function agency(): BelongsTo
    {
        return $this->belongsTo(Agency::class);
    }
}
