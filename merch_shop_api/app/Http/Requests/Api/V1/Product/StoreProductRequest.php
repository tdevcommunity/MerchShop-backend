<?php

namespace App\Http\Requests\Api\V1\Product;

use App\Http\Requests\ApiRequest;
use Illuminate\Validation\Validator;

/**
 * Payload de création d'un produit.
 *
 * Un produit vendable porte toujours un prix, donc l'API refuse d'en créer un
 * qui n'en aurait pas : soit le produit est découpé en variantes, soit il
 * porte une `default_variant` dont le prix et le stock sont exigés. La
 * cohérence des deux est vérifiée en fin de validation, car elle porte sur la
 * présence des clés et non sur la valeur d'un champ.
 */
final class StoreProductRequest extends ApiRequest
{
    use ProductPayloadRules;

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
            ...$this->productAttributes(partial: false),
            ...$this->variantAttributes(),
            ...$this->defaultVariantAttributes(),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return $this->productAttributesMessages();
    }

    public function withValidator(Validator $validator): void
    {
        $this->withVariantConsistency($validator);
    }
}
