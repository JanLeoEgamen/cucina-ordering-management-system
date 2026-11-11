<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ImageViewerTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_layout_carries_the_image_viewer_once(): void
    {
        $this->actingAs($this->superAdmin())
            ->get(route('dishes.index'))
            ->assertOk()
            ->assertSee('id="image-viewer"', false);

        $html = $this->actingAs($this->superAdmin())->get(route('dishes.index'))->getContent();

        $this->assertSame(1, substr_count($html, 'id="image-viewer"'));
    }

    public function test_the_drawer_images_are_zoomable(): void
    {
        $this->actingAs($this->superAdmin())
            ->get(route('dishes.index'))
            ->assertSee('id="view-dish-image"', false)
            ->assertSee('data-zoom', false);

        $this->actingAs($this->superAdmin())
            ->get(route('payment-methods.index'))
            ->assertSee('id="view-method-qr"', false)
            ->assertSee('data-zoom', false);
    }
}
