<?php

namespace App\Services;

use App\Models\Agency;
use App\Models\AgencyBranding;
use App\Services\CloudinaryUploader;
use Illuminate\Http\UploadedFile;
use RuntimeException;

/**
 * Agency white‑label settings. Files are now stored in Cloudinary.
 */
class AgencyBrandingService
{
    /** Resolved on first use, so reading branding (every /auth/me) never needs Cloudinary credentials. */
    private ?CloudinaryUploader $uploader = null;

    private function uploader(): CloudinaryUploader
    {
        return $this->uploader ??= app(CloudinaryUploader::class);
    }

    /** Colour defaults when an agency has not chosen one. */
    public const DEFAULTS = [
        'primary_color' => null,
        'accent_color' => null,
        'theme_mode'   => 'light',
        'sidebar_style'=> 'light',
        'corner_style' => 'rounded',
    ];

    public function forAgency(Agency $agency): AgencyBranding
    {
        // Ensure a branding row exists.
        return $agency->branding()->firstOrCreate([]);
    }

    /** @return array<string, mixed> */
    public function payload(Agency $agency): array
    {
        $b = $agency->relationLoaded('branding') ? $agency->branding : $agency->branding()->first();
        $b ??= new AgencyBranding(self::DEFAULTS);

        // Cloudinary URLs are stored directly; fall back to null if missing.
        return [
            'agency_name'          => $agency->name,
            'display_name'         => $b->display_name,
            'tagline'              => $b->tagline,
            'welcome_message'      => $b->welcome_message,
            'support_email'        => $b->support_email,
            'email_from_name'      => $b->email_from_name,
            'slug'                 => $agency->slug,
            'logo_url'             => $b->logo_url ?? null,
            'logo_collapsed_url'   => $b->logo_collapsed_url ?? null,
            'favicon_url'          => $b->favicon_url ?? null,
            'primary_color'        => $b->primary_color,
            'accent_color'         => $b->accent_color,
            'theme_mode'           => $b->theme_mode ?? self::DEFAULTS['theme_mode'],
            'sidebar_style'        => $b->sidebar_style ?? self::DEFAULTS['sidebar_style'],
            'corner_style'         => $b->corner_style ?? self::DEFAULTS['corner_style'],
            'updated_at'           => $b->updated_at?->toIso8601String(),
        ];
    }

    /** @param array<string, mixed> $data */
    public function update(Agency $agency, array $data): AgencyBranding
    {
        $b = $this->forAgency($agency);
        $b->fill($data)->save();
        return $b;
    }

    /** Upload branding asset to Cloudinary. */
    public function storeAsset(Agency $agency, string $kind, UploadedFile $file): AgencyBranding
    {
        $column = AgencyBranding::ASSET_COLUMNS[$kind];
        $b = $this->forAgency($agency);

        // Validate the uploaded file first.
        if (! $file->isValid()) {
            \Log::error('Branding upload failed: uploaded file is invalid');
            return $b; // early exit – keep previous branding.
        }

        // Determine folder inside Cloudinary for this agency.
        $folder = "agency-branding/{$agency->id}";
        try {
            // Logos often come with a wide blank canvas around the mark; trim uniform borders so the mark
            // fills the sidebar slot. The favicon is left as drawn.
            $options = $kind === 'favicon' ? [] : ['transformation' => ['effect' => 'trim:10']];
            $result = $this->uploader()->upload($file, $folder, $options);
        } catch (\Throwable $e) {
            // Log the failure without exposing details to the client.
            \Log::error('Branding upload exception: ' . get_class($e) . ' – ' . $e->getMessage());
            return $b;
        }

        // Determine the DB columns for public_id and url.
        $publicIdColumn = str_replace('_path', '_public_id', $column);
        $urlColumn = str_replace('_path', '_url', $column);

        // Delete previous Cloudinary asset if it exists.
        $oldPublicId = $b->{$publicIdColumn};
        if ($oldPublicId) {
            $this->uploader()->delete($oldPublicId);
        }

        // Save new data (legacy path column cleared).
        $b->forceFill([
            $column => null,
            $publicIdColumn => $result['public_id'] ?? null,
            $urlColumn => $result['secure_url'] ?? null,
        ])->save();

        return $b;
    }

    /** Remove branding asset from Cloudinary. */
    public function removeAsset(Agency $agency, string $kind): AgencyBranding
    {
        $column = AgencyBranding::ASSET_COLUMNS[$kind];
        $b = $this->forAgency($agency);

        $publicIdColumn = str_replace('_path', '_public_id', $column);
        $urlColumn = str_replace('_path', '_url', $column);

        $oldPublicId = $b->{$publicIdColumn};
        if ($oldPublicId) {
            $this->uploader()->delete($oldPublicId);
        }

        $b->forceFill([
            $column => null,
            $publicIdColumn => null,
            $urlColumn => null,
        ])->save();

        return $b;
    }

    /** Reset branding to defaults and delete all Cloudinary assets. */
    public function reset(Agency $agency): AgencyBranding
    {
        $b = $this->forAgency($agency);

        // Delete all Cloudinary assets.
        foreach (AgencyBranding::ASSET_COLUMNS as $col) {
            $publicIdColumn = str_replace('_path', '_public_id', $col);
            $oldPublicId = $b->{$publicIdColumn};
            if ($oldPublicId) {
                $this->uploader()->delete($oldPublicId);
            }
        }

        // Clear DB columns.
        $clear = [];
        foreach (AgencyBranding::ASSET_COLUMNS as $col) {
            $clear[$col] = null;
            $clear[str_replace('_path', '_public_id', $col)] = null;
            $clear[str_replace('_path', '_url', $col)] = null;
        }
        $b->forceFill(array_merge([
            'display_name' => null,
            'tagline' => null,
            'welcome_message' => null,
        ], $clear, self::DEFAULTS))->save();

        return $b;
    }
}
?>