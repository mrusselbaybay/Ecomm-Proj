<?php

namespace App\Http\Controllers;

use App\Models\CourierApplication;
use App\Models\LogisticsCompany;
use App\Models\Profile;
use App\Services\FileStorage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Uploaded files: serves private files behind signed URLs, and lets the
 * browser upload to / get signed links for the buckets it's allowed to.
 */
class FileController extends Controller
{
    /** Buckets the browser may upload into, each scoped to the user's own folder. */
    private const CLIENT_UPLOAD_BUCKETS = ['avatars', 'product-images', 'return-evidence'];

    public function __construct(private readonly FileStorage $files) {}

    /** GET /files/{bucket}/{path} — only reachable through a signed URL. */
    public function show(string $bucket, string $path): StreamedResponse
    {
        abort_if($this->files->isPublic($bucket) || str_contains($path, '..'), 404);
        abort_unless($this->files->exists($bucket, $path), 404);

        return $this->files->disk($bucket)->response($this->files->key($bucket, $path), basename($path), [
            'Cache-Control' => 'private, max-age=300',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** POST /api/storage/{bucket} — multipart `file` + `path`. */
    public function upload(Request $request, string $bucket): JsonResponse
    {
        /** @var Profile $profile */
        $profile = $request->user();

        $data = $request->validate([
            'path' => ['required', 'string', 'max:255', 'regex:/^[A-Za-z0-9_\-]+(\/[A-Za-z0-9_.\-]+)+$/', 'not_regex:/\.\./'],
            'file' => ['required', 'file', 'max:10240', 'mimetypes:image/jpeg,image/png,image/webp,image/gif'],
        ]);

        abort_unless(in_array($bucket, self::CLIENT_UPLOAD_BUCKETS, true), 403, 'Uploads to this bucket are not allowed.');
        abort_unless(str_starts_with($data['path'], $profile->id.'/'), 403, 'You can only upload into your own folder.');
        abort_if($bucket === 'product-images' && $profile->role !== 'seller', 403, 'Only sellers can upload product images.');

        $file = $request->file('file');
        $this->files->upload($bucket, $data['path'], (string) file_get_contents($file->getRealPath()), $file->getMimeType() ?: 'application/octet-stream');

        return response()->json([
            'path' => $data['path'],
            'fullPath' => $this->files->key($bucket, $data['path']),
        ]);
    }

    /** GET /api/storage/{bucket}/signed-url?path=...&expires_in=... */
    public function signedUrl(Request $request, string $bucket): JsonResponse
    {
        $data = $request->validate([
            'path' => ['required', 'string', 'max:500', 'not_regex:/\.\./'],
            'expires_in' => ['nullable', 'integer', 'min:10', 'max:86400'],
        ]);

        abort_unless($this->canRead($request->user(), $bucket, $data['path']), 403, 'You do not have access to this file.');

        $url = $this->files->createSignedUrl($bucket, $data['path'], (int) ($data['expires_in'] ?? 300));

        abort_unless($url, 404, 'File not found.');

        return response()->json(['signedUrl' => $url]);
    }

    /**
     * Private-file read rules. Admin portals can read everything; others
     * read their own files, and logistics staff can read the documents of
     * couriers who applied to their company and of their own company.
     */
    private function canRead(Profile $profile, string $bucket, string $path): bool
    {
        if ($this->files->isPublic($bucket) || $profile->managedRoles() !== []) {
            return true;
        }

        $segments = explode('/', $path);

        // documents: profile/{profileId}/... or logistics_company/{companyId}/...
        if ($bucket === 'documents' && count($segments) >= 3) {
            [$scope, $ownerId] = $segments;

            if ($scope === 'profile') {
                if ($ownerId === $profile->id) {
                    return true;
                }

                $companyId = LogisticsCompany::forMember($profile->id)->value('id');

                return $companyId && CourierApplication::where('logistics_company_id', $companyId)
                    ->where('courier_profile_id', $ownerId)
                    ->exists();
            }

            if ($scope === 'logistics_company') {
                return LogisticsCompany::forMember($profile->id)->whereKey($ownerId)->exists();
            }
        }

        // Other private buckets are keyed "profile/{profileId}/..." or "{profileId}/...".
        return in_array($profile->id, array_slice($segments, 0, 2), true);
    }
}
