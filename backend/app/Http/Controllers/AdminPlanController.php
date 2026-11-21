<?php

namespace App\Http\Controllers;

use App\Models\Plan;
use App\Support\NameNormalizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Plans an admin can pick in the agency wizard. A contract copies a plan's numbers; editing a plan never changes old contracts. */
class AdminPlanController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $plans = Plan::query()->when($request->boolean('active'), fn ($query) => $query->where('is_active', true))->orderBy('name')->get();

        return response()->json(['plans' => $plans->map(fn (Plan $plan): array => $this->payload($plan))->values()]);
    }

    public function store(Request $request): JsonResponse
    {
        $plan = Plan::create($this->validated($request))->refresh();

        return response()->json(['plan' => $this->payload($plan)], 201);
    }

    public function update(Request $request, Plan $plan): JsonResponse
    {
        $plan->update($this->validated($request, $plan));

        return response()->json(['plan' => $this->payload($plan->refresh())]);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?Plan $plan = null): array
    {
        if (is_string($request->input('name'))) {
            $request->merge(['name' => NameNormalizer::collapse($request->input('name'))]);
        }
        $sometimes = $plan ? 'sometimes' : 'required';

        return $request->validate([
            'name' => [$sometimes, 'string', 'max:120', Rule::unique('plans', 'name')->ignore($plan?->id)],
            'description' => ['sometimes', 'nullable', 'string', 'max:500'],
            'employees' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:1000'],
            'hours_per_month' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:100000'],
            'max_open_projects' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:1000'],
            'max_client_accounts' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:1000'],
            'price' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:99999999'],
            'currency' => ['sometimes', 'regex:/^[A-Za-z]{3}$/'],
            'billing_cycle' => ['sometimes', Rule::in(Plan::BILLING_CYCLES)],
            'overage_rate' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:99999'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
    }

    /** @return array<string, mixed> */
    private function payload(Plan $plan): array
    {
        return [
            'id' => $plan->id,
            'name' => $plan->name,
            'description' => $plan->description,
            'employees' => $plan->employees,
            'hours_per_month' => $plan->hours_per_month !== null ? (float) $plan->hours_per_month : null,
            'max_open_projects' => $plan->max_open_projects,
            'max_client_accounts' => $plan->max_client_accounts,
            'price' => $plan->price !== null ? (float) $plan->price : null,
            'currency' => $plan->currency,
            'billing_cycle' => $plan->billing_cycle,
            'overage_rate' => $plan->overage_rate !== null ? (float) $plan->overage_rate : null,
            'is_active' => $plan->is_active,
        ];
    }
}