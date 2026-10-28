<?php

declare(strict_types=1);

namespace PriceWatch\Tests\Doubles;

use Thelia\Domain\Ai\AiFinishReason;
use Thelia\Domain\Ai\AiGatewayInterface;
use Thelia\Domain\Ai\AiRequest;
use Thelia\Domain\Ai\AiResponse;
use Thelia\Domain\Ai\AiTokenUsage;
use Thelia\Domain\Ai\Exception\AiExceptionInterface;

/**
 * The gateway of the core as a test sees it: records each request and answers
 * with a canned text, or refuses.
 */
final class FakeAiGateway implements AiGatewayInterface
{
    /** @var list<AiRequest> */
    public array $requests = [];

    public function __construct(public string $text = '{}', public ?AiExceptionInterface $refusal = null)
    {
    }

    public function complete(AiRequest $request): AiResponse
    {
        $this->requests[] = $request;

        if ($this->refusal !== null) {
            throw $this->refusal;
        }

        return new AiResponse(
            text: $this->text,
            data: json_decode($this->text, true) ?: null,
            toolCalls: [],
            tokenUsage: new AiTokenUsage(1234, 567),
            cost: '0.00421000',
            model: 'vendor/model',
            connection: 'test-connection',
            finishReason: AiFinishReason::Completed,
            guardrailVerdicts: [],
            logId: 1,
        );
    }

    public function stream(AiRequest $request): iterable
    {
        throw new \LogicException('PriceWatch does not stream.');
    }
}
