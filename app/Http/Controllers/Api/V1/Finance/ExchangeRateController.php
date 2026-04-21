<?php

namespace App\Http\Controllers\Api\V1\Finance;

use App\Http\Controllers\Controller;
use App\Models\ExchangeRate;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ExchangeRateController extends Controller
{
    /**
     * Display a listing of exchange rates.
     */
    public function index(Request $request)
    {
        $query = ExchangeRate::where('organization_id', $request->user()->organization_id);

        // Filter by from currency
        if ($request->has('from_currency')) {
            $query->where('from_currency', $request->from_currency);
        }

        // Filter by to currency
        if ($request->has('to_currency')) {
            $query->where('to_currency', $request->to_currency);
        }

        // Filter by date range
        if ($request->has('from_date')) {
            $query->where('effective_date', '>=', $request->from_date);
        }
        if ($request->has('to_date')) {
            $query->where('effective_date', '<=', $request->to_date);
        }

        $rates = $query->orderBy('effective_date', 'desc')
            ->orderBy('from_currency')
            ->orderBy('to_currency')
            ->paginate($request->input('per_page', 50));

        return $this->paginated($rates);
    }

    /**
     * Store a newly created exchange rate.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'from_currency' => 'required|string|size:3',
            'to_currency' => 'required|string|size:3|different:from_currency',
            'rate' => 'required|numeric|min:0.000001',
            'effective_date' => 'required|date',
            'source' => 'nullable|string|max:100',
        ]);

        $validated['organization_id'] = $request->user()->organization_id;

        // Check if rate already exists for this date and currency pair
        $existing = ExchangeRate::where('organization_id', $request->user()->organization_id)
            ->where('from_currency', $validated['from_currency'])
            ->where('to_currency', $validated['to_currency'])
            ->where('effective_date', $validated['effective_date'])
            ->first();

        if ($existing) {
            // Update existing rate
            $existing->update(['rate' => $validated['rate'], 'source' => $validated['source'] ?? null]);
            return $this->success($existing, 'Exchange rate updated successfully');
        }

        $rate = ExchangeRate::create($validated);

        return $this->success($rate, 'Exchange rate created successfully', 201);
    }

    /**
     * Display the specified exchange rate.
     */
    public function show(Request $request, ExchangeRate $exchangeRate)
    {
        if ($exchangeRate->organization_id !== $request->user()->organization_id) {
            return $this->error('Exchange rate not found', 404);
        }

        return $this->success($exchangeRate);
    }

    /**
     * Update the specified exchange rate.
     */
    public function update(Request $request, ExchangeRate $exchangeRate)
    {
        if ($exchangeRate->organization_id !== $request->user()->organization_id) {
            return $this->error('Exchange rate not found', 404);
        }

        $validated = $request->validate([
            'rate' => 'sometimes|numeric|min:0.000001',
            'effective_date' => 'sometimes|date',
            'source' => 'nullable|string|max:100',
        ]);

        $exchangeRate->update($validated);

        return $this->success($exchangeRate, 'Exchange rate updated successfully');
    }

    /**
     * Remove the specified exchange rate.
     */
    public function destroy(Request $request, ExchangeRate $exchangeRate)
    {
        if ($exchangeRate->organization_id !== $request->user()->organization_id) {
            return $this->error('Exchange rate not found', 404);
        }

        $exchangeRate->delete();

        return $this->success(null, 'Exchange rate deleted successfully');
    }

    /**
     * Get current rate for a currency pair.
     */
    public function current(Request $request)
    {
        $validated = $request->validate([
            'from' => 'required|string|size:3',
            'to' => 'required|string|size:3',
            'date' => 'nullable|date',
        ]);

        $date = $validated['date'] ?? now()->toDateString();

        $rate = ExchangeRate::where('organization_id', $request->user()->organization_id)
            ->where('from_currency', $validated['from'])
            ->where('to_currency', $validated['to'])
            ->where('effective_date', '<=', $date)
            ->orderBy('effective_date', 'desc')
            ->first();

        if (!$rate) {
            // Try reverse rate
            $reverseRate = ExchangeRate::where('organization_id', $request->user()->organization_id)
                ->where('from_currency', $validated['to'])
                ->where('to_currency', $validated['from'])
                ->where('effective_date', '<=', $date)
                ->orderBy('effective_date', 'desc')
                ->first();

            if ($reverseRate) {
                return $this->success([
                    'from_currency' => $validated['from'],
                    'to_currency' => $validated['to'],
                    'rate' => 1 / $reverseRate->rate,
                    'effective_date' => $reverseRate->effective_date,
                    'source' => 'calculated_from_reverse',
                ]);
            }

            return $this->error('No exchange rate found for this currency pair', 404);
        }

        return $this->success($rate);
    }

    /**
     * Convert amount between currencies.
     */
    public function convert(Request $request)
    {
        $validated = $request->validate([
            'amount' => 'required|numeric|min:0',
            'from' => 'required|string|size:3',
            'to' => 'required|string|size:3',
            'date' => 'nullable|date',
        ]);

        if ($validated['from'] === $validated['to']) {
            return $this->success([
                'original_amount' => $validated['amount'],
                'converted_amount' => $validated['amount'],
                'from_currency' => $validated['from'],
                'to_currency' => $validated['to'],
                'rate' => 1,
            ]);
        }

        $date = $validated['date'] ?? now()->toDateString();

        $rate = ExchangeRate::getRate(
            $validated['from'],
            $validated['to'],
            $date,
            $request->user()->organization_id
        );

        if (!$rate) {
            return $this->error('No exchange rate found for this currency pair', 404);
        }

        $convertedAmount = $validated['amount'] * $rate;

        return $this->success([
            'original_amount' => $validated['amount'],
            'converted_amount' => round($convertedAmount, 2),
            'from_currency' => $validated['from'],
            'to_currency' => $validated['to'],
            'rate' => $rate,
            'effective_date' => $date,
        ]);
    }

    /**
     * Bulk import exchange rates.
     */
    public function bulkImport(Request $request)
    {
        $validated = $request->validate([
            'rates' => 'required|array|min:1',
            'rates.*.from_currency' => 'required|string|size:3',
            'rates.*.to_currency' => 'required|string|size:3',
            'rates.*.rate' => 'required|numeric|min:0.000001',
            'rates.*.effective_date' => 'required|date',
            'rates.*.source' => 'nullable|string|max:100',
        ]);

        $created = 0;
        $updated = 0;

        foreach ($validated['rates'] as $rateData) {
            $existing = ExchangeRate::where('organization_id', $request->user()->organization_id)
                ->where('from_currency', $rateData['from_currency'])
                ->where('to_currency', $rateData['to_currency'])
                ->where('effective_date', $rateData['effective_date'])
                ->first();

            if ($existing) {
                $existing->update([
                    'rate' => $rateData['rate'],
                    'source' => $rateData['source'] ?? null,
                ]);
                $updated++;
            } else {
                ExchangeRate::create([
                    'organization_id' => $request->user()->organization_id,
                    'from_currency' => $rateData['from_currency'],
                    'to_currency' => $rateData['to_currency'],
                    'rate' => $rateData['rate'],
                    'effective_date' => $rateData['effective_date'],
                    'source' => $rateData['source'] ?? null,
                ]);
                $created++;
            }
        }

        return $this->success([
            'created' => $created,
            'updated' => $updated,
            'total' => $created + $updated,
        ], 'Exchange rates imported successfully');
    }

    /**
     * Get rate history for a currency pair.
     */
    public function history(Request $request)
    {
        $validated = $request->validate([
            'from' => 'required|string|size:3',
            'to' => 'required|string|size:3',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
        ]);

        $query = ExchangeRate::where('organization_id', $request->user()->organization_id)
            ->where('from_currency', $validated['from'])
            ->where('to_currency', $validated['to']);

        if ($request->has('start_date')) {
            $query->where('effective_date', '>=', $validated['start_date']);
        }
        if ($request->has('end_date')) {
            $query->where('effective_date', '<=', $validated['end_date']);
        }

        $rates = $query->orderBy('effective_date', 'asc')->get();

        return $this->success([
            'from_currency' => $validated['from'],
            'to_currency' => $validated['to'],
            'history' => $rates,
        ]);
    }
}
