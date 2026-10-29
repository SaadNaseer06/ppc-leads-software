<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** One row of the leads list */
class LeadRowResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'company' => $this->company,
            'status' => $this->status,
            'source' => $this->source,
            'channel' => $this->channel,
            'campaign' => $this->utm_campaign,
            'submission_count' => $this->submission_count,
            'created_at' => $this->created_at,
            'website' => $this->website?->only(['id', 'name']),
            'brand' => $this->brand?->only(['id', 'name']),
            'assignee' => $this->assignee?->only(['id', 'name']),
        ];
    }
}
