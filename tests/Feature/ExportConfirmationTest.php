<?php

namespace Tests\Feature;

use Tests\TestCase;

// The Export menu of every table asks first, in a dialog that moves and looks like <x-ui.modal>
// (the payment Verify / Reject / Undo confirmations); the payment and order popups use pill buttons.
class ExportConfirmationTest extends TestCase
{
    public function test_the_table_export_asks_for_confirmation(): void
    {
        $toolbar = file_get_contents(resource_path('views/components/ui/table-toolbar.blade.php'));
        $script = file_get_contents(resource_path('js/ui/table-toolbar.js'));

        $this->assertStringContainsString('data-confirm-text', $toolbar);
        $this->assertStringContainsString("import('./confirm-dialog.js')", $script);
        $this->assertFileExists(resource_path('js/ui/confirm-dialog.js'));
    }

    // The export confirmation must move and look like <x-ui.modal>, which the payment
    // Verify / Reject / Undo confirmations use: same backdrop and panel animation, pill buttons.
    public function test_the_export_confirmation_matches_the_modal_component(): void
    {
        $modal = file_get_contents(resource_path('views/components/ui/modal.blade.php'));
        $dialog = file_get_contents(resource_path('js/ui/confirm-dialog.js'));

        foreach (['bg-gray-900/60', 'backdrop-blur-sm', 'duration-300', 'scale-95', 'rounded-2xl', 'duration-200', '!opacity-100', '!scale-100'] as $class) {
            $this->assertStringContainsString($class, $modal, $class . ' (modal)');
            $this->assertStringContainsString($class, $dialog, $class . ' (confirm dialog)');
        }
    }

    public function test_the_payment_decision_buttons_are_pills_and_the_inline_confirmation_animates(): void
    {
        $payment = file_get_contents(resource_path('views/components/ui/payment-popup.blade.php'));
        $order = file_get_contents(resource_path('views/components/ui/order-popup.blade.php'));

        // No square-cornered action button is left next to the pill Cancel.
        $this->assertDoesNotMatchRegularExpression('/<button\b[^>]*\brounded-lg\b/s', $payment);
        $this->assertDoesNotMatchRegularExpression('/<button\b[^>]*\brounded-lg\b/s', $order);
        $this->assertStringNotContainsString('rounded-lg px-5', file_get_contents(resource_path('js/ui/order-popup.js')));

        // The bar that replaces the footer for Verify / Reject / Complete / Cancel fades in.
        $this->assertMatchesRegularExpression('/id="order-details-confirm"[^>]*class="[^"]*animate-fade-in/s', $order);
    }
}
