<?php

namespace App\Http\Middleware;

use App\Models\Brand;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** A brand's own domain shows its clients their invoices, never the team's dashboard */
class KeepBrandDomainsToPayPages
{
    public function handle(Request $request, Closure $next): Response
    {
        $host = strtolower($request->getHost());
        if ($host === parse_url((string) config('app.url'), PHP_URL_HOST)) {
            return $next($request);
        }

        $brandId = Brand::where('pay_domain', $host)->value('id');
        if ($brandId) {
            abort_unless($request->is('pay/*', 'pay-domain-check'), 404);
            $request->attributes->set('pay_brand_id', $brandId);
        }

        return $next($request);
    }
}
