<?php

namespace App\Http\Requests\Concerns;

use Illuminate\Validation\Validator;

/** "Cualquier parámetro inválido responde 422; nunca se ignora en silencio" (docs/api-contract.md). */
trait RejectsUnknownParameters
{
    /** @return list<string> */
    abstract protected function allowedParameters(): array;

    public function after(): array
    {
        return [function (Validator $validator) {
            foreach (array_diff(array_keys($this->query()), $this->allowedParameters()) as $unknown) {
                $validator->errors()->add($unknown, __('validation.unknown_parameter', ['parameter' => $unknown]));
            }
        }];
    }
}
