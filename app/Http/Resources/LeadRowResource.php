<?php

namespace App\Http\Resources;

use App\Services\ContactMask;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** One row of the leads list */
class LeadRowResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $mask = $request->user() && ! $request->user()->seesFullContacts();

        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $mask ? ContactMask::email($this->email) : $this->email,
            'phone' => $mask ? ContactMask::phone($this->phone) : $this->phone,
            'company' => $this->company,
            'status' => $this->status,
            'source' => $this->source,
            'channel' => $this->channel,
            'campaign' => $this->utm_campaign,
            'submission_count' => $this->submission_count,
            'created_at' => $this->created_at,
            'website' => $this->website?->only(['id', 'name']),
            'brand' => $this->brand?->only(['id', 'name', 'merchant_name']),
            'assignee' => $this->assignee?->only(['id', 'name']),
        ];
    }
}
