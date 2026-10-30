<?php

namespace App\Services;

use App\Enums\LeadSource;
use App\Enums\LeadStatus;
use App\Models\Brand;
use App\Models\Lead;
use App\Models\Website;
use App\Support\Attribution;
use App\Support\LeadText;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The single entry point for every new lead: website forms, Tawk.to chats and
 * manual entry all go through ingest().
 */
class LeadIntake
{
    public function __construct(private LeadAssignment $assignment, private LiveSignal $live) {}

    /**
     * @param  array{name?: ?string, email?: ?string, phone?: ?string, company?: ?string, message?: ?string,
     *   form_name?: ?string, attribution?: array, extra_fields?: array, ip_address?: ?string, user_agent?: ?string,
     *   actor_id?: ?int, assignee_id?: ?int, dedupe?: bool}  $input
     * @return array{lead_id: int, duplicate: bool, assignee_id: ?int}
     */
    public function ingest(int $brandId, ?Website $website, LeadSource $source, array $input): array
    {
        $email = LeadText::email($input['email'] ?? null);
        $phone = LeadText::clean($input['phone'] ?? null, 50);
        $phoneKey = LeadText::phoneKey($phone);
        $company = LeadText::clean($input['company'] ?? null);
        $message = LeadText::clean($input['message'] ?? null, 20_000);
        $name = LeadText::clean($input['name'] ?? null)
            ?? ($email ? strstr($email, '@', true) : null)
            ?? $phone
            ?? 'Website visitor';
        $attribution = $input['attribution'] ?? [];
        $extra = $input['extra_fields'] ?? [];

        if (($input['dedupe'] ?? true) && ($email || $phoneKey)) {
            $existing = Lead::where('brand_id', $brandId)
                ->where('created_at', '>=', now()->subDays(config('ppc.dedupe_window_days')))
                ->where(function ($q) use ($email, $phoneKey) {
                    if ($email) {
                        $q->orWhere('email', $email);
                    }
                    if ($phoneKey) {
                        $q->orWhere('phone_key', $phoneKey);
                    }
                })
                ->orderByDesc('created_at')
                ->first();

            if ($existing) {
                $this->recordResubmission($existing, $website, $source, $email, $phone, $company, $message, $input, $attribution, $extra);
                $this->live->leads($brandId, $existing->assignee_id);

                return ['lead_id' => $existing->id, 'duplicate' => true, 'assignee_id' => $existing->assignee_id];
            }
        }

        $lead = DB::transaction(function () use ($brandId, $website, $source, $name, $email, $phone, $phoneKey, $company, $message, $attribution, $extra, $input) {
            $lead = Lead::create([
                'brand_id' => $brandId,
                'website_id' => $website?->id,
                'name' => $name,
                'email' => $email,
                'phone' => $phone,
                'phone_key' => $phoneKey,
                'company' => $company,
                'message' => $message,
                'source' => $source->value,
                'channel' => Attribution::channel($attribution, $website?->domain)->value,
                'utm_source' => $attribution['utm_source'] ?? null,
                'utm_medium' => $attribution['utm_medium'] ?? null,
                'utm_campaign' => $attribution['utm_campaign'] ?? null,
                'utm_term' => $attribution['utm_term'] ?? null,
                'utm_content' => $attribution['utm_content'] ?? null,
                'click_id' => $attribution['click_id'] ?? null,
                'click_id_type' => $attribution['click_id_type'] ?? null,
                'landing_page' => $attribution['landing_page'] ?? null,
                'page_url' => $attribution['page_url'] ?? null,
                'referrer' => $attribution['referrer'] ?? null,
                'form_name' => LeadText::clean($input['form_name'] ?? null),
                'ip_address' => LeadText::clean($input['ip_address'] ?? null, 45),
                'user_agent' => LeadText::clean($input['user_agent'] ?? null, 512),
                'metadata' => $extra ? ['fields' => $extra] : null,
                'search_text' => LeadText::searchText($name, $email, $phone, $company),
                'last_activity_at' => now(),
            ]);
            $lead->activities()->create([
                'actor_id' => $input['actor_id'] ?? null,
                'type' => 'created',
                'data' => ['source' => $source->value, 'website_id' => $website?->id],
            ]);

            return $lead;
        });

        $this->touchWebsite($website, $source);
        Log::info('Lead created', ['lead_id' => $lead->id, 'source' => $source->value, 'website_id' => $website?->id]);

        $assigneeId = null;
        if (! empty($input['assignee_id'])) {
            $this->assignment->assign([$lead->id], (int) $input['assignee_id'], $input['actor_id'] ?? null);
            $assigneeId = (int) $input['assignee_id'];
        } elseif (Brand::whereKey($brandId)->value('auto_assign_enabled')) {
            // The lead is already saved; if routing fails it waits in the unassigned queue
            try {
                $assigneeId = $this->assignment->autoAssign($lead);
            } catch (\Throwable $e) {
                report($e);
            }
        }

        $this->live->leads($brandId, $assigneeId);

        return ['lead_id' => $lead->id, 'duplicate' => false, 'assignee_id' => $assigneeId];
    }

    private function recordResubmission(Lead $existing, ?Website $website, LeadSource $source, ?string $email, ?string $phone, ?string $company, ?string $message, array $input, array $attribution, array $extra): void
    {
        $reopen = $existing->status === LeadStatus::Lost->value;
        $filled = [
            'email' => $existing->email ?? $email,
            'phone' => $existing->phone ?? $phone,
            'company' => $existing->company ?? $company,
        ];

        DB::transaction(function () use ($existing, $filled, $reopen, $website, $source, $message, $input, $attribution, $extra) {
            $existing->fill([
                ...$filled,
                'phone_key' => LeadText::phoneKey($filled['phone']),
                'search_text' => LeadText::searchText($existing->name, $filled['email'], $filled['phone'], $filled['company']),
                'submission_count' => $existing->submission_count + 1,
                'last_activity_at' => now(),
            ]);
            if ($reopen) {
                $existing->status = LeadStatus::New->value;
            }
            $existing->save();

            $existing->activities()->create([
                'type' => 'resubmitted',
                'body' => $message,
                'data' => [
                    'source' => $source->value,
                    'website_id' => $website?->id,
                    'form_name' => $input['form_name'] ?? null,
                    'page_url' => $attribution['page_url'] ?? null,
                    'reopened' => $reopen,
                    'fields' => $extra,
                ],
            ]);
        });
        $this->touchWebsite($website, $source);
    }

    private function touchWebsite(?Website $website, LeadSource $source): void
    {
        $website?->forceFill([$source === LeadSource::Form ? 'last_lead_at' : 'last_chat_at' => now()])->saveQuietly();
    }

    // ------------------------------------------------------------------------
    // Mapping arbitrary form payloads (embed script, Elementor, CF7, Zapier...)
    // ------------------------------------------------------------------------

    private const FIELD_ALIASES = [
        'name' => ['name', 'fullname', 'yourname', 'contactname', 'customername', 'clientname'],
        'first_name' => ['firstname', 'fname', 'first', 'givenname'],
        'last_name' => ['lastname', 'lname', 'last', 'surname', 'familyname'],
        'email' => ['email', 'emailaddress', 'youremail', 'mail', 'useremail', 'contactemail'],
        'phone' => ['phone', 'phonenumber', 'tel', 'telephone', 'mobile', 'mobilenumber', 'mobilephone', 'cell',
            'cellphone', 'yourphone', 'contactnumber', 'whatsapp', 'yourtel'],
        'company' => ['company', 'companyname', 'business', 'businessname', 'organization', 'organisation'],
        'message' => ['message', 'yourmessage', 'comments', 'comment', 'description', 'details', 'enquiry', 'inquiry',
            'query', 'notes', 'msg', 'question', 'requirements', 'projectdetails'],
        'form_name' => ['formname', 'formtitle', 'formid', 'form'],
    ];

    private const HONEYPOT_KEYS = ['gotcha', 'honeypot', 'hp', 'websitehp', 'botfield'];

    private const IGNORED_KEY = '/^(token|wpnonce|wpcf7.*|grecaptcharesponse|hcaptcharesponse|cfturnstileresponse|csrf.*|action|submit|redirect|next|referer|referertitle|postid|queriedid|password.*|pass|cvv|cvc|cardnumber|ccnumber)$/';

    private const ATTRIBUTION_KEY = '/^(utm_(source|medium|campaign|term|content)|gclid|gbraid|wbraid|msclkid|dclid|fbclid|ttclid|li_fat_id|landing_?page|page_?url|referr?er|first_seen)$/i';

    /**
     * Accepts the embed script's shape ({fields, attribution, page, form}) or any
     * flat key/value payload, and maps it onto lead fields.
     *
     * @return array{name: ?string, email: ?string, phone: ?string, company: ?string, message: ?string,
     *   form_name: ?string, attribution: array, extra_fields: array, is_spam: bool}
     */
    public function parseSubmission(array $body): array
    {
        $isEnvelope = isset($body['fields']) && is_array($body['fields']);
        $rawFields = $isEnvelope ? $body['fields'] : $body;
        $rawAttribution = is_array($body['attribution'] ?? null) ? $body['attribution'] : [];
        $page = is_array($body['page'] ?? null) ? $body['page'] : [];
        $form = is_array($body['form'] ?? null) ? $body['form'] : [];

        $aliases = [];
        foreach (self::FIELD_ALIASES as $field => $names) {
            foreach ($names as $alias) {
                $aliases[$alias] = $field;
            }
        }

        $mapped = [];
        $extra = [];
        $flatAttribution = [];
        $isSpam = false;

        foreach ($rawFields as $key => $raw) {
            $key = (string) $key;
            if (! $isEnvelope && in_array($key, ['attribution', 'page', 'form'], true)) {
                continue;
            }
            $value = $this->fieldValue($raw);
            $normalized = $this->normalizeKey($key);

            if (in_array($normalized, self::HONEYPOT_KEYS, true)) {
                $isSpam = $isSpam || $value !== null;

                continue;
            }
            if (preg_match(self::ATTRIBUTION_KEY, $key)) {
                $flatAttribution[strtolower($key)] = $value;

                continue;
            }
            if ($value === null || preg_match(self::IGNORED_KEY, $normalized)) {
                continue;
            }

            $field = $aliases[$normalized] ?? null;
            if ($field && ! isset($mapped[$field])) {
                $mapped[$field] = $value;
            } elseif (count($extra) < 50) {
                $extra[mb_substr($key, 0, 100)] = mb_substr($value, 0, 1000);
            }
        }

        $name = $mapped['name'] ?? (trim(($mapped['first_name'] ?? '').' '.($mapped['last_name'] ?? '')) ?: null);

        $attr = [...$flatAttribution, ...$rawAttribution];
        $pageUrl = LeadText::clean($page['url'] ?? $attr['page_url'] ?? $attr['pageurl'] ?? null, 2048);
        $landing = LeadText::clean($attr['landing_page'] ?? $attr['landingpage'] ?? null, 2048);

        return [
            'name' => $name,
            'email' => $mapped['email'] ?? null,
            'phone' => $mapped['phone'] ?? null,
            'company' => $mapped['company'] ?? null,
            'message' => $mapped['message'] ?? null,
            'form_name' => LeadText::clean($form['name'] ?? $form['id'] ?? null) ?? ($mapped['form_name'] ?? null),
            'attribution' => Attribution::merge(
                Attribution::fromRecord($attr),
                Attribution::fromUrl($landing),
                Attribution::fromUrl($pageUrl),
                [
                    'landing_page' => $landing,
                    'page_url' => $pageUrl,
                    'referrer' => LeadText::clean($attr['referrer'] ?? $attr['referer'] ?? null, 2048),
                ],
            ),
            'extra_fields' => $extra,
            'is_spam' => $isSpam,
        ];
    }

    private function normalizeKey(string $key): string
    {
        $key = strtolower($key);
        $key = preg_replace('/^fields\[(.+?)\](\[value\])?$/', '$1', $key);

        return preg_replace('/[^a-z0-9]/', '', $key);
    }

    private function fieldValue(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (is_array($value)) {
            // Elementor sends {id, title, value}; checkbox groups send lists
            if (array_key_exists('value', $value)) {
                return $this->fieldValue($value['value']);
            }
            $parts = array_filter(array_map(fn ($v) => $this->fieldValue($v), $value));

            return $parts ? implode(', ', $parts) : null;
        }

        return LeadText::clean(is_bool($value) ? ($value ? 'yes' : 'no') : $value, 5000);
    }
}
