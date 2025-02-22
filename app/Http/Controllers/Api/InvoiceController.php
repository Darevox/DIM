<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\ActivityLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Spatie\Browsershot\Browsershot;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Facades\Session;

class InvoiceController extends Controller
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

        $query = Invoice::where('team_id', $user->team->id)
                       ->with(['invoiceable']);

        // Date range filter
        if ($request->has('start_date') && $request->has('end_date')) {
            $query->whereBetween('issue_date', [
                $request->start_date,
                $request->end_date
            ]);
        }

        // Status filter
        if ($request->has('status')) {
            $query->where('status', $request->status);
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
        $sortField = $request->get('sort_by', 'issue_date');
        $sortDirection = $request->get('sort_direction', 'desc');
        $query->orderBy($sortField, $sortDirection);

        $invoices = $query->paginate($request->input('per_page', 15));

        return response()->json([
            'invoices' => $invoices
        ]);
    }

    public function show(Request $request, $id)
    {
        $user = $request->user();

        $invoice = Invoice::where('team_id', $user->team->id)
                        ->with(['invoiceable'])
                        ->find($id);

        if (!$invoice) {
            return response()->json([
                'error' => true,
                'message' => 'Invoice not found'
            ], 404);
        }

        return response()->json([
            'invoice' => $invoice
        ]);
    }
    public function store(Request $request)
    {
        try {
            \Log::info('Invoice creation request received', [
                'request_data' => $request->all()
            ]);
    
            $user = $request->user();
            
            if (!$user->team) {
                return response()->json([
                    'error' => true,
                    'message' => 'No team found for the user'
                ], 404);
            }
    
            // Map invoice type to model namespace
            $typeMapping = [
                'Purchase' => 'App\\Models\\Purchase',
                'Sale' => 'App\\Models\\Sale'
            ];
    
            // Map the type or return error if invalid
            if (!isset($typeMapping[$request->invoiceable_type])) {
                return response()->json([
                    'error' => true,
                    'message' => 'Invalid invoiceable type',
                    'valid_types' => array_keys($typeMapping)
                ], 422);
            }
    
            $mappedType = $typeMapping[$request->invoiceable_type];
    
            // Validate main invoice data
            $validator = Validator::make($request->all(), [
                'invoiceable_type' => 'required|string|in:Purchase,Sale',
                'status' => 'required|in:draft,sent,paid,overdue,cancelled',
                'issue_date' => 'required|date',
                'due_date' => 'required|date|after_or_equal:issue_date',
                'total_amount' => 'required|numeric|min:0',
                'tax_amount' => 'nullable|numeric|min:0',
                'discount_amount' => 'nullable|numeric|min:0',
                'notes' => 'nullable|string',
                'items' => 'required|array|min:1',
                'items.*.description' => 'required|string',
                'items.*.quantity' => 'required|numeric|min:1',
                'items.*.unit_price' => 'required|numeric|min:0',
                'items.*.total_price' => 'required|numeric|min:0',
                'items.*.notes' => 'nullable|string'
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
    
                $lastInvoice = Invoice::where('team_id', $user->team->id)
                    ->orderBy('id', 'desc')
                    ->first();
    
                $nextId = ($lastInvoice ? $lastInvoice->id : 0) + 1;
                $nextInvoiceableId = ($lastInvoice ? $lastInvoice->invoiceable_id : 0) + 1;
    
                $year = date('Y');
                $referenceNumber = "INV-{$year}-" . str_pad($nextId, 6, '0', STR_PAD_LEFT);
    
                // Create invoice with mapped type
                $invoice = new Invoice([
                    'team_id' => $user->team->id,
                    'reference_number' => $referenceNumber,
                    'invoiceable_type' => $mappedType, // Use mapped type here
                    'invoiceable_id' => $nextInvoiceableId,
                    'total_amount' => $request->total_amount,
                    'tax_amount' => $request->tax_amount ?? 0,
                    'discount_amount' => $request->discount_amount ?? 0,
                    'status' => $request->status,
                    'issue_date' => $request->issue_date,
                    'due_date' => $request->due_date,
                    'notes' => $request->notes,
                    'meta_data' => array_merge($request->meta_data ?? [], [
                        'created_by' => $user->id,
                        'created_at' => now()->toISOString(),
                        'source_type' => $request->invoiceable_type,
                        'source_reference' => $referenceNumber
                    ])
                ]);
    
                $invoice->save();
    
                // Create invoice items
                foreach ($request->items as $itemData) {
                    $item = new InvoiceItem([
                        'description' => $itemData['description'],
                        'quantity' => $itemData['quantity'],
                        'unit_price' => $itemData['unit_price'],
                        'total_price' => $itemData['total_price'],
                        'notes' => $itemData['notes'] ?? null,
                        'meta_data' => $itemData['meta_data'] ?? null
                    ]);
                    $invoice->items()->save($item);
                }
    
                // Log activity
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
                    'description' => "Created invoice {$invoice->reference_number}",
                    'new_values' => array_merge($invoice->toArray(), [
                        'items' => $invoice->items->toArray()
                    ])
                ]);
    
                DB::commit();
    
                // Transform the type back for the response
                $invoice->load(['items']);
                $responseInvoice = $invoice->toArray();
                $responseInvoice['invoiceable_type'] = array_search($invoice->invoiceable_type, $typeMapping);
    
                return response()->json([
                    'message' => 'Invoice created successfully',
                    'invoice' => $responseInvoice
                ], 201);
    
            } catch (\Exception $e) {
                DB::rollBack();
                throw $e;
            }
        } catch (\Exception $e) {
            \Log::error('Error creating invoice', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
    
            return response()->json([
                'error' => true,
                'message' => 'Error creating invoice',
                'debug_message' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }
    
    
    
    public function update(Request $request, $id)
    {
        try {
            \Log::info('Invoice update request received', [
                'invoice_id' => $id,
                'request_data' => $request->all(),
                'user_id' => $request->user()?->id,
                'ip' => $request->ip()
            ]);
    
            $user = $request->user();
    
            $invoice = Invoice::where('team_id', $user->team->id)->find($id);
    
            if (!$invoice) {
                \Log::warning('Invoice not found for update', [
                    'invoice_id' => $id,
                    'team_id' => $user->team->id,
                    'user_id' => $user->id
                ]);
                return response()->json([
                    'error' => true,
                    'message' => 'Invoice not found'
                ], 404);
            }
    
            \Log::info('Found invoice for update', [
                'invoice_id' => $invoice->id,
                'reference_number' => $invoice->reference_number,
                'current_status' => $invoice->status
            ]);
    
            // Only validate status
            $validator = Validator::make($request->all(), [
                'status' => 'required|in:draft,sent,paid,cancelled'
            ]);
    
            if ($validator->fails()) {
                \Log::warning('Invoice status update validation failed', [
                    'invoice_id' => $id,
                    'errors' => $validator->errors()->toArray(),
                    'request_data' => $request->all()
                ]);
                return response()->json([
                    'error' => true,
                    'message' => 'Validation error',
                    'errors' => $validator->errors()
                ], 422);
            }
    
            try {
                DB::beginTransaction();
    
                \Log::info('Starting invoice status update transaction', [
                    'invoice_id' => $invoice->id,
                    'old_status' => $invoice->status,
                    'new_status' => $request->status
                ]);
    
                $oldData = $invoice->toArray();
                
                // Only update status
                $invoice->status = $request->status;
                $invoice->save();
    
                \Log::info('Invoice status updated successfully', [
                    'invoice_id' => $invoice->id,
                    'old_status' => $oldData['status'],
                    'new_status' => $invoice->status
                ]);
    
                try {
                    ActivityLog::create([
                        'log_type' => 'Status Update',
                        'model_type' => "Invoice",
                        'model_id' => $invoice->id,
                        'model_identifier' => $invoice->reference_number,
                        'user_identifier' => $user?->name,
                        'user_id' => $user->id,
                        'user_email' => $user?->email,
                        'ip_address' => $request->ip(),
                        'user_agent' => $request->userAgent(),
                        'description' => "Updated invoice {$invoice->reference_number} status from {$oldData['status']} to {$invoice->status}",
                        'old_values' => ['status' => $oldData['status']],
                        'new_values' => ['status' => $invoice->status]
                    ]);
    
                    \Log::info('Activity log created for invoice status update', [
                        'invoice_id' => $invoice->id,
                        'log_type' => 'Status Update'
                    ]);
                } catch (\Exception $e) {
                    \Log::error('Error creating activity log for invoice status update', [
                        'invoice_id' => $invoice->id,
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString()
                    ]);
                    // Don't throw the error as this is not critical
                }
    
                DB::commit();
                \Log::info('Invoice status update transaction committed successfully', [
                    'invoice_id' => $invoice->id
                ]);
    
                $refreshedInvoice = $invoice->fresh(['items']);
                return response()->json([
                    'message' => 'Invoice status updated successfully',
                    'invoice' => $refreshedInvoice
                ]);
    
            } catch (\Exception $e) {
                DB::rollBack();
                
                \Log::error('Error updating invoice status', [
                    'invoice_id' => $id,
                    'error_message' => $e->getMessage(),
                    'error_code' => $e->getCode(),
                    'error_file' => $e->getFile(),
                    'error_line' => $e->getLine(),
                    'trace' => $e->getTraceAsString(),
                    'request_data' => $request->all()
                ]);
    
                return response()->json([
                    'error' => true,
                    'message' => 'Error updating invoice status',
                    'debug_message' => config('app.debug') ? $e->getMessage() : null,
                    'error_details' => config('app.debug') ? [
                        'file' => $e->getFile(),
                        'line' => $e->getLine(),
                        'message' => $e->getMessage(),
                        'trace' => array_slice($e->getTrace(), 0, 5)
                    ] : null
                ], 500);
            }
        } catch (\Exception $e) {
            \Log::critical('Unhandled exception in invoice status update', [
                'invoice_id' => $id,
                'error_message' => $e->getMessage(),
                'error_code' => $e->getCode(),
                'error_file' => $e->getFile(),
                'error_line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
                'request_data' => $request->all()
            ]);
    
            return response()->json([
                'error' => true,
                'message' => 'Critical error occurred while updating invoice status',
                'debug_message' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }
    


    public function destroy(Request $request, $id)
    {
        $user = $request->user();

        $invoice = Invoice::where('team_id', $user->team->id)->find($id);

        if (!$invoice) {
            return response()->json([
                'error' => true,
                'message' => 'Invoice not found'
            ], 404);
        }

        try {
            DB::beginTransaction();

            $invoice->delete();

            ActivityLog::create([
                'log_type' => 'Delete',
                'model_type' => "Invoice",
                'model_id' => $invoice->id,
                'model_identifier' => $invoice->reference_number,
                'user_identifier' => $user?->name,
                'user_id' => $user->id,
                'user_email' => $user?->email,
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'description' => "Deleted invoice {$invoice->reference_number}",
                'old_values' => $invoice->toArray()
            ]);

            DB::commit();

            return response()->json([
                'message' => 'Invoice deleted successfully'
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'error' => true,
                'message' => 'Error deleting invoice'
            ], 500);
        }
    }

    public function send(Request $request, $id)
    {
        $user = $request->user();

        $invoice = Invoice::where('team_id', $user->team->id)->find($id);

        if (!$invoice) {
            return response()->json([
                'error' => true,
                'message' => 'Invoice not found'
            ], 404);
        }

        try {
            DB::beginTransaction();

            $invoice->markAsSent();

            ActivityLog::create([
                'log_type' => 'Send',
                'model_type' => "Invoice",
                'model_id' => $invoice->id,
                'model_identifier' => $invoice->reference_number,
                'user_identifier' => $user?->name,
                'user_id' => $user->id,
                'user_email' => $user?->email,
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'description' => "Sent invoice {$invoice->reference_number}",
                'new_values' => $invoice->fresh()->toArray()
            ]);

            DB::commit();

            return response()->json([
                'message' => 'Invoice sent successfully',
                'invoice' => $invoice->fresh()
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'error' => true,
                'message' => 'Error sending invoice'
            ], 500);
        }
    }

    public function markAsPaid(Request $request, $id)
    {
        $user = $request->user();

        $invoice = Invoice::where('team_id', $user->team->id)->find($id);

        if (!$invoice) {
            return response()->json([
                'error' => true,
                'message' => 'Invoice not found'
            ], 404);
        }

        try {
            DB::beginTransaction();

            $invoice->markAsPaid();

            ActivityLog::create([
                'log_type' => 'Status Update',
                'model_type' => "Invoice",
                'model_id' => $invoice->id,
                'model_identifier' => $invoice->reference_number,
                'user_identifier' => $user?->name,
                'user_id' => $user->id,
                'user_email' => $user?->email,
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'description' => "Marked invoice {$invoice->reference_number} as paid",
                'new_values' => $invoice->fresh()->toArray()
            ]);

            DB::commit();

            return response()->json([
                'message' => 'Invoice marked as paid',
                'invoice' => $invoice->fresh()
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'error' => true,
                'message' => 'Error updating invoice status'
            ], 500);
        }
    }

    public function download(Request $request, $id)
    {
        try {
            $user = $request->user();
            
            // Get invoice with all necessary relationships
            $invoice = Invoice::where('team_id', $user->team->id)
                             ->with([
                                 'items',
                                 'invoiceable',
                                 'team',
                             ])
                             ->findOrFail($id);
    
            if (!$invoice) {
                return response()->json([
                    'error' => true,
                    'message' => 'Invoice not found'
                ], 404);
            }
    
            // Verify all necessary data is present
            if (!$invoice->items || !$invoice->invoiceable) {
                return response()->json([
                    'error' => true,
                    'message' => 'Invoice data is incomplete'
                ], 422);
            }
    
            // Get team's preferred locale, fallback to default
            $locale = $invoice->team->locale ?? config('app.locale');
            
            // Set locale for this request
            app()->setLocale($locale);
    
            // Handle team logo
            if ($invoice->team->image_path) {
                $imagePath = storage_path('app/public/' . $invoice->team->image_path);
                if (file_exists($imagePath)) {
                    $imageData = base64_encode(file_get_contents($imagePath));
                    $imageMime = mime_content_type($imagePath);
                    $invoice->team->logo_data_url = "data:{$imageMime};base64,{$imageData}";
                }
            }
    
            // Format numbers according to locale
            $numberFormatter = new \NumberFormatter($locale, \NumberFormatter::DECIMAL);
            $currencyFormatter = new \NumberFormatter($locale, \NumberFormatter::CURRENCY);
            
            // Format dates according to locale
            $dateFormatter = new \IntlDateFormatter(
                $locale,
                \IntlDateFormatter::LONG,
                \IntlDateFormatter::NONE
            );
    
            // Prepare formatted data
            $formattedInvoice = [
                'invoice' => $invoice,
                'formatted' => [
                    'issue_date' => $dateFormatter->format($invoice->issue_date),
                    'due_date' => $dateFormatter->format($invoice->due_date),
                    'subtotal' => $currencyFormatter->format($invoice->meta_data['subtotal'] ?? 0),
                    'tax_amount' => $currencyFormatter->format($invoice->tax_amount),
                    'discount_amount' => $currencyFormatter->format($invoice->discount_amount),
                    'total_amount' => $currencyFormatter->format($invoice->total_amount),
                ],
                'items' => $invoice->items->map(function ($item) use ($numberFormatter, $currencyFormatter) {
                    return [
                        'description' => $item->description,
                        'quantity' => $numberFormatter->format($item->quantity),
                        'unit_price' => $currencyFormatter->format($item->unit_price),
                        'tax_amount' => $currencyFormatter->format($item->tax_amount ?? 0),
                        'discount_amount' => $currencyFormatter->format($item->discount_amount ?? 0),
                        'total_price' => $currencyFormatter->format($item->total_price),
                    ];
                }),
                'translations' => [
                    'invoice' => __('invoice.invoice'),
                    'bill' => __('invoice.bill'),
                    'bill_to' => __('invoice.bill_to'),
                    'supplier' => __('invoice.supplier'),
                    'tax_number' => __('invoice.tax_number'),
                    'issue_date' => __('invoice.issue_date'),
                    'due_date' => __('invoice.due_date'),
                    'description' => __('invoice.description'),
                    'quantity' => __('invoice.quantity'),
                    'unit_price' => __('invoice.unit_price'),
                    'tax' => __('invoice.tax'),
                    'discount' => __('invoice.discount'),
                    'total' => __('invoice.total'),
                    'subtotal' => __('invoice.subtotal'),
                    'total_amount' => __('invoice.total_amount'),
                    'notes' => __('invoice.notes'),
                    'thank_you' => __('invoice.thank_you'),
                ],
                'rtl' => in_array($locale, ['ar']), // RTL support for Arabic
            ];
    
            // Generate HTML with localized data
            $html = View::make('invoices.pdf', $formattedInvoice)->render();
    
            // Create filename with locale
            $filename = "invoice-{$invoice->reference_number}-{$locale}.pdf";
            $tempPath = storage_path('app/public/temp');
            
            if (!file_exists($tempPath)) {
                mkdir($tempPath, 0755, true);
            }
            
            $pdfPath = $tempPath . '/' . $filename;
    
            // Configure Browsershot with locale-specific settings
            $browsershot = Browsershot::html($html)
                ->setChromePath('/usr/bin/google-chrome-stable')
                ->format('A4')
                ->margins(16, 16, 16, 16)
                ->showBackground();
    
            // Add RTL support if needed
            if (in_array($locale, ['ar'])) {
                $browsershot->setOption('isRtl', true);
            }
    
            $browsershot->savePdf($pdfPath);
    
            // Log activity with locale information
            ActivityLog::create([
                'log_type' => 'Download',
                'model_type' => "Invoice",
                'model_id' => $invoice->id,
                'model_identifier' => $invoice->reference_number,
                'user_identifier' => $user?->name,
                'user_id' => $user->id,
                'user_email' => $user?->email,
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'description' => "Downloaded invoice {$invoice->reference_number} in {$locale}",
                'meta_data' => ['locale' => $locale],
            ]);
    
            return response()->download($pdfPath, $filename, [
                'Content-Type' => 'application/pdf',
            ])->deleteFileAfterSend(true);
    
        } catch (\Exception $e) {
            \Log::error('PDF Generation Error: ' . $e->getMessage());
            return response()->json([
                'error' => true,
                'message' => 'Error generating invoice PDF: ' . $e->getMessage(),
                'details' => $e->getTrace()
            ], 500);
        }
    }
    
    
}
