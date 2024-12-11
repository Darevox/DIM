<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Product;
use App\Models\ActivityLog;
use App\Models\Invoice;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class SaleController extends Controller
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

        $query = Sale::where('team_id', $user->team->id)
                    ->with(['client', 'items.product', 'cashSource']);

        // Date range filter
        if ($request->has('start_date') && $request->has('end_date')) {
            $query->whereBetween('sale_date', [
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

        // Client filter
        if ($request->has('client_id')) {
            $query->where('client_id', $request->client_id);
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
        $sortField = $request->get('sort_by', 'sale_date');
        $sortDirection = $request->get('sort_direction', 'desc');
        $query->orderBy($sortField, $sortDirection);

        $sales = $query->paginate($request->input('per_page', 15));

        return response()->json([
            'sales' => $sales
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
            'client_id' => 'required|exists:clients,id',
            'cash_source_id' => 'required|exists:cash_sources,id',
            'sale_date' => 'required|date',
            'due_date' => 'nullable|date|after_or_equal:sale_date',
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

            // Check stock availability
            foreach ($request->items as $item) {
                $product = Product::find($item['product_id']);
                if ($product->quantity < $item['quantity']) {
                    return response()->json([
                        'error' => true,
                        'message' => "Insufficient stock for product: {$product->name}"
                    ], 400);
                }
            }

            // Create sale
            $sale = new Sale();
            $sale->team_id = $user->team->id;
            $sale->client_id = $request->client_id;
            $sale->cash_source_id = $request->cash_source_id;
            $sale->reference_number = 'SALE-' . str_pad(Sale::max('id') + 1, 6, '0', STR_PAD_LEFT);
            $sale->sale_date = $request->sale_date;
            $sale->due_date = $request->due_date;
            $sale->notes = $request->notes;
            $sale->status = 'pending';
            $sale->payment_status = 'unpaid';
            $sale->save();

            // Create sale items and update stock
            foreach ($request->items as $item) {
                $saleItem = new SaleItem();
                $saleItem->sale_id = $sale->id;
                $saleItem->product_id = $item['product_id'];
                $saleItem->quantity = $item['quantity'];
                $saleItem->unit_price = $item['unit_price'];
                $saleItem->tax_rate = $item['tax_rate'] ?? 0;
                $saleItem->discount_amount = $item['discount_amount'] ?? 0;
                $saleItem->calculateTotals();
                $saleItem->save();

                // Update product stock
                $product = Product::find($item['product_id']);
                $product->updateStock($item['quantity'], 'subtract');
            }

            // Calculate sale totals
            $sale->calculateTotals();

            // Log activity
            ActivityLog::create([
                'log_type' => 'Create',
                'model_type' => "Sale",
                'model_id' => $sale->id,
                'model_identifier' => $sale->reference_number,
                'user_identifier' => $user?->name,
                'user_id' => $user->id,
                'user_email' => $user?->email,
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'description' => "Created sale {$sale->reference_number}",
                'new_values' => $sale->toArray()
            ]);

            DB::commit();

            return response()->json([
                'message' => 'Sale created successfully',
                'sale' => $sale->load(['items.product', 'client', 'cashSource'])
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'error' => true,
                'message' => 'Error creating sale',
                'debug' => $e->getMessage()
            ], 500);
        }
    }

    public function show(Request $request, $id)
    {
        $user = $request->user();

        $sale = Sale::where('team_id', $user->team->id)
                   ->with(['client', 'items.product', 'cashSource', 'transactions'])
                   ->find($id);

        if (!$sale) {
            return response()->json([
                'error' => true,
                'message' => 'Sale not found'
            ], 404);
        }

        return response()->json([
            'sale' => $sale
        ]);
    }

    public function update(Request $request, $id)
    {
        $user = $request->user();

        $sale = Sale::where('team_id', $user->team->id)->find($id);

        if (!$sale) {
            return response()->json([
                'error' => true,
                'message' => 'Sale not found'
            ], 404);
        }

        if ($sale->status === 'completed') {
            return response()->json([
                'error' => true,
                'message' => 'Cannot update completed sale'
            ], 400);
        }

        $validator = Validator::make($request->all(), [
            'sale_date' => 'sometimes|required|date',
            'due_date' => 'nullable|date|after_or_equal:sale_date',
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

            $oldData = $sale->toArray();
            $sale->update($request->all());

            ActivityLog::create([
                'log_type' => 'Update',
                'model_type' => "Sale",
                'model_id' => $sale->id,
                'model_identifier' => $sale->reference_number,
                'user_identifier' => $user?->name,
                'user_id' => $user->id,
                'user_email' => $user?->email,
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'description' => "Updated sale {$sale->reference_number}",
                'old_values' => $oldData,
                'new_values' => $sale->toArray()
            ]);

            DB::commit();

            return response()->json([
                'message' => 'Sale updated successfully',
                'sale' => $sale->fresh(['items.product', 'client', 'cashSource'])
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'error' => true,
                'message' => 'Error updating sale'
            ], 500);
        }
    }

    public function addPayment(Request $request, $id)
    {
        $user = $request->user();

        $sale = Sale::where('team_id', $user->team->id)->find($id);

        if (!$sale) {
            return response()->json([
                'error' => true,
                'message' => 'Sale not found'
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

            $transaction = $sale->addPayment($request->amount, $sale->cashSource);

            ActivityLog::create([
                'log_type' => 'Payment',
                'model_type' => "Sale",
                'model_id' => $sale->id,
                'model_identifier' => $sale->reference_number,
                'user_identifier' => $user?->name,
                'user_id' => $user->id,
                'user_email' => $user?->email,
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'description' => "Added payment of {$request->amount} to sale {$sale->reference_number}",
                'new_values' => $transaction->toArray()
            ]);

            DB::commit();

            return response()->json([
                'message' => 'Payment added successfully',
                'sale' => $sale->fresh(['items.product', 'client', 'cashSource']),
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

        $sale = Sale::where('team_id', $user->team->id)->find($id);

        if (!$sale) {
            return response()->json([
                'error' => true,
                'message' => 'Sale not found'
            ], 404);
        }

        try {
            DB::beginTransaction();

            $invoice = new Invoice();
            $invoice->team_id = $user->team->id;
            $invoice->invoiceable_type = Sale::class;
            $invoice->invoiceable_id = $sale->id;
            $invoice->reference_number = 'INV-' . str_pad(Invoice::max('id') + 1, 6, '0', STR_PAD_LEFT);
            $invoice->total_amount = $sale->total_amount;
            $invoice->tax_amount = $sale->tax_amount;
            $invoice->discount_amount = $sale->discount_amount;
            $invoice->status = 'draft';
            $invoice->issue_date = now();
            $invoice->due_date = $sale->due_date;
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
                'description' => "Generated invoice {$invoice->reference_number} for sale {$sale->reference_number}",
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
                'total_sales' => Sale::where('team_id', $user->team->id)
                    ->whereBetween('sale_date', [$startDate, $endDate])
                    ->count(),

                'total_amount' => Sale::where('team_id', $user->team->id)
                    ->whereBetween('sale_date', [$startDate, $endDate])
                    ->sum('total_amount'),

                'total_paid' => Sale::where('team_id', $user->team->id)
                    ->whereBetween('sale_date', [$startDate, $endDate])
                    ->sum('paid_amount'),

                'sales_by_status' => Sale::where('team_id', $user->team->id)
                    ->whereBetween('sale_date', [$startDate, $endDate])
                    ->select('status', DB::raw('COUNT(*) as count'))
                    ->groupBy('status')
                    ->get(),

                'sales_by_payment_status' => Sale::where('team_id', $user->team->id)
                    ->whereBetween('sale_date', [$startDate, $endDate])
                    ->select('payment_status', DB::raw('COUNT(*) as count'))
                    ->groupBy('payment_status')
                    ->get(),

                'top_clients' => Sale::where('team_id', $user->team->id)
                    ->whereBetween('sale_date', [$startDate, $endDate])
                    ->join('clients', 'sales.client_id', '=', 'clients.id')
                    ->select('clients.name', DB::raw('COUNT(*) as count'), DB::raw('SUM(total_amount) as total_amount'))
                    ->groupBy('clients.id', 'clients.name')
                    ->orderBy('total_amount', 'desc')
                    ->limit(5)
                    ->get(),

                'top_products' => SaleItem::join('sales', 'sale_items.sale_id', '=', 'sales.id')
                    ->where('sales.team_id', $user->team->id)
                    ->whereBetween('sales.sale_date', [$startDate, $endDate])
                    ->join('products', 'sale_items.product_id', '=', 'products.id')
                    ->select(
                        'products.name',
                        DB::raw('SUM(sale_items.quantity) as total_quantity'),
                        DB::raw('SUM(sale_items.total_price) as total_amount')
                    )
                    ->groupBy('products.id', 'products.name')
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