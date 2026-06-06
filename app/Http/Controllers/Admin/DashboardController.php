<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Comment;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\Ticket;
use App\Models\User;
use App\Support\Invoices\InvoiceStatusService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function __construct()
    {
        $this->middleware('permission:dashboard.view');
    }

    public function show(): JsonResponse
    {
        $paidStatuses = [
            InvoiceStatusService::PAID,
            InvoiceStatusService::PROCESSING,
            InvoiceStatusService::SHIPPED,
            InvoiceStatusService::DELIVERED,
        ];

        $recentInvoices = Invoice::query()
            ->with(['user:id,name,phone,email', 'payments:id,invoice_id,status,reference'])
            ->latest()
            ->limit(8)
            ->get();

        $statusCounts = Invoice::query()
            ->select('status', DB::raw('COUNT(*) as aggregate'))
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        return response()->json([
            'kpis' => [
                'sales_total' => (float) Invoice::query()
                    ->whereIn('status', $paidStatuses)
                    ->sum('total'),
                'paid_orders' => Invoice::query()->whereIn('status', $paidStatuses)->count(),
                'new_orders' => Invoice::query()
                    ->whereIn('status', [
                        InvoiceStatusService::PENDING,
                        InvoiceStatusService::PAYMENT_PENDING,
                        InvoiceStatusService::PAID,
                    ])
                    ->count(),
                'low_stock_products' => Product::query()
                    ->where('status', 'active')
                    ->where('stock', '<=', 5)
                    ->count(),
                'open_tickets' => Ticket::query()->whereNotIn('status', ['closed', 'resolved'])->count(),
                'pending_comments' => Comment::query()->where('status', 'pending')->count(),
                'customers' => User::query()->count(),
            ],
            'invoice_statuses' => $statusCounts,
            'recent_invoices' => $recentInvoices,
            'low_stock_products' => Product::query()
                ->select(['id', 'name', 'sku', 'stock', 'status'])
                ->where('status', 'active')
                ->where('stock', '<=', 5)
                ->orderBy('stock')
                ->limit(8)
                ->get(),
        ]);
    }
}
