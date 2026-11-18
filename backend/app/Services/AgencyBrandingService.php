<?php

namespace App\Services;

use App\Models\Agency;
use App\Models\AgencyBranding;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Agency white-label settings. Every method takes the Agency (derived from the authenticated user by the
 * caller), never an id from request input. Files live on the public disk under agency-branding/{agency_id}/
 * with random names; the client's filename is never used.
 */
class AgencyBrandingService
{
    /** Colour defaults when an agency has not chosen one (the front end falls back to the ClientHub teal). */
    public const DEFAULTS = [
        'primary_color' => null,
        'accent_color' => null,
        'theme_mode' => 'light',
        'sidebar_style' => 'light',
        'corner_style' => 'rounded',
    ];

    public function forAgency(Agency $agency): AgencyBranding
    {
        // Through the relation so agency_id is set from the Agency, not mass-assigned.
        return $agency->branding()->firstOrCreate([]);
    }

    /** @return array<string, mixed> */
    public function payload(Agency $agency): array
    {
        $b = $agency->relationLoaded('branding') ? $agency->branding : $agency->branding()->first();
        $b ??= new AgencyBranding(self::DEFAULTS);

        $disk = Storage::disk('public');
        // Always absolute: the SPA may be served from another origin than the API/storage.
        $url = function (?string $path) use ($disk): ?string {
            if (! $path) {
                return null;
            }
            $u = $disk->url($path);

            return preg_match('#^https?://#i', $u) ? $u : rtrim((string) config('app.url'), '/').'/'.ltrim($u, '/');
        };

        return [
            'agency_name' => $agency->name,
            'display_name' => $b->display_name,
            'tagline' => $b->tagline,
            'welcome_message' => $b->welcome_message,
            'logo_url' => $url($b->logo_path),
            'logo_collapsed_url' => $url($b->logo_collapsed_path),
            'favicon_url' => $url($b->favicon_path),
            'primary_color' => $b->primary_color,
            'accent_color' => $b->accent_color,
            'theme_mode' => $b->theme_mode ?? self::DEFAULTS['theme_mode'],
            'sidebar_style' => $b->sidebar_style ?? self::DEFAULTS['sidebar_style'],
            'corner_style' => $b->corner_style ?? self::DEFAULTS['corner_style'],
            'updated_at' => $b->updated_at?->toIso8601String(),
        ];
    }

    /** @param array<string, mixed> $data validated text/colour/enum fields */
    public function update(Agency $agency, array $data): AgencyBranding
    {
        $b = $this->forAgency($agency);
        $b->fill($data)->save();

        return $b;
    }

    public function storeAsset(Agency $agency, string $kind, UploadedFile $file): AgencyBranding
    {
        $column = AgencyBranding::ASSET_COLUMNS[$kind];
        $b = $this->forAgency($agency);

        // The extension comes from the sniffed content, not from what the client called the file.
        $extension = match ($file->guessExtension()) {
            'png' => 'png',
            'jpg', 'jpeg' => 'jpg',
            'webp' => 'webp',
            'ico', 'cur' => 'ico',
            default => throw new RuntimeException('Unsupported image type.'),
        };

        $path = Storage::disk('public')->putFileAs(
            $this->directory($agency),
            $file,
            Str::random(40).'.'.$extension
        );
        if ($path === false) {
            throw new RuntimeException('The file could not be stored.');
        }

        $old = $b->{$column};
        $b->forceFill([$column => $path])->save();
        $this->deleteFile($old);

        return $b;
    }

    public function removeAsset(Agency $agency, string $kind): AgencyBranding
    {
        $column = AgencyBranding::ASSET_COLUMNS[$kind];
        $b = $this->forAgency($agency);

        $old = $b->{$column};
        $b->forceFill([$column => null])->save();
        $this->deleteFile($old);

        return $b;
    }

    /** Back to the ClientHub defaults: clears every field and deletes every stored file. */
    public function reset(Agency $agency): AgencyBranding
    {
        $b = $this->forAgency($agency);

        foreach (AgencyBranding::ASSET_COLUMNS as $column) {
            $this->deleteFile($b->{$column});
        }
        Storage::disk('public')->deleteDirectory($this->directory($agency));

        $b->forceFill([
            'display_name' => null,
            'tagline' => null,
            'welcome_message' => null,
            'logo_path' => null,
            'logo_collapsed_path' => null,
            'favicon_path' => null,
            ...self::DEFAULTS,
        ])->save();

        return $b;
    }

    private function directory(Agency $agency): string
    {
        return 'agency-branding/'.$agency->id;
    }

    private function deleteFile(?string $path): void
    {
        if ($path) {
            Storage::disk('public')->delete($path);
        }
    }
}
