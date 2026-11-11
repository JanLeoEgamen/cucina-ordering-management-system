<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;

abstract class Controller
{
    // Status badge HTML for a table cell. These class names are only kept by
    // Tailwind because tailwind.config.js scans app/Http/Controllers/**.
    protected function statusBadge(bool $active): string
    {
        $classes = $active
            ? 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-300'
            : 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-300';

        return '<span data-status="' . ($active ? 'active' : 'inactive') . '" class="inline-flex rounded-full px-2 text-xs font-semibold leading-5 ' . $classes . '">'
            . e($active ? __('Active') : __('Inactive'))
            . '</span>';
    }

    // Badge for an order session: scheduled before its window, live inside it,
    // ended once it was closed by hand or its end time went past.
    protected function sessionBadge(string $status): string
    {
        return $this->badge($status, [
            'live' => 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-300',
            'scheduled' => 'bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-300',
            'ended' => 'bg-gray-200 text-gray-700 dark:bg-gray-700 dark:text-gray-300',
        ], 'bg-gray-200 text-gray-700 dark:bg-gray-700 dark:text-gray-300');
    }

    // Badge for an order (pending / completed / cancelled).
    protected function orderStatusBadge(string $status): string
    {
        return $this->badge($status, [
            'completed' => 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-300',
            'cancelled' => 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-300',
            'pending' => 'bg-amber-100 text-amber-800 dark:bg-amber-900 dark:text-amber-300',
        ], 'bg-amber-100 text-amber-800 dark:bg-amber-900 dark:text-amber-300');
    }

    // Badge for a payment (pending / verified / rejected).
    protected function paymentStatusBadge(string $status): string
    {
        return $this->badge($status, [
            'verified' => 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-300',
            'rejected' => 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-300',
            'pending' => 'bg-amber-100 text-amber-800 dark:bg-amber-900 dark:text-amber-300',
        ], 'bg-amber-100 text-amber-800 dark:bg-amber-900 dark:text-amber-300');
    }

    // One badge renderer for the three status columns above.
    protected function badge(string $status, array $classes, string $fallback): string
    {
        $colour = $classes[$status] ?? $fallback;

        return '<span data-status="' . e($status) . '" class="inline-flex rounded-full px-2 text-xs font-semibold leading-5 ' . $colour . '">'
            . e(__(ucfirst($status)))
            . '</span>';
    }

    // Success response for the AJAX forms: {status, message}.
    protected function successResponse(string $message): JsonResponse
    {
        return response()->json([
            'status' => 'success',
            'message' => $message,
        ]);
    }

    // Failure response for the AJAX forms: {status, message}, with the HTTP code.
    protected function errorResponse(string $message, int $status = 422): JsonResponse
    {
        return response()->json([
            'status' => 'failed',
            'message' => $message,
        ], $status);
    }
}
