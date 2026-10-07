<?php

namespace App\Http\Requests\Api\V1\Product;

use App\Http\Requests\ApiRequest;

/**
 * Payload de mise a jour d'un produit.
 *
 * Chaque champ est facultatif, ce qui autorise deux usages distincts et
 * legitimes : corriger un libelle sans toucher aux declinaisons, ou renvoyer la
 * liste complete des variantes pour la resynchroniser.
 *
 * L'absence de la cle `variants` signifie « variantes inchangees ». Une liste
 * vide signifie « plus aucune variante ». Le service fait cette distinction ;
 * la validation ne peut que la permettre, pas l'interpreter.
 */
final class UpdateProductRequest extends ApiRequest
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
            ...$this->productAttributes(partial: true),
            ...$this->variantAttributes(),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return $this->productAttributesMessages();
    }
}
