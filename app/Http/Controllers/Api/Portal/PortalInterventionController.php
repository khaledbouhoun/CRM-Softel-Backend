<?php

namespace App\Http\Controllers\Api\Portal;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\PortalAttachmentRequest;
use App\Http\Requests\Api\V1\PortalInterventionRequest;
use App\Http\Requests\Api\V1\PortalMessageRequest;
use App\Http\Requests\Api\V1\PortalTransitionRequest;
use App\Http\Resources\Api\V1\PortalPresenter;
use App\Http\Responses\ApiResponse;
use App\Models\Intervention;
use App\Models\InterventionAttachment;
use App\Models\InterventionStatus;
use App\Models\InterventionStatusTransition;
use App\Models\InterventionType;
use App\Models\PortalAccount;
use App\Services\AttachmentResponder;
use App\Services\InterventionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Interventions the signed-in customer's company raised itself (source = customer). */
class PortalInterventionController extends Controller
{
    private const WITH = ['type', 'status', 'product', 'messages.status', 'messages.fromStatus', 'messages.account', 'messages.attachments', 'attachments'];

    public function __construct(private readonly InterventionService $interventions) {}

    private function account(Request $request): PortalAccount
    {
        return $request->attributes->get('portal_account');
    }

    private function own(Request $request, int $id): Intervention
    {
        return Intervention::query()->with(self::WITH)->where('source', 'customer')
            ->where('prospect_id', $this->account($request)->prospect_id ?? 0)->findOrFail($id);
    }

    public function options(): JsonResponse
    {
        $types = InterventionType::query()->where('is_active', true)->whereIn('source', ['customer', 'both'])->orderBy('sort_order')->get()
            ->map(fn ($t) => ['id' => $t->id, 'code' => $t->code, 'name' => $t->name, 'color' => $t->color])->all();

        return ApiResponse::success(['types' => $types], 'Options retrieved successfully.');
    }

    public function index(Request $request): JsonResponse
    {
        $items = Intervention::query()->with(['type', 'status', 'product'])->where('source', 'customer')
            ->where('prospect_id', $this->account($request)->prospect_id ?? 0)->latest('id')->get();

        return ApiResponse::success($items->map(fn ($i) => PortalPresenter::intervention($i))->all(), 'Requests retrieved successfully.', 200, ['total' => $items->count()]);
    }

    public function store(PortalInterventionRequest $request): JsonResponse
    {
        $created = $this->interventions->createFromCustomer($this->account($request), $request->validated());

        return ApiResponse::created(PortalPresenter::intervention($this->own($request, $created->id), true), 'Your request was sent.');
    }

    public function show(Request $request, int $id): JsonResponse
    {
        return ApiResponse::success(PortalPresenter::intervention($this->own($request, $id), true), 'Request retrieved successfully.');
    }

    public function message(PortalMessageRequest $request, int $id): JsonResponse
    {
        $intervention = $this->own($request, $id);
        $this->interventions->addMessage($intervention, 'customer', $this->account($request)->name, 'customer', $request->validated('body'), [], $this->account($request)->id);

        return ApiResponse::success(PortalPresenter::intervention($this->own($request, $id), true), 'Message sent.');
    }

    /** Customer-allowed transitions only (e.g. reopening a delivered fix). */
    public function transition(PortalTransitionRequest $request, int $id): JsonResponse
    {
        $intervention = $this->own($request, $id);
        $this->interventions->transition($intervention, (int) $request->validated('status_id'), 'customer', $this->account($request)->name, $request->validated('message'), $this->account($request)->id);

        return ApiResponse::success(PortalPresenter::intervention($this->own($request, $id), true), 'Request updated.');
    }

    public function attach(PortalAttachmentRequest $request, int $id): JsonResponse
    {
        $intervention = $this->own($request, $id);
        $this->interventions->assertAcceptsReplies($intervention, 'customer');
        $this->interventions->storeAttachment($intervention, $request->file('file'), 'customer', 'customer', $this->account($request)->name);

        return ApiResponse::created(PortalPresenter::intervention($this->own($request, $id), true), 'File uploaded.');
    }

    public function download(Request $request, int $id, int $attachmentId): StreamedResponse
    {
        $intervention = $this->own($request, $id);
        $attachment = $intervention->attachments->where('visibility', 'customer')->firstWhere('id', $attachmentId);
        abort_if($attachment === null, 404);

        return AttachmentResponder::download($attachment, $this->interventions->disk());
    }

    /** Inline preview for images and PDFs; other files are downloaded instead. */
    public function preview(Request $request, int $id, int $attachmentId): StreamedResponse
    {
        $intervention = $this->own($request, $id);
        $attachment = $intervention->attachments->where('visibility', 'customer')->firstWhere('id', $attachmentId);
        abort_if($attachment === null, 404);

        return AttachmentResponder::preview($attachment, $this->interventions->disk());
    }

    /** Statuses a customer may move to from the current one (drives the "reopen" button). */
    public function allowedTransitions(Request $request, int $id): JsonResponse
    {
        $intervention = $this->own($request, $id);
        $ids = InterventionStatusTransition::query()->where('is_active', true)->where('customer_allowed', true)
            ->where('from_status_id', $intervention->intervention_status_id)->pluck('to_status_id');
        $items = InterventionStatus::query()->whereIn('id', $ids)->where('is_active', true)->get()
            ->map(fn ($s) => ['id' => $s->id, 'label' => $s->customer_label])->all();

        return ApiResponse::success($items, 'Transitions retrieved successfully.');
    }
}
