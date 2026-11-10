<?php

namespace App\Http\Controllers;

use App\Models\Lead;
use App\Models\LeadEmailAttachment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * A file sent with an email, for whoever can see the lead. Pictures and PDFs open in the browser; anything
 * else downloads, so a file a client sent can never run as a page on this site.
 */
class EmailAttachmentController extends Controller
{
    public function show(Request $request, int $id): StreamedResponse
    {
        $user = $request->user();
        abort_unless($user->canWorkLeads(), 403);
        $file = LeadEmailAttachment::with('email:id,lead_id')->findOrFail($id);
        abort_unless($file->email && Lead::query()->visibleTo($user)->whereKey($file->email->lead_id)->exists(), 404);

        $disk = Storage::disk(LeadEmailAttachment::DISK);
        abort_unless($disk->exists($file->path), 404);
        $view = $file->viewable() && ! $request->boolean('download');

        return $disk->response($file->path, $file->name, [
            'Content-Type' => $view ? $file->mime : 'application/octet-stream',
            'Content-Disposition' => HeaderUtils::makeDisposition($view ? 'inline' : 'attachment', $file->name, $this->fallbackName($file->name)),
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, max-age=3600',
        ]);
    }

    /** An ASCII-only name for old browsers; the real name goes in filename* */
    private function fallbackName(string $name): string
    {
        $ascii = preg_replace('/[^\x20-\x7E]|[%\/\\\\]/', '_', $name);

        return $ascii !== '' ? $ascii : 'attachment';
    }
}
