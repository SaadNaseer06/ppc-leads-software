<?php

namespace App\Services;

use App\Models\DeletedLead;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Moves leads into deleted_leads (archive) then removes them from the live table.
 * Conversations keep their transcript (lead_id nullOnDelete); invoices block deletion.
 */
class LeadDeletion
{
    public function __construct(private LiveSignal $live) {}

    /**
     * @return array{deleted: int, skipped_invoices: int}
     */
    public function deleteMany(array $leadIds, User $actor): array
    {
        $leads = Lead::query()->visibleTo($actor)->whereIn('id', $leadIds)->get();
        $deleted = 0;
        $skipped = 0;

        foreach ($leads as $lead) {
            if ($lead->invoices()->exists()) {
                $skipped++;

                continue;
            }
            $this->archiveAndRemove($lead, $actor);
            $deleted++;
        }

        return ['deleted' => $deleted, 'skipped_invoices' => $skipped];
    }

    public function deleteOne(Lead $lead, User $actor): void
    {
        if ($lead->invoices()->exists()) {
            throw ValidationException::withMessages([
                'lead' => 'This lead has invoices. Remove or reassign those invoices before deleting the lead.',
            ]);
        }

        $this->archiveAndRemove($lead, $actor);
    }

    private function archiveAndRemove(Lead $lead, User $actor): void
    {
        DB::transaction(function () use ($lead, $actor) {
            $lead->loadMissing(['activities', 'website:id,name', 'brand:id,name', 'assignee:id,name']);

            DeletedLead::create([
                'original_lead_id' => $lead->id,
                'brand_id' => $lead->brand_id,
                'website_id' => $lead->website_id,
                'name' => $lead->name,
                'email' => $lead->email,
                'phone' => $lead->phone,
                'company' => $lead->company,
                'source' => $lead->source,
                'channel' => $lead->channel,
                'status' => $lead->status,
                'assignee_id' => $lead->assignee_id,
                'payload' => [
                    'lead' => $lead->attributesToArray(),
                    'brand' => $lead->brand?->only(['id', 'name']),
                    'website' => $lead->website?->only(['id', 'name']),
                    'assignee' => $lead->assignee?->only(['id', 'name']),
                    'activities' => $lead->activities->map->only([
                        'id', 'actor_id', 'type', 'body', 'data', 'created_at',
                    ])->all(),
                    'conversation_ids' => $lead->conversations()->pluck('id')->all(),
                ],
                'deleted_by' => $actor->id,
                'deleted_at' => now(),
            ]);

            // Activities cascade; conversations keep history with lead_id null
            $lead->delete();
        });
        $this->live->leads($lead->brand_id, $lead->assignee_id);
    }
}
