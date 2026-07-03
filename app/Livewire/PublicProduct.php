<?php

namespace App\Livewire;

use App\Models\Product;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Public, unauthenticated landing page reached by scanning a product's QR code
 * (URL format /p/{token}). Shows the product identity and its latest QC verdict
 * so anyone in the field can confirm an item without a dashboard login.
 */
#[Layout('layouts.guest', ['title' => 'Product Status'])]
class PublicProduct extends Component
{
    public Product $product;

    public function mount(string $token): void
    {
        $this->product = Product::with('latestDetection')
            ->where('qr_token', $token)
            ->firstOrFail();
    }

    public function render()
    {
        return view('livewire.public-product', [
            'detection' => $this->product->latestDetection,
        ]);
    }
}
