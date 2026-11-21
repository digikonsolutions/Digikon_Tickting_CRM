<?php

namespace App\Http\Controllers;

use App\Models\Agency;
use App\Models\AgencyContract;
use App\Models\AuditLog;
use App\Models\ContractDocument;
use App\Services\ContractDocumentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Signed contract files. Admins upload and download them; an agency owner may download the current one
 * (AgencyDashboardController). Paths never leave the server, and every admin download is audited.
 */
class ContractDocumentController extends Controller
{
    public function __construct(private ContractDocumentService $documents) {}

    /** The contract new documents attach to: the live one, else the most recent. */
    public static function currentContract(int $agencyId): ?AgencyContract
    {
        return AgencyContract::query()->where('agency_id', $agencyId)
            ->orderByRaw('CASE WHEN live_agency_id IS NULL THEN 1 ELSE 0 END')->orderByDesc('id')->first();
    }

    public function store(Request $request, Agency $agency): JsonResponse
    {
        $request->validate(['file' => ['required', 'file']]);
        $file = $request->file('file');
        $contract = self::currentContract($agency->id);

        if ($contract === null) {
            throw ValidationException::withMessages(['file' => 'Add a contract to this agency before uploading its document.']);
        }
        if ($contract->documents()->count() >= $this->documents->maxPerContract()) {
            throw ValidationException::withMessages(['file' => "A contract can have at most {$this->documents->maxPerContract()} documents."]);
        }
        if ($problem = $this->documents->problem($file)) {
            throw ValidationException::withMessages(['file' => $problem]);
        }

        $document = $this->documents->store($contract, $request->user(), $file);
        $contract->record('document_uploaded', $request->user(), ['name' => $document->original_name]);

        return response()->json(['document' => self::payload($document)], 201);
    }

    public function download(Request $request, Agency $agency, ContractDocument $document): Response
    {
        abort_unless($document->agency_id === $agency->id, 404);
        AuditLog::record($request->user(), 'downloaded_document', $document, $request);

        return self::stream($document, $request->boolean('inline'));
    }

    /** @return array<string, mixed> */
    public static function payload(ContractDocument $document): array
    {
        return [
            'id' => $document->id,
            'name' => $document->original_name,
            'mime_type' => $document->mime,
            'size_bytes' => $document->size,
            'created_at' => $document->created_at?->toISOString(),
        ];
    }

    public static function stream(ContractDocument $document, bool $inline = false): Response
    {
        $disk = Storage::disk($document->disk);
        abort_unless($disk->exists($document->path), 404);
        $inline = $inline && in_array($document->mime, config('attachments.inline_mimes', []), true);

        return $disk->response($document->path, $document->original_name, [
            'Content-Type' => $document->mime,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
            'Content-Security-Policy' => "default-src 'none'; img-src 'self' data:; sandbox",
        ], $inline ? 'inline' : 'attachment');
    }
}