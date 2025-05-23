<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Facades\DB;

class Sale extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'team_id',
        'client_id',
        'cash_source_id',
        'reference_number',
        'total_amount',
        'paid_amount',
        'tax_amount',
        'discount_amount',
        'payment_status',
        'status',
        'type',
        'sale_date',
        'due_date',
        'notes',
    ];

    protected $casts = [
        'total_amount' => 'decimal:2',
        'paid_amount' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'sale_date' => 'date',
        'due_date' => 'date',
    ];

    // Relationships
    public function team()
    {
        return $this->belongsTo(Team::class);
    }

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function cashSource()
    {
        return $this->belongsTo(CashSource::class);
    }

    public function items()
    {
        return $this->hasMany(SaleItem::class);
    }

    public function transactions()
    {
        return $this->morphMany(CashTransaction::class, 'transactionable');
    }

    public function invoice()
    {
        return $this->morphOne(Invoice::class, 'invoiceable');
    }

    // Scopes
    public function scopePaid($query)
    {
        return $query->where('payment_status', 'paid');
    }

    public function scopeUnpaid($query)
    {
        return $query->where('payment_status', 'unpaid');
    }

    public function scopePartial($query)
    {
        return $query->where('payment_status', 'partial');
    }

    // Helper methods
    public function addPayment($amount, $cashSource, $paymentDate = null, $referenceNumber = null, $paymentMethod = 'cash', $notes = null)
{
    if ($amount > ($this->total_amount - $this->paid_amount)) {
        throw new \Exception('Payment amount exceeds remaining balance');
    }

    $transaction = null;

    DB::transaction(function () use ($amount, $cashSource, $paymentDate, $referenceNumber, $paymentMethod, $notes, &$transaction) {
        // Add to cash source
        $cashSource->deposit($amount, "Payment received for sale #{$this->reference_number}");

        // Update client balance if client exists
        if ($this->client_id) {
            $this->client->updateBalance($amount, 'subtract');
        }

        // Update sale payment status
        $this->paid_amount += $amount;
        $this->payment_status = $this->paid_amount >= $this->total_amount ? 'paid' : 'partial';
        $this->save();

        // Create transaction record
        $transaction = $this->transactions()->create([
            'team_id' => $this->team_id,
            'cash_source_id' => $cashSource->id,
            'amount' => $amount,
            'type' => 'Sale Payment',
            'payment_method' => $paymentMethod, // Add payment method
            'transaction_date' => $paymentDate ? new \DateTime($paymentDate) : now(),
            'reference_number' => $referenceNumber,
            'description' => $notes ?: "Payment received for sale #{$this->reference_number}",
        ]);
    });

    return $transaction;
}

public function calculateTotals()
{
    $subtotal = 0;
    $taxAmount = 0;
    $itemDiscountTotal = 0;
    
    foreach ($this->items as $item) {
        $itemSubtotal = $item->quantity * $item->unit_price;
        $itemDiscountAmount = $item->discount_amount ?? 0;
        
        // Track item-level discounts separately
        $itemDiscountTotal += $itemDiscountAmount;
        
        // Apply item discount before calculating tax
        $taxableAmount = $itemSubtotal - $itemDiscountAmount;
        
        // Calculate tax on discounted amount
        $itemTaxAmount = ($taxableAmount * $item->tax_rate) / 100;
        
        // Add to total tax
        $taxAmount += $itemTaxAmount;
        
        // Add to subtotal (before tax)
        $subtotal += $itemSubtotal;
    }
    
    // Get the already-stored sale-level discount
    $saleDiscount = $this->discount_amount ?? 0;
    
    // Calculate final total:
    // 1. Start with subtotal (sum of all item prices * quantities)
    // 2. Subtract all item discounts
    // 3. Add the total tax amount
    // 4. Subtract the sale-level discount
    $totalAmount = ($subtotal - $itemDiscountTotal) + $taxAmount - $saleDiscount;
    
    // Update the model
    $this->total_amount = $totalAmount;
    $this->tax_amount = $taxAmount;
    
    $this->save();
    
    return $this;
}


    public function __construct(array $attributes = [])
{
    parent::__construct($attributes);
    $this->attributes['type'] = $this->attributes['type'] ?? 'sale';
}
}