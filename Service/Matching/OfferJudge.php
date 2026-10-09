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
use PriceWatch\Service\Jev\JevException;
use PriceWatch\Service\Jev\OfferQuestions;
use PriceWatch\Service\Jev\OfferState;
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

        foreach ($this->pending($limit) as $offer) {
            if ($spent + self::REQUEST_COST_ESTIMATE > $budgetUsd) {
                $errors[] = 'Budget reached: remaining offers left for the next pass.';
                break;
            }

            ++$counts['seen'];
            $quote = PwPriceQuoteQuery::create()->filterByOfferId($offer->getId())->orderByCollectedAt(Criteria::DESC)->findOne();

            if ($quote === null) {
                continue;
            }

            $verdict = $this->decision->prefilter((string) $quote->getOurPrice(), $quote->getPrice(), $quote->getCurrencyCode(), $quote->getConditionCode());

            if ($verdict === null) {
                if ($dryRun) {
                    continue;
                }

                try {
                    $asked = $this->jev->ask($this->state($offer, $quote), $this->questions->all());
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
                $verdict = $this->decision->decide($asked['answers']);
            }

            if ($dryRun) {
                continue;
            }

            $this->apply($offer, $quote, $verdict);

            match ($verdict->state) {
                MatchVerdict::MATCHED => ++$counts['matched'],
                MatchVerdict::REJECTED => ++$counts['rejected'],
                default => ++$counts['review'],
            };
        }

        $run->setStatus($errors === [] ? 'success' : 'partial')
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

        return $run;
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

    private function apply(PwCompetitorOffer $offer, PwPriceQuote $quote, MatchVerdict $verdict): void
    {
        $offer
            ->setMatchState($verdict->state)
            ->setMatchMethod($verdict->judgedByJev ? 'jev' : 'code')
            ->setMatchReason($verdict->reason)
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
