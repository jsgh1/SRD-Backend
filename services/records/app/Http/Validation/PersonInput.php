<?php

namespace App\Http\Validation;

use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class PersonInput
{
    public static function save(Request $r): array
    {
        $complete = $r->input('status') === 'complete';
        $required = $complete ? 'required' : 'nullable';

        return $r->validate(['positions_version' => 'sometimes|integer|min:0', 'schema_version' => 'sometimes|integer|min:0', 'custom_values' => 'sometimes|array|max:20', 'custom_values.*' => 'nullable|string|max:500', 'document_type' => 'required|in:RC,TI,CC,CE,NIT', 'document_number' => 'required|string|max:30|regex:/^[0-9A-Za-z-]+$/', 'first_names' => 'required|string|max:120', 'last_names' => "$required|string|max:120", 'status' => 'required|in:pending,complete', 'affiliated' => "$required|boolean", 'zone' => "$required|in:rural,urban", 'gender' => "$required|in:male,female,other", 'birth_date' => "$required|date_format:Y-m-d|before_or_equal:".now('America/Bogota')->toDateString(), 'phone' => "$required|string|max:20|regex:/^[+0-9 ()-]{7,20}$/", 'email' => "$required|email|max:254", 'position_code' => "$required|string|max:60|regex:/^[a-z0-9-]+$/D", 'descriptive_role' => "$required|in:admin,registrar,treasurer,auditor,viewer", 'property_name' => ($complete && $r->input('zone') === 'rural' ? 'required' : 'nullable').'|string|max:160', 'address' => ($complete && $r->input('zone') === 'urban' ? 'required' : 'nullable').'|string|max:180', 'neighborhood' => ($complete && $r->input('zone') === 'urban' ? 'required' : 'nullable').'|string|max:100', 'note' => 'nullable|string|max:4000', 'version' => 'sometimes|integer|min:1', 'authorization_basis' => ($complete ? 'required' : 'nullable|required_with:authorization_purpose').'|string|max:120', 'authorization_purpose' => ($complete ? 'required' : 'nullable|required_with:authorization_basis').'|string|max:1000']);
    }

    public static function filters(Request $r): array
    {
        $filters = $r->validate(['document_type' => 'nullable|in:RC,TI,CC,CE,NIT', 'gender' => 'nullable|in:male,female,other', 'descriptive_role' => 'nullable|in:admin,registrar,treasurer,auditor,viewer', 'position_code' => 'nullable|string|max:60|regex:/^[a-z0-9-]+$/D', 'birth_date_from' => 'sometimes|required|date_format:Y-m-d', 'birth_date_to' => 'sometimes|required|date_format:Y-m-d', 'registered_from' => 'sometimes|required|date_format:Y-m-d', 'registered_to' => 'sometimes|required|date_format:Y-m-d', 'custom_filters' => 'sometimes|array|list|max:3', 'custom_filters.*' => 'required|array:field_id,value,operator,value_to', 'custom_filters.*.field_id' => 'required|uuid|distinct:strict', 'custom_filters.*.value' => 'required|string|max:120', 'custom_filters.*.operator' => 'sometimes|required|in:eq,between', 'custom_filters.*.value_to' => 'sometimes|required|string|max:120', 'q' => 'nullable|string|max:120', 'status' => 'nullable|in:pending,complete', 'zone' => 'nullable|in:rural,urban', 'affiliated' => 'nullable|boolean', 'page' => 'nullable|integer|min:1', 'page_size' => 'nullable|in:10,25,50']);
        if (array_key_exists('birth_date_from', $filters) !== array_key_exists('birth_date_to', $filters)) throw ValidationException::withMessages(['birth_date_from' => 'Indica ambas fechas de nacimiento.']);
        if (isset($filters['birth_date_from']) && $filters['birth_date_to'] < $filters['birth_date_from']) throw ValidationException::withMessages(['birth_date_to' => 'La fecha final no puede ser anterior a la inicial.']);
        if (array_key_exists('registered_from', $filters) !== array_key_exists('registered_to', $filters)) throw ValidationException::withMessages(['registered_from' => 'Indica ambas fechas de registro.']);
        if (isset($filters['registered_from']) && $filters['registered_to'] < $filters['registered_from']) throw ValidationException::withMessages(['registered_to' => 'La fecha final de registro no puede ser anterior a la inicial.']);
        return $filters;
    }
}
