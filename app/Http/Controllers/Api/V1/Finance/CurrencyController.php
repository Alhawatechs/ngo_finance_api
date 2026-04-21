<?php

namespace App\Http\Controllers\Api\V1\Finance;

use App\Http\Controllers\Controller;
use App\Models\Currency;
use App\Models\ExchangeRate;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;

class CurrencyController extends Controller
{
    /**
     * Display a listing of currencies.
     */
    public function index(Request $request)
    {
        $orgId = $request->user()->organization_id;
        $isActive = $request->has('is_active') ? $request->boolean('is_active') : null;
        $cacheKey = "currencies_{$orgId}_" . ($isActive === null ? 'all' : ($isActive ? 'active' : 'inactive'));

        $currencies = Cache::remember($cacheKey, 300, function () use ($orgId, $isActive) {
            $query = Currency::where('organization_id', $orgId);
            if ($isActive !== null) {
                $query->where('is_active', $isActive);
            }
            return $query->orderBy('code')->get();
        });

        return $this->success($currencies);
    }

    /**
     * Store a newly created currency.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'code' => [
                'required',
                'string',
                'size:3',
                Rule::unique('currencies')->where(function ($query) use ($request) {
                    return $query->where('organization_id', $request->user()->organization_id);
                }),
            ],
            'name' => 'required|string|max:100',
            'symbol' => 'required|string|max:10',
            'decimal_places' => 'integer|min:0|max:6',
            'is_default' => 'boolean',
        ]);

        $validated['organization_id'] = $request->user()->organization_id;
        $validated['is_active'] = true;

        // If this is the default currency, unset other defaults
        if ($validated['is_default'] ?? false) {
            Currency::where('organization_id', $request->user()->organization_id)
                ->update(['is_default' => false]);
        }

        $currency = Currency::create($validated);
        $this->clearCurrencyCache($validated['organization_id']);

        return $this->success($currency, 'Currency created successfully', 201);
    }

    /**
     * Display the specified currency.
     */
    public function show(Request $request, Currency $currency)
    {
        if ($currency->organization_id !== $request->user()->organization_id) {
            return $this->error('Currency not found', 404);
        }

        return $this->success($currency);
    }

    /**
     * Update the specified currency.
     */
    public function update(Request $request, Currency $currency)
    {
        if ($currency->organization_id !== $request->user()->organization_id) {
            return $this->error('Currency not found', 404);
        }

        $validated = $request->validate([
            'name' => 'sometimes|string|max:100',
            'symbol' => 'sometimes|string|max:10',
            'decimal_places' => 'sometimes|integer|min:0|max:6',
            'is_default' => 'sometimes|boolean',
            'is_active' => 'sometimes|boolean',
        ]);

        // If setting as default, unset other defaults
        if (($validated['is_default'] ?? false) && !$currency->is_default) {
            Currency::where('organization_id', $request->user()->organization_id)
                ->where('id', '!=', $currency->id)
                ->update(['is_default' => false]);
        }

        $currency->update($validated);
        $this->clearCurrencyCache($currency->organization_id);

        return $this->success($currency, 'Currency updated successfully');
    }

    /**
     * Remove the specified currency.
     * Cascades to delete related exchange rates (from or to this currency).
     * Only blocks if it is the organization's default currency.
     */
    public function destroy(Request $request, Currency $currency)
    {
        if ($currency->organization_id !== $request->user()->organization_id) {
            return $this->error('Currency not found', 404);
        }

        if ($currency->is_default) {
            return $this->error('Cannot delete the default currency. Set another currency as default first.', 400);
        }

        // Cascade: delete all exchange rates that reference this currency
        $deletedRates = ExchangeRate::where('organization_id', $request->user()->organization_id)
            ->where(function ($q) use ($currency) {
                $q->where('from_currency', $currency->code)
                  ->orWhere('to_currency', $currency->code);
            })
            ->delete();

        $orgId = $currency->organization_id;
        $currency->delete();
        $this->clearCurrencyCache($orgId);

        $message = $deletedRates > 0
            ? "Currency and {$deletedRates} related exchange rate(s) deleted successfully."
            : 'Currency deleted successfully';

        return $this->success(null, $message);
    }

    /**
     * Set currency as default.
     */
    public function setDefault(Request $request, Currency $currency)
    {
        if ($currency->organization_id !== $request->user()->organization_id) {
            return $this->error('Currency not found', 404);
        }

        // Unset current default
        Currency::where('organization_id', $request->user()->organization_id)
            ->update(['is_default' => false]);

        $currency->update(['is_default' => true]);
        $this->clearCurrencyCache($currency->organization_id);

        return $this->success($currency, 'Default currency set successfully');
    }

    /**
     * Get exchange rates for a currency.
     */
    public function exchangeRates(Request $request, Currency $currency)
    {
        if ($currency->organization_id !== $request->user()->organization_id) {
            return $this->error('Currency not found', 404);
        }

        $rates = ExchangeRate::where('organization_id', $request->user()->organization_id)
            ->where('from_currency', $currency->code)
            ->orderBy('effective_date', 'desc')
            ->get();

        return $this->success($rates);
    }

    private function clearCurrencyCache(int $orgId): void
    {
        foreach (['all', 'active', 'inactive'] as $suffix) {
            Cache::forget("currencies_{$orgId}_{$suffix}");
        }
    }
}
