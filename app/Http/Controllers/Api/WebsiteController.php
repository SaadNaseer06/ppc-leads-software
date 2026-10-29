<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Brand;
use App\Models\Conversation;
use App\Models\Lead;
use App\Models\WebhookEvent;
use App\Models\Website;
use App\Services\TawkService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class WebsiteController extends Controller
{
    public function __construct(private TawkService $tawk) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->canManageWebsites(), 403);

        $websites = Website::query()->visibleTo($user)->with('brand:id,name')
            ->join('brands', 'brands.id', '=', 'websites.brand_id')
            ->orderBy('brands.name')->orderBy('websites.name')
            ->select('websites.*')->get();

        $leads30d = Lead::whereIn('website_id', $websites->pluck('id'))
            ->where('created_at', '>=', now()->subDays(30))
            ->groupBy('website_id')->selectRaw('website_id, COUNT(*) AS n')->pluck('n', 'website_id');

        return response()->json([
            'websites' => $websites->map(fn (Website $w) => [
                ...$w->only(['id', 'name', 'domain', 'active', 'tawk_property_id', 'last_lead_at', 'last_chat_at']),
                'brand' => $w->brand->only(['id', 'name']),
                'has_tawk_secret' => (bool) $w->getRawOriginal('tawk_webhook_secret') || (bool) config('ppc.tawk_webhook_secret'),
                'leads_30d' => (int) ($leads30d[$w->id] ?? 0),
            ]),
            'brands' => Brand::query()->visibleTo($user)->orderBy('name')->get(['id', 'name']),
            // Chats arriving from Tawk properties that no website uses yet
            'unlinked_properties' => $user->hasGlobalAccess()
                ? Conversation::whereNull('website_id')->whereNotNull('property_id')
                    ->groupBy('property_id', 'property_name')
                    ->selectRaw('property_id, property_name, COUNT(*) AS conversations, MAX(last_message_at) AS last_message_at')
                    ->get()
                : [],
        ]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->canManageWebsites(), 403);
        $website = Website::query()->visibleTo($user)->with('brand:id,name')->findOrFail($id);

        return response()->json([
            'website' => [
                ...$website->only(['id', 'name', 'domain', 'brand_id', 'active', 'public_key', 'tawk_property_id', 'last_lead_at', 'last_chat_at']),
                'brand' => $website->brand->only(['id', 'name']),
                'has_tawk_secret' => (bool) $website->getRawOriginal('tawk_webhook_secret'),
                'embed_snippet' => $website->embedSnippet(),
                'intake_url' => $website->intakeUrl(),
                'tawk_webhook_url' => url('/api/webhooks/tawk'),
            ],
            'leads_30d' => Lead::where('website_id', $id)->where('created_at', '>=', now()->subDays(30))->count(),
            'recent_leads' => Lead::where('website_id', $id)->orderByDesc('created_at')->orderByDesc('id')->limit(6)
                ->get(['id', 'name', 'status', 'source', 'created_at']),
            'events' => $website->tawk_property_id
                ? WebhookEvent::where('property_id', $website->tawk_property_id)->latest('created_at')->limit(8)
                    ->get(['id', 'event', 'status', 'error', 'created_at'])
                : [],
            'brands' => Brand::query()->visibleTo($user)->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->canManageWebsites(), 403);
        $data = $this->validated($request, false);
        abort_unless($user->canAccessBrand($data['brand_id']), 403);

        $website = $this->saving(fn () => Website::create([
            ...$data,
            'public_key' => Website::generatePublicKey(),
            'active' => $data['active'] ?? true,
        ]));
        $this->tawk->linkConversations($website);
        ActivityLog::record($user->id, 'website.create', 'Website', $website->id, ['name' => $website->name, 'domain' => $website->domain]);

        return response()->json(['id' => $website->id], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->canManageWebsites(), 403);
        $website = Website::query()->visibleTo($user)->findOrFail($id);
        $data = $this->validated($request, true);
        if (isset($data['brand_id'])) {
            abort_unless($user->canAccessBrand($data['brand_id']), 403);
        }

        $previousProperty = $website->tawk_property_id;
        // An empty secret field in the form means "keep the current one"
        if (empty($data['tawk_webhook_secret'])) {
            unset($data['tawk_webhook_secret']);
        }
        if ($request->boolean('rotate_key')) {
            $data['public_key'] = Website::generatePublicKey();
        }
        $this->saving(fn () => $website->update($data));

        if ($website->tawk_property_id && $website->tawk_property_id !== $previousProperty) {
            $this->tawk->linkConversations($website);
        }
        ActivityLog::record($user->id, $request->boolean('rotate_key') ? 'website.rotate_key' : 'website.update', 'Website', $id,
            ['fields' => array_keys($request->except('tawk_webhook_secret'))]);

        return response()->json(['ok' => true, 'public_key' => $website->public_key]);
    }

    private function validated(Request $request, bool $partial): array
    {
        $req = $partial ? 'sometimes' : 'required';
        $data = $request->validate([
            'name' => [$req, 'string', 'max:120'],
            'domain' => [$req, 'string', 'max:191'],
            'brand_id' => [$req, 'integer', 'exists:brands,id'],
            'tawk_property_id' => ['sometimes', 'nullable', 'string', 'max:64'],
            'tawk_webhook_secret' => ['sometimes', 'nullable', 'string', 'max:191'],
            'active' => ['sometimes', 'boolean'],
        ], ['brand_id.required' => 'Choose a brand']);

        if (isset($data['domain'])) {
            $data['domain'] = Website::normalizeDomain($data['domain'])
                ?? throw ValidationException::withMessages(['domain' => 'Enter a valid domain, e.g. example.com']);
        }
        if (array_key_exists('tawk_property_id', $data)) {
            $data['tawk_property_id'] = trim((string) $data['tawk_property_id']) ?: null;
        }

        return $data;
    }

    private function saving(callable $save): mixed
    {
        try {
            return $save();
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['tawk_property_id' => 'That Tawk.to property is already linked to another website']);
        }
    }
}
