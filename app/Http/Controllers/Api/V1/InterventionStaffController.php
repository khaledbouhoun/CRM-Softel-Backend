<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\IndexInterventionRequest;
use App\Http\Requests\Api\V1\InterventionMessageRequest;
use App\Http\Requests\Api\V1\InterventionTransitionRequest;
use App\Http\Requests\Api\V1\PublishInterventionRequest;
use App\Http\Requests\Api\V1\ReopenInterventionRequest;
use App\Http\Requests\Api\V1\StoreInterventionRequest;
use App\Http\Requests\Api\V1\UpdateInterventionRequest;
use App\Http\Resources\Api\V1\InterventionResource;
use App\Http\Responses\ApiResponse;
use App\Models\Intervention;
use App\Models\InterventionStatusTransition;
use App\Services\AttachmentResponder;
use App\Services\InterventionService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** After-sales triage for employees (all employees have the same access). */
class InterventionStaffController extends Controller
{
    private const LIST_WITH = ['type', 'status', 'priority', 'prospect', 'product'];

    private const DETAIL_WITH = [...self::LIST_WITH, 'customers', 'messages.attachments', 'messages.status', 'messages.fromStatus', 'messages.account', 'attachments'];

    public function __construct(private readonly InterventionService $interventions) {}

    private function detail(Intervention $i): InterventionResource
    {
        return new InterventionResource($i->refresh()->load(self::DETAIL_WITH));
    }

    public function index(IndexInterventionRequest $request): JsonResponse
    {
        $v = $request->validated();

        $paginator = Intervention::query()->with(self::LIST_WITH)
            ->when($v['status_id'] ?? null, fn ($q, $id) => $q->where('intervention_status_id', $id))
            ->when($v['priority_id'] ?? null, fn ($q, $id) => $q->where('intervention_priority_id', $id))
            ->when($v['type_id'] ?? null, fn ($q, $id) => $q->where('intervention_type_id', $id))
            ->when($v['source'] ?? null, fn ($q, $s) => $q->where('source', $s))
            ->when($v['assigned_employee'] ?? null, fn ($q, $e) => $q->where('assigned_employee', $e))
            ->when($v['prospect_id'] ?? null, fn ($q, $id) => $q->where('prospect_id', $id))
            ->when(array_key_exists('open', $v), fn ($q) => $q->whereHas('status', fn ($s) => $s->where('is_closed', ! $v['open'])))
            ->orderByRaw('intervention_priority_id is null')
            ->orderBy('intervention_priority_id')->orderByDesc('id')
            ->paginate((int) ($v['per_page'] ?? config('crm.pagination.default_per_page')));

        return ApiResponse::paginated($paginator, InterventionResource::class, 'Interventions retrieved successfully.');
    }

    public function show(Intervention $intervention): JsonResponse
    {
        return ApiResponse::success($this->detail($intervention), 'Intervention retrieved successfully.');
    }

    public function store(StoreInterventionRequest $request): JsonResponse
    {
        $created = $this->interventions->createFromEmployee($request->validated(), $this->actor($request));

        return ApiResponse::created($this->detail($created), 'Intervention created.');
    }

    public function update(UpdateInterventionRequest $request, Intervention $intervention): JsonResponse
    {
        $intervention->update($request->validated());

        return ApiResponse::success($this->detail($intervention), 'Intervention updated.');
    }

    public function transition(InterventionTransitionRequest $request, Intervention $intervention): JsonResponse
    {
        $this->interventions->transition($intervention, (int) $request->validated('status_id'), 'employee', $this->actor($request), $request->validated('customer_message'));

        return ApiResponse::success($this->detail($intervention), 'Status updated.');
    }

    /** Statuses reachable from the current one (drives the status picker). */
    public function allowedTransitions(Intervention $intervention): JsonResponse
    {
        $rows = InterventionStatusTransition::query()->where('is_active', true)
            ->where('from_status_id', $intervention->intervention_status_id)->get(['to_status_id', 'customer_allowed']);

        return ApiResponse::success($rows->map(fn ($r) => ['status_id' => $r->to_status_id, 'customer_allowed' => $r->customer_allowed])->all(), 'Transitions retrieved successfully.');
    }

    public function message(InterventionMessageRequest $request, Intervention $intervention): JsonResponse
    {
        $this->interventions->addMessage(
            $intervention, 'employee', $this->actor($request), $request->validated('visibility'),
            (string) $request->validated('body', ''), $request->file('files', []), null, $request->boolean('is_hotfix'),
        );

        return ApiResponse::created($this->detail($intervention), 'Message added.');
    }

    /** Employees reopen a closed intervention: replies are enabled again for both sides. */
    public function reopen(ReopenInterventionRequest $request, Intervention $intervention): JsonResponse
    {
        $this->interventions->reopen($intervention, $this->actor($request), $request->validated('status_id'), $request->validated('note'));

        return ApiResponse::success($this->detail($intervention), 'Intervention reopened.');
    }

    /** Secure download (attachment disposition) of any file on the intervention, internal ones included. */
    public function download(Intervention $intervention, int $attachmentId): StreamedResponse
    {
        return AttachmentResponder::download($intervention->attachments()->findOrFail($attachmentId), $this->interventions->disk());
    }

    /** Inline preview for images and PDFs only; anything else is downloaded. */
    public function preview(Intervention $intervention, int $attachmentId): StreamedResponse
    {
        return AttachmentResponder::preview($intervention->attachments()->findOrFail($attachmentId), $this->interventions->disk());
    }

    public function publish(PublishInterventionRequest $request, Intervention $intervention): JsonResponse
    {
        $this->interventions->publish($intervention, $request->validated('customer_resolution'));

        return ApiResponse::success($this->detail($intervention), 'Published to the affected customers.');
    }

    public function unpublish(Intervention $intervention): JsonResponse
    {
        $this->interventions->unpublish($intervention);

        return ApiResponse::success($this->detail($intervention), 'Unpublished.');
    }
}
