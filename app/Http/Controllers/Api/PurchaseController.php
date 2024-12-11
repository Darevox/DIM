<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\Product;
use App\Models\ActivityLog;
use App\Models\Invoice;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class PurchaseController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();

        if (!$user->team) {
            return response()->json([
                'error' => true,
                'message' => 'No team found for the user'
            ], 404);
        }

        $query = Purchase::where('team_id', $user->team->id)
                        ->with(['supplier', 'items.product', 'cashSource']);

        // Date range filter
        if ($request->has('start_date') && $request->has('end_date')) {
            $query->whereBetween('purchase_date', [
                $request->start_date,
                $request->end_date
            ]);
        }

        // Status filter
        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        // Payment status filter
        if ($request->has('payment_status')) {
            $query->where('payment_status', $request->payment_status);
        }

        // Supplier filter
        if ($request->has('supplier_id')) {
            $query->where('supplier_id', $request->supplier_id);
        }

        // Search by reference number
        if ($request->has('search')) {
            $searchTerm = $request->search;
            $query->where(function($q) use ($searchTerm) {
                $q->where('reference_number', 'like', "%{$searchTerm}%")
                  ->orWhere('notes', 'like', "%{$searchTerm}%");
            });
        }

        // Sort
        $sortField = $request->get('sort_by', 'purchase_date');
        $sortDirection = $request->get('sort_direction', 'desc');
        $query->orderBy($sortField, $sortDirection);

        $purchases = $query->paginate($request->input('per_page', 15));

        return response()->json([
            'purchases' => $purchases
        ]);
    }

    public function store(Request $request)
    {
        $user = $request->user();

        if (!$user->team) {
            return response()->json([
                'error' => true,
                'message' => 'No team found for the user'
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'supplier_id' => 'required|exists:suppliers,id',
            'cash_source_id' => 'required|exists:cash_sources,id',
            'purchase_date' => 'required|date',
            'due_date' => 'nullable|date|after_or_equal:purchase_date',
            'notes' => 'nullable|string',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.unit_price' => 'required|numeric|min:0',
            'items.*.tax_rate' => 'nullable|numeric|min:0',
            'items.*.discount_amount' => 'nullable|numeric|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => true,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            DB::beginTransaction();

            // Create purchase
            $purchase = new Purchase();
            $purchase->team_id = $user->team->id;
            $purchase->supplier_id = $request->supplier_id;
            $purchase->cash_source_id = $request->cash_source_id;
            $purchase->reference_number = 'PUR-' . str_pad(Purchase::max('id') + 1, 6, '0', STR_PAD_LEFT);
            $purchase->purchase_date = $request->purchase_date;
            $purchase->due_date = $request->due_date;
            $purchase->notes = $request->notes;
            $purchase->status = 'pending';
            $purchase->payment_status = 'unpaid';
            $purchase->save();

            // Create purchase items
            foreach ($request->items as $item) {
                $purchaseItem = new PurchaseItem();
                $purchaseItem->purchase_id = $purchase->id;
                $purchaseItem->product_id = $item['product_id'];
                $purchaseItem->quantity = $item['quantity'];
                $purchaseItem->unit_price = $item['unit_price'];
                $purchaseItem->tax_rate = $item['tax_rate'] ?? 0;
                $purchaseItem->discount_amount = $item['discount_amount'] ?? 0;
                $purchaseItem->calculateTotals();
                $purchaseItem->save();

                // Update product stock
                $product = Product::find($item['product_id']);
                $product->updateStock($item['quantity'], 'add');
            }

            // Calculate purchase totals
            $purchase->calculateTotals();

            // Log activity
            ActivityLog::create([
                'log_type' => 'Create',
                'model_type' => "Purchase",
                'model_id' => $purchase->id,
                'model_identifier' => $purchase->reference_number,
                'user_identifier' => $user?->name,
                'user_id' => $user->id,
                'user_email' => $user?->email,
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'description' => "Created purchase {$purchase->reference_number}",
                'new_values' => $purchase->toArray()
            ]);

            DB::commit();

            return response()->json([
                'message' => 'Purchase created successfully',
                'purchase' => $purchase->load(['items.product', 'supplier', 'cashSource'])
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'error' => true,
                'message' => 'Error creating purchase',
                'debug' => $e->getMessage()
            ], 500);
        }
    }

    public function show(Request $request, $id)
    {
        $user = $request->user();

        $purchase = Purchase::where('team_id', $user->team->id)
                          ->with(['supplier', 'items.product', 'cashSource', 'transactions'])
                          ->find($id);

        if (!$purchase) {
            return response()->json([
                'error' => true,
                'message' => 'Purchase not found'
            ], 404);
        }

        return response()->json([
            'purchase' => $purchase
        ]);
    }

    public function update(Request $request, $id)
    {
        $user = $request->user();

        $purchase = Purchase::where('team_id', $user->team->id)->find($id);

        if (!$purchase) {
            return response()->json([
                'error' => true,
                'message' => 'Purchase not found'
            ], 404);
        }

        if ($purchase->status === 'completed') {
            return response()->json([
                'error' => true,
                'message' => 'Cannot update completed purchase'
            ], 400);
        }

        $validator = Validator::make($request->all(), [
            'purchase_date' => 'sometimes|required|date',
            'due_date' => 'nullable|date|after_or_equal:purchase_date',
            'notes' => 'nullable|string',
            'status' => 'sometimes|required|in:pending,completed,cancelled',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => true,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            DB::beginTransaction();

            $oldData = $purchase->toArray();
            $purchase->update($request->all());

            ActivityLog::create([
                'log_type' => 'Update',
                'model_type' => "Purchase",
                'model_id' => $purchase->id,
                'model_identifier' => $purchase->reference_number,
                'user_identifier' => $user?->name,
                'user_id' => $user->id,
                'user_email' => $user?->email,
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'description' => "Updated purchase {$purchase->reference_number}",
                'old_values' => $oldData,
                'new_values' => $purchase->toArray()
            ]);

            DB::commit();

            return response()->json([
                'message' => 'Purchase updated successfully',
                'purchase' => $purchase->fresh(['items.product', 'supplier', 'cashSource'])
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'error' => true,
                'message' => 'Error updating purchase'
            ], 500);
        }
    }

    public function addPayment(Request $request, $id)
    {
        $user = $request->user();

        $purchase = Purchase::where('team_id', $user->team->id)->find($id);

        if (!$purchase) {
            return response()->json([
                'error' => true,
                'message' => 'Purchase not found'
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'amount' => 'required|numeric|min:0.01',
            'payment_date' => 'required|date',
            'notes' => 'nullable|string'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => true,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            DB::beginTransaction();

            $transaction = $purchase->addPayment($request->amount, $purchase->cashSource);

            ActivityLog::create([
                'log_type' => 'Payment',
                'model_type' => "Purchase",
                'model_id' => $purchase->id,
                'model_identifier' => $purchase->reference_number,
                'user_identifier' => $user?->name,
                'user_id' => $user->id,
                'user_email' => $user?->email,
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'description' => "Added payment of {$request->amount} to purchase {$purchase->reference_number}",
                'new_values' => $transaction->toArray()
            ]);

            DB::commit();

            return response()->json([
                'message' => 'Payment added successfully',
                'purchase' => $purchase->fresh(['items.product', 'supplier', 'cashSource']),
                'transaction' => $transaction
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'error' => true,
                'message' => $e->getMessage()
            ], 400);
        }
    }

    public function generateInvoice(Request $request, $id)
    {
        $user = $request->user();

        $purchase = Purchase::where('team_id', $user->team->id)->find($id);

        if (!$purchase) {
            return response()->json([
                'error' => true,
                'message' => 'Purchase not found'
            ], 404);
        }

        try {
            DB::beginTransaction();

            $invoice = new Invoice();
            $invoice->team_id = $user->team->id;
            $invoice->invoiceable_type = Purchase::class;
            $invoice->invoiceable_id = $purchase->id;
            $invoice->reference_number = 'INV-' . str_pad(Invoice::max('id') + 1, 6, '0', STR_PAD_LEFT);
            $invoice->total_amount = $purchase->total_amount;
            $invoice->tax_amount = $purchase->tax_amount;
            $invoice->discount_amount = $purchase->discount_amount;
            $invoice->status = 'draft';
            $invoice->issue_date = now();
            $invoice->due_date = $purchase->due_date;
            $invoice->save();

            ActivityLog::create([
                'log_type' => 'Create',
                'model_type' => "Invoice",
                'model_id' => $invoice->id,
                'model_identifier' => $invoice->reference_number,
                'user_identifier' => $user?->name,
                'user_id' => $user->id,
                'user_email' => $user?->email,
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'description' => "Generated invoice {$invoice->reference_number} for purchase {$purchase->reference_number}",
                'new_values' => $invoice->toArray()
            ]);

            DB::commit();

            return response()->json([
                'message' => 'Invoice generated successfully',
                'invoice' => $invoice
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'error' => true,
                'message' => 'Error generating invoice'
            ], 500);
        }
    }

    public function getSummary(Request $request)
    {
        $user = $request->user();

        if (!$user->team) {
            return response()->json([
                'error' => true,
                'message' => 'No team found for the user'
            ], 404);
        }

        try {
            $startDate = $request->input('start_date', now()->startOfMonth());
            $endDate = $request->input('end_date', now()->endOfMonth());

            $summary = [
                'total_purchases' => Purchase::where('team_id', $user->team->id)
                    ->whereBetween('purchase_date', [$startDate, $endDate])
                    ->count(),

                'total_amount' => Purchase::where('team_id', $user->team->id)
                    ->whereBetween('purchase_date', [$startDate, $endDate])
                    ->sum('total_amount'),

                'total_paid' => Purchase::where('team_id', $user->team->id)
                    ->whereBetween('purchase_date', [$startDate, $endDate])
                    ->sum('paid_amount'),

                'purchases_by_status' => Purchase::where('team_id', $user->team->id)
                    ->whereBetween('purchase_date', [$startDate, $endDate])
                    ->select('status', DB::raw('COUNT(*) as count'))
                    ->groupBy('status')
                    ->get(),

                'purchases_by_payment_status' => Purchase::where('team_id', $user->team->id)
                    ->whereBetween('purchase_date', [$startDate, $endDate])
                    ->select('payment_status', DB::raw('COUNT(*) as count'))
                    ->groupBy('payment_status')
                    ->get(),

                'top_suppliers' => Purchase::where('team_id', $user->team->id)
                    ->whereBetween('purchase_date', [$startDate, $endDate])
                    ->join('suppliers', 'purchases.supplier_id', '=', 'suppliers.id')
                    ->select('suppliers.name', DB::raw('COUNT(*) as count'), DB::raw('SUM(total_amount) as total_amount'))
                    ->groupBy('suppliers.id', 'suppliers.name')
                    ->orderBy('total_amount', 'desc')
                    ->limit(5)
                    ->get()
            ];

            return response()->json([
                'summary' => $summary
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'error' => true,
                'message' => 'Error generating summary'
            ], 500);
        }
    }
}