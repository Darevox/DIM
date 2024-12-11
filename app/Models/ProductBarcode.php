<?php

    namespace App\Models;

    use Illuminate\Database\Eloquent\Model;
    use Illuminate\Database\Eloquent\Relations\BelongsTo;
    class ProductBarcode extends Model
    {
        protected $fillable = ['product_id', 'barcode'];

        public function product(): BelongsTo
        {
            return $this->belongsTo(Product::class);
        }
    }