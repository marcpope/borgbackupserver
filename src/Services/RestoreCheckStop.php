<?php

namespace BBS\Services;

/** Ends a run early with a definite outcome. */
class RestoreCheckStop extends \RuntimeException
{
    public function __construct(private array $outcomeData)
    {
        parent::__construct((string) ($outcomeData['detail'] ?? $outcomeData['reason'] ?? 'stopped'));
    }

    public static function failed(string $reason, string $detail, array $extra = []): self
    {
        return new self(['result' => 'failed', 'reason' => $reason, 'detail' => $detail, 'extra' => $extra]);
    }

    public function outcome(): array
    {
        return $this->outcomeData;
    }
}
