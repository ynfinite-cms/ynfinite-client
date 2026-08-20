<?php

namespace App\Domain\Request\Service;

/**
 * Proof-of-Work validation failure with a machine-readable sub-reason.
 *
 * checkPostProof() used to throw plain \Exception for six different failure
 * modes, all logged as the single reason "pow" - making stale-cache fallout,
 * replay attempts and genuine bots indistinguishable in the security log.
 * The $reason lands in tmp/bot_protection_logs; the message stays internal
 * (the user always receives the same generic error).
 */
class PowException extends \Exception
{
    /** @var string Log reason, e.g. "pow_missing", "pow_replay". */
    public $reason;

    public function __construct(string $reason, string $message)
    {
        parent::__construct($message);
        $this->reason = $reason;
    }
}
