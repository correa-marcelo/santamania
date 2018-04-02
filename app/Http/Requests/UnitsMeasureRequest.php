<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UnitsMeasureRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize()
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules()
    {
        switch ($this->method()) {
            case 'POST':
                return [
                    'name'   => 'required',
                    'symbol' => 'required',
                ];
            case'PUT':
                $unitsMeasure = $this->route('units_measure');

                $id = is_object($unitsMeasure) ? $unitsMeasure->id : $unitsMeasure;

                return [
                    "name" => "required|unique:units_measures,name," . $id,
                ];
        }
    }
}