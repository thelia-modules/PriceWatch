<?php

declare(strict_types=1);

namespace PriceWatch\Service\Matching;

use PriceWatch\Model\PwCollectRun;
use PriceWatch\Model\PwCompetitorOffer;
use PriceWatch\Model\PwCompetitorOfferQuery;
use PriceWatch\Model\PwCompetitorQuery;
use PriceWatch\Model\PwPriceQuote;
use PriceWatch\Model\PwPriceQuoteQuery;
use PriceWatch\Model\PwProductIdentityQuery;
use PriceWatch\Service\Competitor\CompetitorStatus;
use PriceWatch\Service\Jev\CachedJev;
use PriceWatch\Service\Jev\JevAnswer;
use PriceWatch\Service\Jev\JevException;
use PriceWatch\Service\Jev\JevSpendRefusedException;
use PriceWatch\Service\Jev\OfferQuestions;
use PriceWatch\Service\Jev\OfferState;
use PriceWatch\Service\Run\PassStep;
use Propel\Runtime\ActiveQuery\Criteria;
use Thelia\Model\ProductI18nQuery;

/**
 * Judges candidate offers of whitelisted competitors.
 *
 * Code first (prefilter), Jev only for what code cannot see, and a human for
 * whatever Jev leaves in the middle band. A failed Jev call leaves the offer
 * as it was, to be judged on the next pass: not judged is never judged good.
 */
final readonly class OfferJudge
{
    public const PASS = 'judge';
    private const LOCALE = 'fr_FR';

    /** Upper estimate of one request, used to stop before overshooting the budget. */
    private const REQUEST_COST_ESTIMATE = 0.0001;

    /**
     * Verdicts about the article, whatever our size: given once for the sizes
     * of a product read on the same listing at the same price. A verdict that
     * involves the size (a match, another size) is never shared.
     */
    private const PRODUCT_VERDICTS = ['PRODUCT_MISMATCH', 'PRODUCT_UNLIKELY', 'PRODUCT_UNCLEAR'];

    public function __construct(
        private CachedJev $jev,
        private OfferQuestions $questions,
        private OfferState $offerState,
        private MatchDecision $decision,
    ) {
    }

    public function judgePending(int $limit, float $budgetUsd, bool $dryRun = false): PwCollectRun
    {
        $run = (new PwCollectRun())
            ->setPassName(self::PASS)
            ->setStatus('started')
            ->setStartedAt(new \DateTime())
            ->setDryRun($dryRun ? 1 : 0);
        $run->save();

        $spent = 0.0;
        $errors = [];
        $counts = ['matched' => 0, 'review' => 0, 'rejected' => 0, 'requests' => 0, 'hits' => 0, 'tokens' => 0, 'seen' => 0];

        try {
            $this->judge($run, $limit, $budgetUsd, $dryRun, $spent, $errors, $counts);
            $status = $errors === [] ? 'success' : 'partial';
        } catch (\Throwable $error) {
            // Never a pass left "started" because of an exception: its row
            // says it failed, with what was already spent.
            $errors[] = \sprintf('Stopped by an error: %s (%s)', $error->getMessage(), $error::class);
            $this->close($run, 'failed', $spent, $errors, $counts);

            throw $error;
        }

        $this->close($run, $status, $spent, $errors, $counts);

        return $run;
    }

    /**
     * @param list<string>       $errors
     * @param array<string, int> $counts
     */
    private function judge(PwCollectRun $run, int $limit, float $budgetUsd, bool $dryRun, float &$spent, array &$errors, array &$counts): void
    {
        $pending = $this->pending($limit);
        PassStep::Judging->record($run, 0, \count($pending));
        /** @var array<string, array{MatchVerdict, array<string, JevAnswer>}> $productVerdicts evidence => verdict and answers */
        $productVerdicts = [];

        foreach ($pending as $offer) {
            if ($spent + self::REQUEST_COST_ESTIMATE > $budgetUsd) {
                $errors[] = 'Budget reached: remaining offers left for the next pass.';
                break;
            }

            PassStep::Judging->record($run, $counts['seen'], \count($pending));
            ++$counts['seen'];
            $quote = PwPriceQuoteQuery::create()->filterByOfferId($offer->getId())->orderByCollectedAt(Criteria::DESC)->findOne();

            if ($quote === null) {
                continue;
            }

            $verdict = $this->decision->prefilter((string) $quote->getOurPrice(), $quote->getPrice(), $quote->getCurrencyCode(), $quote->getConditionCode());
            $answers = [];

            if ($verdict === null) {
                if ($dryRun) {
                    continue;
                }

                $evidence = $this->evidence($offer, $quote);

                if (isset($productVerdicts[$evidence])) {
                    // Seen 2026-10-12: six sizes, one listing naming none,
                    // six requests for the same "product unclear".
                    [$verdict, $answers] = $productVerdicts[$evidence];
                    $counts['hits'] += \count($answers);
                } else {
                    try {
                        $asked = $this->jev->ask($this->state($offer, $quote), $this->questions->all());
                    } catch (JevSpendRefusedException $refusal) {
                        // The budget or the switch of the usage: every next offer would be refused the same way.
                        $errors[] = 'Stopped: '.$refusal->getMessage().' Remaining offers left for the next pass.';
                        break;
                    } catch (JevException $error) {
                        if (\count($errors) < 20) {
                            $errors[] = 'offer '.$offer->getId().': '.$error->getMessage();
                        }

                        continue;
                    }

                    $spent += $asked['costUsd'];
                    $counts['requests'] += $asked['requests'];
                    $counts['hits'] += $asked['cacheHits'];
                    $counts['tokens'] += $asked['inputTokens'];
                    $answers = $asked['answers'];
                    $verdict = $this->decision->decide($answers);

                    if (\in_array($verdict->reason, self::PRODUCT_VERDICTS, true)) {
                        $productVerdicts[$evidence] = [$verdict, $answers];
                    }
                }

                // A price far from ours makes Jev's word not enough, and so does a GTIN that
                // cannot vouch for it.
                $verdict = $this->decision->withGap($verdict, (string) $quote->getOurPrice(), $quote->getPrice());
                $verdict = $this->decision->withGtinCheck($verdict, $this->ourGtin($offer), (int) $offer->getRemoteGtinValid() === 1 ? $offer->getRemoteGtin() : null);
            }

            if ($dryRun) {
                continue;
            }

            $this->apply($offer, $quote, $verdict, $answers);

            match ($verdict->state) {
                MatchVerdict::MATCHED => ++$counts['matched'],
                MatchVerdict::REJECTED => ++$counts['rejected'],
                default => ++$counts['review'],
            };
        }

        PassStep::Judging->record($run, \count($pending), \count($pending));
    }

    /**
     * @param list<string>       $errors
     * @param array<string, int> $counts
     */
    private function close(PwCollectRun $run, string $status, float $spent, array $errors, array $counts): void
    {
        $run->setStatus($status)
            ->setFinishedAt(new \DateTime())
            ->setOffersSeen($counts['seen'])
            ->setOffersMatched($counts['matched'])
            ->setOffersReview($counts['review'])
            ->setOffersRejected($counts['rejected'])
            ->setJevRequests($counts['requests'])
            ->setJevCacheHits($counts['hits'])
            ->setJevInputTokens($counts['tokens'])
            ->setJevCostUsd(number_format($spent, 8, '.', ''))
            ->setErrorsJson($errors === [] ? null : json_encode($errors, \JSON_UNESCAPED_UNICODE))
            ->save();
    }

    /**
     * Only offers of whitelisted competitors: the blacklist is never compared,
     * and judging an offer of an undecided competitor would be paying for a
     * comparison nobody asked for.
     *
     * @return list<PwCompetitorOffer>
     */
    private function pending(int $limit): array
    {
        $watched = PwCompetitorQuery::create()
            ->filterByStatus(CompetitorStatus::Watched->value)
            ->select(['Id'])
            ->find()
            ->getData();

        if ($watched === []) {
            return [];
        }

        return PwCompetitorOfferQuery::create()
            ->filterByCompetitorId($watched, Criteria::IN)
            ->filterByMatchState('candidate')
            ->filterByActive(1)
            ->orderById()
            ->limit($limit)
            ->find()
            ->getData();
    }

    private function ourGtin(PwCompetitorOffer $offer): ?string
    {
        $identity = PwProductIdentityQuery::create()->filterByProductSaleElementsId($offer->getProductSaleElementsId())->findOne();

        // A GTIN shared by several variants says nothing about one of them.
        return $identity !== null && (int) $identity->getGtinValid() === 1 && (int) $identity->getGtinShared() === 0 ? $identity->getGtin() : null;
    }

    /**
     * What Jev sees of an offer, but our size: same competitor, same product
     * of ours, same listing, same prices.
     */
    private function evidence(PwCompetitorOffer $offer, PwPriceQuote $quote): string
    {
        $productId = PwProductIdentityQuery::create()->filterByProductSaleElementsId($offer->getProductSaleElementsId())->findOne()?->getProductId();

        return sha1((string) json_encode([
            $offer->getCompetitorId(),
            $productId ?? 'pse:'.$offer->getProductSaleElementsId(),
            $offer->getRemoteTitle(),
            $offer->getRemoteVariantLabel(),
            $quote->getPrice(),
            $quote->getListPrice(),
            $quote->getShippingPrice(),
            $quote->getCurrencyCode(),
            $quote->getAvailability(),
            $quote->getConditionCode(),
            $quote->getOurPrice(),
        ]));
    }

    /**
     * @return array<string, mixed>
     */
    private function state(PwCompetitorOffer $offer, PwPriceQuote $quote): array
    {
        $identity = PwProductIdentityQuery::create()->filterByProductSaleElementsId($offer->getProductSaleElementsId())->findOne();
        $title = $identity === null ? null : ProductI18nQuery::create()
            ->filterById($identity->getProductId())
            ->filterByLocale(self::LOCALE)
            ->findOne()?->getTitle();
        $axes = json_decode((string) $identity?->getVariantAxisJson(), true);
        $variant = \is_array($axes) ? implode(', ', array_map(static fn ($k, $v): string => $k.': '.$v, array_keys($axes), $axes)) : null;
        $competitor = PwCompetitorQuery::create()->findPk($offer->getCompetitorId());

        return $this->offerState->build(
            [
                'title' => (string) $title,
                'brand' => $identity?->getBrandLabel(),
                'size' => $identity?->getSizeLabel(),
                'variant' => $variant,
                'gtin' => $identity?->getGtin(),
                'price_incl_tax' => $quote->getOurPrice(),
            ],
            [
                'title' => (string) $offer->getRemoteTitle(),
                'seller' => $competitor?->getDisplayName() ?? $competitor?->getDomain(),
                'variant' => $offer->getRemoteVariantLabel(),
                'description' => null,
                'price' => $quote->getPrice(),
                'list_price' => $quote->getListPrice(),
                'shipping' => $quote->getShippingPrice(),
                'currency' => $quote->getCurrencyCode(),
                'availability' => $quote->getAvailability(),
                'condition' => $quote->getConditionCode(),
                'annotation' => null,
            ],
        );
    }

    /**
     * @param array<string, JevAnswer> $answers empty when code decided alone
     */
    private function apply(PwCompetitorOffer $offer, PwPriceQuote $quote, MatchVerdict $verdict, array $answers): void
    {
        // Kept on the offer as proof for the screen: the judgment cache is
        // keyed by state, so it cannot be found again from the offer alone.
        $judgment = array_map(static fn (JevAnswer $answer): array => [
            'band' => $answer->band,
            'value' => $answer->value,
            'confidence' => $answer->confidence,
            'probabilities' => $answer->probabilities,
        ], $answers);

        $offer
            ->setMatchState($verdict->state)
            ->setMatchMethod($verdict->judgedByJev ? 'jev' : 'code')
            ->setMatchReason($verdict->reason)
            ->setJudgmentJson($judgment === [] ? null : json_encode($judgment, \JSON_UNESCAPED_UNICODE))
            ->setJudgedFingerprint($offer->getIdentityFingerprint())
            ->setLastComparable($verdict->comparable ? 1 : 0)
            ->setExclusionReason($verdict->exclusionReason)
            ->save();

        $quote
            ->setComparable($verdict->comparable ? 1 : 0)
            ->setExclusionReason($verdict->exclusionReason)
            ->save();
    }
}
