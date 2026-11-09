<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A request that is well-formed but breaks a CRM business rule
 * (invalid stage transition, modifying a closed follow-up, ...).
 * Rendered by ApiExceptionRenderer with its status and error code.
 */
class BusinessRuleException extends RuntimeException
{
    /**
     * @param  array<string, list<string>>  $errors  optional field-keyed details
     */
    public function __construct(
        string $message,
        public readonly string $errorCode = 'BUSINESS_RULE_VIOLATION',
        public readonly array $errors = [],
        public readonly int $status = 422,
    ) {
        parent::__construct($message);
    }

    public static function invalidStageTransition(string $message): self
    {
        return new self($message, 'INVALID_STAGE_TRANSITION', ['sales_stage_id' => [$message]]);
    }

    public static function invalidFollowUpState(string $message): self
    {
        return new self($message, 'INVALID_FOLLOW_UP_STATE');
    }

    public static function activityAlreadyLinked(string $message): self
    {
        return new self($message, 'ACTIVITY_ALREADY_LINKED', ['confirm_relink' => [$message]], 409);
    }

    public static function activityLinkLocked(string $message): self
    {
        return new self($message, 'ACTIVITY_LINK_LOCKED');
    }

    public static function resourceInUse(string $message, array $references = []): self
    {
        $errors = $references === [] ? [] : ['references' => $references];

        return new self($message, 'RESOURCE_IN_USE', $errors, 409);
    }
}
