<?php

namespace App\Models\Finance;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RiderRemittanceItem extends Model
{
    protected $fillable = ['rider_remittance_id', 'cod_collection_id'];

    public function remittance(): BelongsTo { return $this->belongsTo(RiderRemittance::class); }
    public function codCollection(): BelongsTo { return $this->belongsTo(CodCollection::class); }
}
