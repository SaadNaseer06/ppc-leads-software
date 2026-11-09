<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Brand;
use App\Services\PayDomain;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** How a brand appears to its clients on invoices: name, logo, colour and domain */
class BrandBrandingController extends Controller
{
    public function update(Request $request, int $id, PayDomain $domains): JsonResponse
    {
        $brand = $this->brand($request, $id);
        $data = $request->validate([
            'merchant_name' => ['nullable', 'string', 'max:120'],
            'brand_color' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'billing_email' => ['nullable', 'email', 'max:191'],
            'billing_address' => ['nullable', 'string', 'max:500'],
            'pay_domain' => ['nullable', 'string', 'max:191'],
        ], ['brand_color.regex' => 'Choose a colour like #0f766e']);

        $domain = isset($data['pay_domain']) && trim($data['pay_domain']) !== '' ? $domains->normalize($data['pay_domain']) : null;
        unset($data['pay_domain']);
        if ($request->filled('pay_domain') && ! $domain) {
            throw ValidationException::withMessages(['pay_domain' => 'Enter a domain name like pay.yourbrand.com']);
        }

        if ($domain !== $brand->pay_domain) {
            abort_unless($request->user()->canAssignBrandManagers(), 403, 'Only an Admin can change the invoice domain.');
            $this->assertDomainIsFree($domain, $brand);
            $data += ['pay_domain' => $domain, 'pay_domain_verified_at' => null];
        }

        $brand->update($data);
        ActivityLog::record($request->user()->id, 'brand.branding', 'Brand', $brand->id, ['pay_domain' => $brand->pay_domain]);

        return response()->json(['branding' => $brand->branding()]);
    }

    public function storeLogo(Request $request, int $id): JsonResponse
    {
        $brand = $this->brand($request, $id);
        // No SVG: it can carry scripts, and this file is served straight to clients
        $request->validate(['logo' => ['required', 'file', 'mimes:png,jpg,jpeg', 'max:1024', 'dimensions:max_width=3000,max_height=3000']], [
            'logo.mimes' => 'Use a PNG or JPG image',
            'logo.max' => 'The logo must be under 1 MB',
            'logo.dimensions' => 'The logo is too large. Use an image up to 3000 pixels wide',
        ]);

        $file = $request->file('logo');
        $name = "{$brand->id}-".Str::random(12).".{$file->extension()}";
        Storage::disk('brand_logos')->putFileAs('', $file, $name);
        $brand->forgetLogo();
        $brand->update(['logo_path' => "brand-logos/{$name}"]);
        ActivityLog::record($request->user()->id, 'brand.logo', 'Brand', $brand->id);

        return response()->json(['branding' => $brand->branding()], 201);
    }

    /** The small icon in the browser tab on the brand's invoice pages */
    public function storeFavicon(Request $request, int $id): JsonResponse
    {
        $brand = $this->brand($request, $id);
        // PNG or ICO only: no SVG, which can carry scripts
        $request->validate(['favicon' => ['required', 'file', 'mimes:png,ico', 'max:256']], [
            'favicon.mimes' => 'Use a PNG or ICO file',
            'favicon.max' => 'The favicon must be under 256 KB',
        ]);

        $file = $request->file('favicon');
        $name = "{$brand->id}-icon-".Str::random(12).".{$file->extension()}";
        Storage::disk('brand_logos')->putFileAs('', $file, $name);
        $brand->forgetFavicon();
        $brand->update(['favicon_path' => "brand-logos/{$name}"]);
        ActivityLog::record($request->user()->id, 'brand.favicon', 'Brand', $brand->id);

        return response()->json(['branding' => $brand->branding()], 201);
    }

    public function destroyFavicon(Request $request, int $id): JsonResponse
    {
        $brand = $this->brand($request, $id);
        $brand->forgetFavicon();
        $brand->update(['favicon_path' => null]);

        return response()->json(['branding' => $brand->branding()]);
    }

    public function destroyLogo(Request $request, int $id): JsonResponse
    {
        $brand = $this->brand($request, $id);
        $brand->forgetLogo();
        $brand->update(['logo_path' => null]);

        return response()->json(['branding' => $brand->branding()]);
    }

    /** Switches this brand's new invoice links to its domain, once the domain really reaches this app */
    public function checkDomain(Request $request, int $id, PayDomain $domains): JsonResponse
    {
        $brand = $this->brand($request, $id);
        abort_unless($request->user()->canAssignBrandManagers(), 403);
        abort_unless($brand->pay_domain, 422, 'Save a domain first.');

        $problem = $domains->problem($brand->pay_domain);
        $brand->update(['pay_domain_verified_at' => $problem ? null : now()]);
        ActivityLog::record($request->user()->id, 'brand.domain_check', 'Brand', $brand->id, ['connected' => ! $problem]);

        return $problem
            ? response()->json(['error' => $problem, 'branding' => $brand->branding()], 422)
            : response()->json(['branding' => $brand->branding()]);
    }

    private function brand(Request $request, int $id): Brand
    {
        abort_unless($request->user()->canAssignLeads(), 403);

        return Brand::query()->visibleTo($request->user())->findOrFail($id);
    }

    private function assertDomainIsFree(?string $domain, Brand $brand): void
    {
        if (! $domain) {
            return;
        }
        if ($domain === parse_url((string) config('app.url'), PHP_URL_HOST)) {
            throw ValidationException::withMessages(['pay_domain' => "That's this app's own address. Use a domain of the brand's."]);
        }
        validator(['pay_domain' => $domain], [
            'pay_domain' => [Rule::unique('brands', 'pay_domain')->ignore($brand->id)],
        ], ['pay_domain.unique' => 'Another brand already uses that domain'])->validate();
    }
}
