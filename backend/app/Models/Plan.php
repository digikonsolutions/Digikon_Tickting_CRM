<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A sellable plan. A contract copies these values when it is made; editing a plan never changes old contracts. */
class Plan extends Model
{
    public const BILLING_CYCLES = ['monthly', 'quarterly', 'yearly'];

    /** Plan columns a contract snapshots (and an admin may override per agency). */
    public const SNAPSHOT_FIELDS = ['employees', 'hours_per_month', 'max_open_projects', 'max_client_accounts', 'price', 'currency', 'billing_cycle', 'overage_rate'];

    protected $fillable = ['name', 'tagline', 'description', 'best_for', 'features', 'is_custom', 'sort_order', 'employees', 'hours_per_month', 'max_open_projects', 'max_client_accounts', 'price', 'currency', 'billing_cycle', 'overage_rate', 'is_active'];

    protected function casts(): array
    {
        return [
            'hours_per_month' => 'decimal:2',
            'price' => 'decimal:2',
            'overage_rate' => 'decimal:2',
            'is_active' => 'boolean',
            'is_custom' => 'boolean',
            'features' => 'array',
        ];
    }
}