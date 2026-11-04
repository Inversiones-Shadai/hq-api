<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

abstract class BaseRequest extends FormRequest
{
    abstract public function rules(): array;

    public function authorize(): bool
    {
        return true;
    }

    public function messages(): array
    {
        return array_merge(
            $this->defaultMessages(),
            $this->customMessages()
        );
    }

    public function attributes(): array
    {
        return $this->customAttributes();
    }

    protected function customMessages(): array
    {
        return [];
    }

    protected function customAttributes(): array
    {
        return [];
    }

    protected function defaultMessages(): array
    {
        return [
            'required' => 'El campo :attribute es obligatorio.',
            'required_if' => 'El campo :attribute es obligatorio cuando :other es :value.',
            'required_with' => 'El campo :attribute es obligatorio cuando se proporciona :values.',
            'required_without' => 'El campo :attribute es obligatorio cuando no se proporciona :values.',
            // Estas reglas controlan cuándo validar; Laravel no genera errores propios para ellas.
            'sometimes' => 'El campo :attribute debe ser válido cuando se proporciona.',
            'nullable' => 'El campo :attribute debe ser válido o estar vacío.',
            'string' => 'El campo :attribute debe ser una cadena de texto.',
            'integer' => 'El campo :attribute debe ser un número entero.',
            'numeric' => 'El campo :attribute debe ser un número.',
            'decimal' => 'El campo :attribute debe tener :decimal decimales.',
            'email' => 'El campo :attribute debe ser una dirección de correo electrónico válida.',
            'unique' => 'El valor del campo :attribute ya está registrado.',
            'exists' => 'El valor seleccionado para :attribute no existe.',
            'min' => [
                'numeric' => 'El campo :attribute debe ser al menos :min.',
                'string' => 'El campo :attribute debe tener al menos :min caracteres.',
                'array' => 'El campo :attribute debe contener al menos :min elementos.',
                'file' => 'El archivo :attribute debe pesar al menos :min kilobytes.',
            ],
            'max' => [
                'numeric' => 'El campo :attribute no debe ser mayor que :max.',
                'string' => 'El campo :attribute no debe exceder :max caracteres.',
                'array' => 'El campo :attribute no debe contener más de :max elementos.',
                'file' => 'El archivo :attribute no debe pesar más de :max kilobytes.',
            ],
            'between' => [
                'numeric' => 'El campo :attribute debe estar entre :min y :max.',
                'string' => 'El campo :attribute debe tener entre :min y :max caracteres.',
                'array' => 'El campo :attribute debe contener entre :min y :max elementos.',
                'file' => 'El archivo :attribute debe pesar entre :min y :max kilobytes.',
            ],
            'boolean' => 'El campo :attribute debe ser verdadero o falso.',
            'array' => 'El campo :attribute debe ser una lista de elementos.',
            'date' => 'El campo :attribute debe ser una fecha válida.',
            'date_format' => 'El campo :attribute debe tener el formato :format.',
            'before' => 'El campo :attribute debe ser una fecha anterior a :date.',
            'after' => 'El campo :attribute debe ser una fecha posterior a :date.',
            'confirmed' => 'La confirmación del campo :attribute no coincide.',
            'same' => 'El campo :attribute debe coincidir con :other.',
            'different' => 'El campo :attribute debe ser diferente de :other.',
            'in' => 'El campo :attribute debe ser uno de los siguientes valores: :values.',
            'not_in' => 'El campo :attribute contiene un valor no permitido.',
            'regex' => 'El formato del campo :attribute no es válido.',
            'image' => 'El campo :attribute debe ser una imagen.',
            'mimes' => 'El archivo :attribute debe ser de uno de estos tipos: :values.',
            'mimetypes' => 'El archivo :attribute debe tener uno de estos tipos MIME: :values.',
            'file' => 'El campo :attribute debe ser un archivo.',
            'url' => 'El campo :attribute debe ser una URL válida.',
            'uuid' => 'El campo :attribute debe ser un UUID válido.',
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(response()->json([
            'message' => $validator->errors()->first(),
            'code' => 422,
            'data' => ['errors' => $validator->errors()->toArray()],
            'meta' => null,
        ], 422));
    }
}
