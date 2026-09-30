<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\ApiController;
use App\Http\Requests\Api\V1\Auth\LoginRequest;
use App\Http\Requests\Api\V1\Auth\RegisterRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\AuthService;
use App\Support\Api\CamelCase;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authentification par session.
 *
 * Aucun jeton n'est renvoye : l'identite transite uniquement par le cookie de
 * session, pose par le middleware StartSession. C'est ce qui permet de
 * declarer le cookie HttpOnly, donc inaccessible au JavaScript du front, ce
 * qu'un jeton lisible depuis la page ne permettrait pas.
 */
final class AuthController extends ApiController
{
    public function __construct(private readonly AuthService $auth) {}

    /**
     * Jeton CSRF destine a etre renvoye dans le header X-XSRF-TOKEN.
     *
     * Cette route doit rester publique et sans limitation de debit : c'est la
     * premiere requete du front, avant toute session, et c'est elle qui fait
     * poser le cookie XSRF-TOKEN. La mettre derriere `auth` rendrait
     * impossible toute premiere ecriture.
     */
    public function csrfToken(Request $request): JsonResponse
    {
        // CamelCase::keys applique la meme normalisation que les ressources et
        // que les erreurs, pour qu'aucune reponse de l'API ne Echappe au
        // contrat de nommage du fil.
        return response()->json(
            CamelCase::keys([
                'data' => [
                    'csrf_token' => $request->session()->token(),
                ],
            ]),
        );
    }

    /**
     * Inscription : cree le compte et ouvre sa session.
     */
    public function register(RegisterRequest $request): JsonResponse
    {
        $user = $this->auth->register($request, $request->body());

        return $this->jsonResource(UserResource::make($user), Response::HTTP_CREATED);
    }

    /**
     * Connexion : verifie les identifiants et ouvre la session.
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $data = $request->body();

        $user = $this->auth->attempt(
            $request,
            (string) $data['email'],
            (string) $data['password'],
            (bool) ($data['remember'] ?? false),
        );

        return $this->jsonResource(UserResource::make($user));
    }

    /**
     * Deconnexion : invalide la session et purge le jeton CSRF.
     */
    public function logout(Request $request): JsonResponse
    {
        $this->auth->logout($request);

        return response()->json([
            'data' => [
                'message' => 'Deconnexion effectuee.',
            ],
        ]);
    }

    /**
     * Profil du compte connecte.
     *
     * Le middleware `auth` garantit qu'un utilisateur existe, mais pas que le
     * type rendu est bien un User : on verifie plutot que de laisser une
     * conversion implicite.
     */
    public function me(Request $request): JsonResponse
    {
        $user = $request->user();

        abort_unless($user instanceof User, Response::HTTP_UNAUTHORIZED);

        return $this->jsonResource(UserResource::make($user));
    }
}
