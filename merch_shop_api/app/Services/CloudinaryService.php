<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Téléversement d'images vers Cloudinary via l'API REST signée.
 *
 * Aucun SDK tiers n'est requis : l'API d'upload Cloudinary accepte un POST
 * multipart signé avec HMAC-SHA1. Le service lit ses identifiants depuis
 * config('cloudinary.*') et retourne l'URL sécurisée de l'image uploadée,
 * ou null si les identifiants sont absents ou si l'upload échoue.
 *
 * Utilisé uniquement par ProductController ; injectable via le conteneur
 * Laravel (pas besoin d'enregistrement manuel dans un ServiceProvider).
 */
final class CloudinaryService
{
    private string $cloudName;
    private string $apiKey;
    private string $apiSecret;
    private string $folder;

    public function __construct()
    {
        $this->cloudName = (string) config('cloudinary.cloud_name', '');
        $this->apiKey    = (string) config('cloudinary.api_key', '');
        $this->apiSecret = (string) config('cloudinary.api_secret', '');
        $this->folder    = (string) config('cloudinary.folder', 'merch-shop/products');
    }

    /**
     * Envoie $file sur Cloudinary et retourne son secure_url.
     *
     * Retourne null si les identifiants sont manquants ou si l'upload
     * échoue (réseau, quota, etc.). Dans ce cas un Log::warning est émis
     * pour qu'un opérateur puisse diagnostiquer sans que l'enregistrement
     * du produit échoue pour autant.
     */
    public function upload(UploadedFile $file): ?string
    {
        if ($this->cloudName === '' || $this->apiKey === '' || $this->apiSecret === '') {
            Log::warning('Cloudinary non configuré — upload ignoré.');

            return null;
        }

        $timestamp = (string) time();
        $publicId  = $this->buildPublicId($file);

        $signature = $this->sign($publicId, $timestamp);

        $cloudName = $this->cloudName;
        $endpoint  = "https://api.cloudinary.com/v1_1/{$cloudName}/image/upload";

        $response = Http::attach(
            'file',
            file_get_contents($file->getRealPath()),
            $file->getClientOriginalName(),
        )->post($endpoint, [
            'api_key'   => $this->apiKey,
            'timestamp' => $timestamp,
            'folder'    => $this->folder,
            'public_id' => $publicId,
            'signature' => $signature,
        ]);

        if (! $response->successful()) {
            Log::error('CloudinaryService: échec de l\'upload.', [
                'status' => $response->status(),
                'body'   => $response->body(),
            ]);

            return null;
        }

        $secureUrl = $response->json('secure_url');

        if (! $secureUrl) {
            Log::error('CloudinaryService: secure_url absent de la réponse.', [
                'body' => $response->body(),
            ]);

            return null;
        }

        return (string) $secureUrl;
    }

    /**
     * Construit un public_id stable à partir du nom de fichier original.
     *
     * Le suffixe uniqid() évite les collisions si deux admins uploadent un
     * fichier du même nom ; le dossier n'est pas préfixé ici car il est
     * transmis séparément dans le champ `folder` du POST.
     */
    private function buildPublicId(UploadedFile $file): string
    {
        $base = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
        $base = preg_replace('/[^a-z0-9]+/', '-', strtolower($base));
        $base = trim((string) $base, '-') ?: 'product';

        return $base . '_' . uniqid('', false);
    }

    /**
     * Signature HMAC-SHA1 des paramètres envoyés à Cloudinary.
     *
     * Cloudinary exige que tous les paramètres POST (hors api_key et file)
     * soient triés alphabétiquement, concaténés sous la forme clé=valeur&…,
     * puis suivis de l'api_secret avant le hachage SHA1.
     */
    private function sign(string $publicId, string $timestamp): string
    {
        $params = [
            'folder'    => $this->folder,
            'public_id' => $publicId,
            'timestamp' => $timestamp,
        ];

        ksort($params);

        $toSign = implode('&', array_map(
            static fn (string $k, string $v): string => $k . '=' . $v,
            array_keys($params),
            array_values($params),
        )) . $this->apiSecret;

        return hash('sha1', $toSign);
    }
}
