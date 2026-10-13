<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreGpsPositionRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'vehicle_code' => ['bail', 'required', 'string', 'max:255', Rule::exists('vehicles', 'code')->where('is_active', true)],
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'recorded_at' => [
                'bail', 'required', 'string', 'date',
                'regex:/\A[1-9]\d{3}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,3})?(?:Z|[+-]\d{2}:\d{2})\z/',
            ],
            'accuracy_m' => ['nullable', 'numeric', 'between:0,9999999.999'],
            'speed_mps' => ['nullable', 'numeric', 'between:0,99999.999'],
            'heading_deg' => ['nullable', 'numeric', 'between:0,359.999'],
            'received_at' => ['missing'],
            'vehicle_id' => ['missing'],
            'bus_route_id' => ['missing'],
        ];
    }
}
