<?php

namespace App\Http\Controllers;

use App\Models\StoreSetting;
use App\Models\Transaction;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class ReceiptController extends Controller
{
    public function __invoke(Request $request, Transaction $transaction): View
    {
        Gate::authorize('view', $transaction);

        $settings = StoreSetting::current();
        $paperWidth = $settings->receipt_paper_width === 80 ? 80 : 58;

        return view('receipts.show', [
            'transaction' => $transaction->load(['items', 'cashier']),
            'settings' => $settings,
            'paperWidth' => $paperWidth,
            'printableWidth' => $paperWidth === 80 ? 72 : 48,
            'autoPrint' => $request->boolean('print'),
        ]);
    }
}
