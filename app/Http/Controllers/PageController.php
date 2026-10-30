<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Api\BrandController;
use App\Http\Controllers\Api\ChatController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\InvoiceController;
use App\Http\Controllers\Api\LeadController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\WebsiteController;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Dashboard screens. Each page renders the same payload its JSON API endpoint
 * returns, so screens and API share one implementation of every query and
 * permission check.
 */
class PageController extends Controller
{
    /** Run an API controller action and return its JSON body as page props */
    private function payload(string $controller, string $method, array $parameters = []): array
    {
        return app()->call([app($controller), $method], $parameters)->getData(true);
    }

    public function dashboard(Request $request): Response
    {
        $range = in_array((int) $request->query('range'), [7, 30, 90], true) ? (int) $request->query('range') : 30;
        $request->query->set('range', $range);

        return Inertia::render('Dashboard', [
            // Lazy: a live refresh reloads only this prop
            'dashboard' => fn () => $this->payload(DashboardController::class, 'show'),
            'range' => $range,
        ]);
    }

    public function leads(Request $request): Response
    {
        return Inertia::render('Leads/Index', [
            // Lazy: a live refresh reloads only the list, not the pickers
            'page' => fn () => $this->payload(LeadController::class, 'index'),
            'lookups' => fn () => $this->payload(DashboardController::class, 'lookups'),
            'query' => (object) array_filter($request->query(), 'is_string'),
        ]);
    }

    public function lead(int $id): Response
    {
        return Inertia::render('Leads/Show', $this->payload(LeadController::class, 'show', ['id' => $id]));
    }

    public function chats(Request $request): Response
    {
        return Inertia::render('Chats', [
            'chats' => $this->payload(ChatController::class, 'index'),
            'query' => (object) array_filter($request->query(), 'is_string'),
        ]);
    }

    public function websites(): Response
    {
        return Inertia::render('Websites/Index', $this->payload(WebsiteController::class, 'index'));
    }

    public function website(int $id): Response
    {
        return Inertia::render('Websites/Show', $this->payload(WebsiteController::class, 'show', ['id' => $id]));
    }

    public function team(): Response
    {
        return Inertia::render('Team/Index', $this->payload(UserController::class, 'index'));
    }

    public function brands(): Response
    {
        return Inertia::render('Brands/Index', $this->payload(BrandController::class, 'index'));
    }

    public function invoices(): Response
    {
        return Inertia::render('Invoices', $this->payload(InvoiceController::class, 'index'));
    }

    public function finance(): Response
    {
        return Inertia::render('Finance/Index', $this->payload(InvoiceController::class, 'finance'));
    }

    public function reports(): Response
    {
        return Inertia::render('Reports', $this->payload(InvoiceController::class, 'salesByBrand'));
    }
}
