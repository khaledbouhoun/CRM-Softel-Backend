<?php

namespace App\Http\Requests\Api\V1;

/**
 * Body of POST /follow-ups/{id}/complete. With create_activity=true the
 * completion also records the activity (type copied from the follow-up) in the
 * same transaction. The activity_* fields only refine that activity.
 */
class CompleteFollowUpRequest extends CloseFollowUpRequest
{
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'create_activity' => ['sometimes', 'boolean'],
            ...ConvertFollowUpRequest::activityRules(),
        ];
    }
}
