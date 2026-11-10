<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;

abstract class Controller
{
    // Success response for the AJAX forms: {status, message}.
    protected function successResponse(string $message): JsonResponse
    {
        return response()->json([
            'status' => 'success',
            'message' => $message,
        ]);
    }

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
}
