<?php

namespace App\Http\Requests\Api\V1\Order;

use App\Http\Requests\ApiRequest;

final class SyncPaymentReturnRequest extends ApiRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'transaction_id' => ['sometimes', 'nullable', 'string', 'min:3', 'max:120'],
        ];
    }
}
