<?php

namespace App\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Erreur metier expected par un cas d'usage de l'API.
 *
 * Elle porte le code HTTP, le code d'erreur stable expose au client et des
 * details structurels. Le client n'a jamais a reconstruire le sens d'une
 * reponse HTTP a partir du message : `errorCode` est la seule valeur sur
 * laquelle un front doit brancher sa logique.
 *
 * Les instances ne doivent pas etre signalees a Sentry ou aux logs d'erreur :
 * elles sont enregistrees comme `dontReport` dans bootstrap/app.php, car une
 * erreur metier (stock insuffisant, panier vide) n'est pas un incident.
 */
class ApiException extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $details  Informations complementaires destinees au client (jamais de donnees sensibles).
     */
    public function __construct(
        string $message,
        protected readonly int $status = 500,
        protected readonly string $errorCode = 'INTERNAL_ERROR',
        protected readonly array $details = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $status, $previous);
    }

    public function status(): int
    {
        return $this->status;
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    /**
     * @return array<string, mixed>
     */
    public function details(): array
    {
        return $this->details;
    }
}
