<?php

namespace App\Http\Requests;

use App\Models\ShippySetting;
use App\Models\User;

/** A question typed into Test Shippy: the same limits as a guest's, asked by an account that manages Shippy. */
class TestShippyRequest extends StoreShippyMessageRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user() instanceof User && $this->user()->can('update', ShippySetting::class);
    }
}
