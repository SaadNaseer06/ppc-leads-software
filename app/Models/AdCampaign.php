<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A brand's name for one ad campaign, so leads show "Roof repair - Dallas" rather than 21345678901 */
class AdCampaign extends Model
{
    protected $guarded = ['id'];

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }
}
