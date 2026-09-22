<?php

namespace Database\Factories;

use App\Models\Payment;
use App\Models\PaymentInvoiceProof;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PaymentInvoiceProof>
 */
class PaymentInvoiceProofFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'payment_id' => Payment::factory(),
            'order_id' => fn (array $attributes) => Payment::query()->whereKey($attributes['payment_id'])->value('order_id'),
            'branch_id' => fn (array $attributes) => Payment::query()->whereKey($attributes['payment_id'])->value('branch_id'),
            'disk' => 'local', 'path' => 'payment-invoices/'.fake()->uuid().'.jpg',
            'original_name' => 'invoice.jpg', 'mime_type' => 'image/jpeg', 'size_bytes' => 1024,
            'uploaded_by_user_id' => User::factory(),
        ];
    }
}
